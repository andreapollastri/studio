<?php

namespace App\Databases\Transfers;

use App\Databases\Connector;
use App\Databases\DatabaseException;
use App\Databases\Dialects\Dialect;
use App\Databases\Driver;
use App\Databases\Links\Link;
use App\Databases\Links\PdoLink;
use App\Databases\Links\SqliteShellLink;
use App\Databases\Redis\Keyspace;
use App\Databases\Statements;
use App\Databases\Values;
use App\Models\DatabaseConnection;
use App\Models\DatabaseTransfer;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;
use ZipArchive;

/**
 * Imports and exports of a connection, on the databases queue:
 *
 *   - a SQL dump with the database's own tool when it is on this server (mariadb-dump or
 *     mysqldump, pg_dump, sqlite3's .dump on the file's host), else written by Studio
 *     (structure from the catalog, data in INSERTs of 500 rows); gzip when asked;
 *   - tables or a query as CSV or JSON (several tables: a zip, a file each);
 *   - a SQL file run with mariadb/mysql, psql or sqlite3, else statement by statement;
 *   - a CSV or JSON file into a table, its columns matched by name;
 *   - Redis and Valkey keys to and from JSON, with their types and TTLs.
 *
 * Every secret goes to the tools through a file only Studio reads or their environment,
 * never their command line.
 */
class Transfers
{
    public function __construct(protected Connector $connector) {}

    public function run(DatabaseTransfer $transfer): void
    {
        $connection = $transfer->connection;
        $user = $transfer->user ?? throw new DatabaseException(__('The person who asked for it is gone.'));

        if ($transfer->kind === 'import' && ! $connection->canWrite($user)) {
            throw new DatabaseException(__('You may only read this connection.'));
        }

        if ($connection->driver->isRedis()) {
            $transfer->kind === 'export' ? $this->exportRedis($transfer, $connection, $user) : $this->importRedis($transfer, $connection, $user);

            return;
        }

        if ($transfer->kind === 'export') {
            $transfer->format === 'sql' ? $this->exportSql($transfer, $connection, $user) : $this->exportRows($transfer, $connection, $user);
        } else {
            $transfer->format === 'sql' ? $this->importSql($transfer, $connection, $user) : $this->importRows($transfer, $connection, $user);
        }
    }

    /* ------------------------------------------------------------------ exports */

    private function exportSql(DatabaseTransfer $transfer, DatabaseConnection $connection, User $user): void
    {
        $scope = $transfer->scope;
        $database = $scope['database'] ?? $connection->database;
        $tables = array_values(array_map('strval', (array) ($scope['tables'] ?? [])));
        $file = $this->newFile($transfer, Str::slug((string) ($database ?: $connection->name)).'-'.now()->format('Y-m-d_His').'.sql');
        $engine = (string) ($scope['engine'] ?? 'auto');
        $done = false;

        if ($engine !== 'studio') {
            try {
                $done = $this->nativeDump($transfer, $connection, $user, $database, $tables, $file);
            } catch (Throwable $e) {
                if ($engine === 'native') {
                    throw $e;
                }
                $transfer->log(__('The native tool failed (:reason): Studio writes the dump itself.', ['reason' => Str::limit($e->getMessage(), 300)]));
            }
        }

        if (! $done) {
            $transfer->log(__('Writing the dump with Studio.'));
            $this->studioDump($transfer, $connection, $user, $database, $tables, $file);
        }

        $this->finishFile($transfer, $file, (bool) ($scope['gzip'] ?? false));
    }

    /** @param  list<string>  $tables */
    private function nativeDump(DatabaseTransfer $transfer, DatabaseConnection $connection, User $user, ?string $database, array $tables, string $file): bool
    {
        $scope = $transfer->scope;
        $structure = (bool) ($scope['structure'] ?? true);
        $data = (bool) ($scope['data'] ?? true);
        $drop = (bool) ($scope['drop'] ?? true);

        if ($connection->driver === Driver::Sqlite) {
            $link = $this->connector->sql($connection, $user);

            if ($link instanceof SqliteShellLink && $structure && $data) {
                $transfer->log(__('Dumping with sqlite3 on :host.', ['host' => $connection->sshConnection?->host]));
                $link->dump($file, $tables);

                return true;
            }

            if ($link instanceof PdoLink && $structure && $data && ($binary = $this->binary('sqlite3')) !== null) {
                $transfer->log(__('Dumping with :tool.', ['tool' => basename($binary)]));
                $result = Process::timeout($this->timeout())->env(['STUDIO_EXEC_OUT' => $file])
                    ->run(['sh', '-c', '"$@" > "$STUDIO_EXEC_OUT"', 'sh', $binary, '-readonly', (string) $connection->database, '.dump'.($tables === [] ? '' : ' '.implode(' ', $tables))]);

                return $result->successful() ? true : throw new DatabaseException(trim($result->errorOutput()));
            }

            return false;
        }

        if (blank($database)) {
            throw new DatabaseException(__('Choose a database to export.'));
        }

        $family = $connection->driver->family();
        $binary = $this->binary($family === 'mysql' ? 'mysqldump' : 'pg_dump');

        if ($binary === null) {
            return false;
        }

        $endpoint = $this->connector->endpoint($connection, $user);
        $bench = $this->bench();

        try {
            if ($family === 'mysql') {
                $command = [$binary, '--defaults-extra-file='.$this->mysqlDefaults($bench, $connection, $endpoint), '--single-transaction', '--quick', '--no-tablespaces', '--routines', '--triggers', '--hex-blob', '--default-character-set=utf8mb4',
                    ...($drop ? ['--add-drop-table'] : ['--skip-add-drop-table']),
                    ...($structure ? [] : ['--no-create-info', '--skip-triggers', '--skip-routines']),
                    ...($data ? [] : ['--no-data']),
                    (string) $database, ...$tables];
                $env = [];
            } else {
                $schema = (string) ($transfer->scope['schema'] ?? 'public');
                $command = [$binary, '--no-owner', '--no-acl', '--host='.($endpoint['socket'] ?? $endpoint['host']), '--port='.$endpoint['port'], '--username='.(string) $connection->username, '--dbname='.$database,
                    ...($drop ? ['--clean', '--if-exists'] : []),
                    ...($structure ? [] : ['--data-only']),
                    ...($data ? [] : ['--schema-only']),
                    ...($tables === [] ? [] : array_map(fn (string $t) => '--table='.self::pgName($schema).'.'.self::pgName($t), $tables)),
                    '--file='.$file];
                $env = ['PGPASSWORD' => (string) $connection->password, 'PGCONNECT_TIMEOUT' => '15', 'PGSSLMODE' => isset($endpoint['socket']) ? 'disable' : ($connection->tls ? 'require' : 'prefer')];
            }

            $transfer->log(__('Dumping with :tool.', ['tool' => basename($binary)]));
            $process = Process::timeout($this->timeout())->env($env);
            $result = $family === 'mysql'
                ? $process->env([...$env, 'STUDIO_EXEC_OUT' => $file])->run(['sh', '-c', '"$@" > "$STUDIO_EXEC_OUT"', 'sh', ...$command])
                : $process->run($command);

            if (! $result->successful()) {
                throw new DatabaseException(Str::limit(trim($result->errorOutput()) ?: __('exit code :code', ['code' => $result->exitCode()]), 600));
            }

            if (trim($result->errorOutput()) !== '') {
                $transfer->log(Str::limit(trim($result->errorOutput()), 2000));
            }
        } finally {
            File::deleteDirectory($bench);
        }

        return true;
    }

    /** @param  list<string>  $tables */
    private function studioDump(DatabaseTransfer $transfer, DatabaseConnection $connection, User $user, ?string $database, array $tables, string $file): void
    {
        $scope = $transfer->scope;
        $link = $this->connector->sql($connection, $user, $connection->driver === Driver::Pgsql ? $database : null);
        $dialect = Dialect::for($link, $database, $scope['schema'] ?? null);
        $family = $connection->driver->family();
        $objects = collect($dialect->objects());
        if ($tables !== []) {
            $objects = $objects->whereIn('name', $tables);
        }
        $out = fopen($file, 'w') ?: throw new DatabaseException(__('Could not write the export.'));
        $rows = 0;

        try {
            fwrite($out, '-- '.__('Dump of :database made by Studio on :date', ['database' => $database ?? $connection->name, 'date' => now()->toDateTimeString()])."\n\n");
            fwrite($out, match ($family) {
                'mysql' => "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n",
                'pgsql' => "SET client_encoding = 'UTF8';\nSET standard_conforming_strings = on;\n\n",
                default => "PRAGMA foreign_keys = OFF;\nBEGIN TRANSACTION;\n\n",
            });

            foreach ($objects->sortBy(fn (array $o) => $o['type'] === 'view' ? 1 : 0) as $object) {
                $name = $object['name'];

                if ($scope['structure'] ?? true) {
                    if ($scope['drop'] ?? true) {
                        fwrite($out, $dialect->dropTable($name, $object['type'], true)."\n");
                    }
                    fwrite($out, $dialect->ddl($name, $object['type'])."\n\n");
                }

                if ($object['type'] === 'view' || ! ($scope['data'] ?? true)) {
                    continue;
                }

                $count = 0;
                $columns = [];
                $batch = [];

                foreach ($link->cursor('SELECT * FROM '.$dialect->qualified($name)) as $i => $row) {
                    if ($i === 0) {
                        $columns = $row;

                        continue;
                    }
                    $batch[] = $row;
                    if (count($batch) === 500) {
                        fwrite($out, $dialect->insertStatement($name, $columns, $batch));
                        $count += 500;
                        $batch = [];
                    }
                }

                if ($batch !== []) {
                    fwrite($out, $dialect->insertStatement($name, $columns, $batch));
                    $count += count($batch);
                }

                $rows += $count;

                // identities and serials go on after the rows put back
                if ($family === 'pgsql' && $count > 0) {
                    foreach ($dialect->columns($name) as $column) {
                        if (str_starts_with((string) $column['extra'], 'identity') || str_starts_with((string) $column['default'], 'nextval(')) {
                            $table = $dialect->qualified($name);
                            fwrite($out, "SELECT setval(pg_get_serial_sequence('".str_replace("'", "''", $table)."', ".$link->quote($column['name']).'), COALESCE(MAX('.$dialect->ident($column['name'])."), 1)) FROM {$table};\n");
                        }
                    }
                }

                fwrite($out, "\n");
                $transfer->log(trans_choice(':table: :count row|:table: :count rows', $count, ['table' => $name, 'count' => number_format($count)]));
            }

            fwrite($out, match ($family) {
                'mysql' => "SET FOREIGN_KEY_CHECKS = 1;\n",
                'pgsql' => '',
                default => "COMMIT;\n",
            });
        } finally {
            fclose($out);
            $link->close();
        }

        $transfer->forceFill(['rows' => $rows])->save();
    }

    private function exportRows(DatabaseTransfer $transfer, DatabaseConnection $connection, User $user): void
    {
        $scope = $transfer->scope;
        $database = $scope['database'] ?? $connection->database;
        $link = $this->connector->sql($connection, $user, $connection->driver === Driver::Pgsql ? $database : null);
        $dialect = Dialect::for($link, $database, $scope['schema'] ?? null);
        $format = $transfer->format;
        $sources = [];

        if (filled($scope['query'] ?? null)) {
            $query = (string) $scope['query'];
            if (! $connection->canWrite($user) && ! Statements::isRead($query, $connection->driver->family())) {
                throw new DatabaseException(__('You may only read this connection.'));
            }
            $sources['query'] = $query;
        } else {
            foreach ((array) ($scope['tables'] ?? []) as $table) {
                $sources[(string) $table] = 'SELECT * FROM '.$dialect->qualified((string) $table);
            }
        }

        if ($sources === []) {
            throw new DatabaseException(__('Nothing to export: choose a table.'));
        }

        $dir = $this->bench();
        $files = [];
        $total = 0;

        try {
            foreach ($sources as $name => $sql) {
                $path = $dir.'/'.Str::slug($name === 'query' ? 'query' : $name, '_').'.'.$format;
                $count = $this->writeRows($link, $sql, $path, $format, (string) ($scope['delimiter'] ?? ','));
                $total += $count;
                $files[] = $path;
                $transfer->log(trans_choice(':table: :count row|:table: :count rows', $count, ['table' => $name, 'count' => number_format($count)]));
            }

            $base = Str::slug((string) ($database ?: $connection->name)).'-'.(count($sources) === 1 ? Str::slug((string) array_key_first($sources), '_').'-' : '').now()->format('Y-m-d_His');

            if (count($files) === 1) {
                $file = $this->newFile($transfer, $base.'.'.$format);
                File::move($files[0], $file);
                $this->finishFile($transfer, $file, (bool) ($scope['gzip'] ?? false));
            } else {
                $file = $this->newFile($transfer, $base.'.zip');
                $zip = new ZipArchive;
                $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
                foreach ($files as $path) {
                    $zip->addFile($path, basename($path));
                }
                $zip->close();
                $this->finishFile($transfer, $file, false);
            }
        } finally {
            File::deleteDirectory($dir);
            $link->close();
        }

        $transfer->forceFill(['rows' => $total])->save();
    }

    private function writeRows(Link $link, string $sql, string $path, string $format, string $delimiter): int
    {
        $out = fopen($path, 'w') ?: throw new DatabaseException(__('Could not write the export.'));
        $columns = [];
        $count = 0;

        try {
            if ($format === 'json') {
                fwrite($out, "[\n");
            }

            foreach ($link->cursor($sql) as $i => $row) {
                if ($i === 0) {
                    $columns = $row;
                    if ($format === 'csv') {
                        fputcsv($out, $columns, $delimiter === '\t' ? "\t" : ($delimiter ?: ','), escape: '');
                    }

                    continue;
                }

                if ($format === 'csv') {
                    fputcsv($out, array_map(Values::csv(...), $row), $delimiter === '\t' ? "\t" : ($delimiter ?: ','), escape: '');
                } else {
                    fwrite($out, ($count > 0 ? ",\n" : '').'  '.json_encode(array_combine($columns, array_map(fn ($v) => Values::display($v), $row)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION));
                }
                $count++;
            }

            if ($format === 'json') {
                fwrite($out, "\n]\n");
            }
        } finally {
            fclose($out);
        }

        return $count;
    }

    private function exportRedis(DatabaseTransfer $transfer, DatabaseConnection $connection, User $user): void
    {
        $scope = $transfer->scope;
        $keyspace = new Keyspace($this->connector->redis($connection, $user, isset($scope['database']) ? (int) $scope['database'] : null));
        $file = $this->newFile($transfer, Str::slug($connection->name).'-db'.(int) ($scope['database'] ?? 0).'-'.now()->format('Y-m-d_His').'.json');
        $out = fopen($file, 'w') ?: throw new DatabaseException(__('Could not write the export.'));
        $cursor = '0';
        $count = 0;

        try {
            fwrite($out, "[\n");
            do {
                $reply = $keyspace->client->command('SCAN', $cursor, 'MATCH', (string) ($scope['pattern'] ?? '') ?: '*', 'COUNT', 1000);
                $cursor = (string) ($reply[0] ?? '0');
                foreach ((array) ($reply[1] ?? []) as $key) {
                    $item = $keyspace->export((string) $key);
                    if ($item !== null) {
                        fwrite($out, ($count > 0 ? ",\n" : '').'  '.json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
                        $count++;
                    }
                }
            } while ($cursor !== '0');
            fwrite($out, "\n]\n");
        } finally {
            fclose($out);
            $keyspace->client->close();
        }

        $transfer->log(trans_choice(':count key|:count keys', $count, ['count' => number_format($count)]));
        $transfer->forceFill(['rows' => $count])->save();
        $this->finishFile($transfer, $file, (bool) ($scope['gzip'] ?? false));
    }

    /* ------------------------------------------------------------------ imports */

    private function importSql(DatabaseTransfer $transfer, DatabaseConnection $connection, User $user): void
    {
        $scope = $transfer->scope;
        $database = $scope['database'] ?? $connection->database;
        $file = $this->plainFile($transfer);
        $engine = (string) ($scope['engine'] ?? 'auto');
        $family = $connection->driver->family();

        try {
            if ($engine !== 'studio') {
                $link = $connection->driver === Driver::Sqlite ? $this->connector->sql($connection, $user) : null;

                if ($link instanceof SqliteShellLink) {
                    $transfer->log(__('Running the file with sqlite3 on :host.', ['host' => $connection->sshConnection?->host]));
                    $link->import($file);

                    return;
                }

                $binary = $family === 'sqlite' ? null : $this->binary($family === 'mysql' ? 'mysql' : 'psql');

                if ($binary !== null) {
                    if (blank($database)) {
                        throw new DatabaseException(__('Choose the database to import into.'));
                    }
                    $this->nativeImport($transfer, $connection, $user, (string) $database, $file, $binary);

                    return;
                }
            }

            $this->studioImport($transfer, $connection, $user, $database, $file, (bool) ($scope['continue'] ?? false));
        } finally {
            if ($file !== $transfer->absolutePath()) {
                @unlink($file);
            }
        }
    }

    private function nativeImport(DatabaseTransfer $transfer, DatabaseConnection $connection, User $user, string $database, string $file, string $binary): void
    {
        $endpoint = $this->connector->endpoint($connection, $user);
        $bench = $this->bench();
        $continue = (bool) ($transfer->scope['continue'] ?? false);

        try {
            if ($connection->driver->family() === 'mysql') {
                $command = [$binary, '--defaults-extra-file='.$this->mysqlDefaults($bench, $connection, $endpoint), '--default-character-set=utf8mb4', ...($continue ? ['--force'] : []), $database];
                $env = [];
            } else {
                $command = [$binary, '--no-psqlrc', '--quiet', '--host='.($endpoint['socket'] ?? $endpoint['host']), '--port='.$endpoint['port'], '--username='.(string) $connection->username, '--dbname='.$database, ...($continue ? [] : ['--set=ON_ERROR_STOP=1']), '--file=-'];
                $env = ['PGPASSWORD' => (string) $connection->password, 'PGCONNECT_TIMEOUT' => '15', 'PGSSLMODE' => isset($endpoint['socket']) ? 'disable' : ($connection->tls ? 'require' : 'prefer')];
            }

            $transfer->log(__('Running the file with :tool.', ['tool' => basename($binary)]));
            $input = fopen($file, 'r');

            try {
                $result = Process::timeout($this->timeout())->env($env)->input($input)->run($command);
            } finally {
                is_resource($input) && fclose($input);
            }

            if (trim($result->errorOutput()) !== '') {
                $transfer->log(Str::limit(trim($result->errorOutput()), 4000));
            }

            if (! $result->successful()) {
                throw new DatabaseException(Str::limit(trim($result->errorOutput()) ?: __('exit code :code', ['code' => $result->exitCode()]), 600));
            }
        } finally {
            File::deleteDirectory($bench);
        }
    }

    private function studioImport(DatabaseTransfer $transfer, DatabaseConnection $connection, User $user, ?string $database, string $file, bool $continue): void
    {
        $link = $this->connector->sql($connection, $user, $database);
        $splitter = new Statements($connection->driver->family());
        $in = fopen($file, 'r') ?: throw new DatabaseException(__('The file to import is gone.'));
        $done = 0;
        $failed = 0;
        $transfer->log(__('Running the file statement by statement.'));

        $run = function (array $statements) use ($link, $transfer, $continue, &$done, &$failed) {
            foreach ($statements as $statement) {
                try {
                    $link->exec($statement);
                    $done++;
                } catch (Throwable $e) {
                    $failed++;
                    $error = DatabaseException::from($e)->getMessage();
                    $transfer->log(__('Failed: :statement — :error', ['statement' => Str::limit((string) preg_replace('/\s+/', ' ', $statement), 160), 'error' => $error]));

                    if (! $continue) {
                        throw new DatabaseException(__('Stopped at statement :n: :error', ['n' => $done + $failed, 'error' => $error]));
                    }
                }

                if (($done + $failed) % 5000 === 0) {
                    $transfer->log(trans_choice(':count statement run|:count statements run', $done, ['count' => number_format($done)]));
                }
            }
        };

        try {
            while (! feof($in)) {
                $run($splitter->feed((string) fread($in, 1024 * 1024)));
            }
            $run($splitter->finish());
        } finally {
            fclose($in);
            $link->close();
        }

        $transfer->forceFill(['rows' => $done])->save();
        $transfer->log(trans_choice(':count statement run|:count statements run', $done, ['count' => number_format($done)]).($failed > 0 ? ' · '.trans_choice(':count failed|:count failed', $failed, ['count' => $failed]) : ''));
    }

    private function importRows(DatabaseTransfer $transfer, DatabaseConnection $connection, User $user): void
    {
        $scope = $transfer->scope;
        $database = $scope['database'] ?? $connection->database;
        $table = (string) ($scope['table'] ?? throw new DatabaseException(__('Choose the table to import into.')));
        $link = $this->connector->sql($connection, $user, $connection->driver === Driver::Pgsql ? $database : null);
        $dialect = Dialect::for($link, $database, $scope['schema'] ?? null);
        $known = array_column($dialect->columns($table), 'name');

        if ($known === []) {
            throw new DatabaseException(__('There is no table :table.', ['table' => $table]));
        }

        $file = $this->plainFile($transfer);
        $emptyNull = (bool) ($scope['empty_null'] ?? true);
        $count = 0;

        try {
            if ($scope['truncate'] ?? false) {
                $link->affect($dialect->truncate($table));
                $transfer->log(__(':table emptied.', ['table' => $table]));
            }

            $batch = [];
            $columns = null;
            $logged = 0;

            foreach ($this->records($file, $transfer->format, (string) ($scope['delimiter'] ?? 'auto'), (bool) ($scope['header'] ?? true), $known) as $record) {
                if ($columns === null) {
                    $keys = array_map('strval', array_keys($record));
                    $skipped = array_diff($keys, $known);
                    if ($skipped !== []) {
                        $transfer->log(__('Columns not in :table, left out: :columns', ['table' => $table, 'columns' => implode(', ', $skipped)]));
                    }
                    $columns = array_values(array_intersect($keys, $known));
                    if ($columns === []) {
                        throw new DatabaseException(__('None of the file’s columns is a column of :table.', ['table' => $table]));
                    }
                }

                $batch[] = array_map(fn (string $c) => $emptyNull && ($record[$c] ?? null) === '' ? null : (is_array($record[$c] ?? null) ? json_encode($record[$c]) : ($record[$c] ?? null)), $columns);

                if (count($batch) >= max(1, intdiv(1000, count($columns)))) {
                    $count += $this->insertRows($link, $dialect, $table, $columns, $batch);
                    $batch = [];
                    if ($count - $logged >= 10000) {
                        $logged = $count;
                        $transfer->log(trans_choice(':count row imported|:count rows imported', $count, ['count' => number_format($count)]));
                    }
                }
            }

            if ($columns !== null && $batch !== []) {
                $count += $this->insertRows($link, $dialect, $table, $columns, $batch);
            }
        } finally {
            $link->close();
            if ($file !== $transfer->absolutePath()) {
                @unlink($file);
            }
        }

        $transfer->forceFill(['rows' => $count])->save();
        $transfer->log(trans_choice(':count row imported into :table|:count rows imported into :table', $count, ['count' => number_format($count), 'table' => $table]));
    }

    /**
     * One multi-row INSERT of a batch of an import.
     *
     * @param  list<string>  $columns
     * @param  list<list<mixed>>  $batch
     */
    private function insertRows(Link $link, Dialect $dialect, string $table, array $columns, array $batch): int
    {
        $placeholders = implode(', ', array_fill(0, count($batch), '('.implode(', ', array_fill(0, count($columns), '?')).')'));
        $link->affect('INSERT INTO '.$dialect->qualified($table).' ('.implode(', ', array_map($dialect->ident(...), $columns)).') VALUES '.$placeholders, array_merge(...$batch));

        return count($batch);
    }

    /**
     * The rows of a CSV or JSON file, each as column => value.
     *
     * @param  list<string>  $known
     * @return \Generator<int, array<string, mixed>>
     */
    private function records(string $file, string $format, string $delimiter, bool $header, array $known): \Generator
    {
        if ($format === 'json') {
            $data = json_decode((string) file_get_contents($file), true);

            if (! is_array($data)) {
                // JSON lines
                $data = array_values(array_filter(array_map(fn (string $line) => json_decode($line, true), preg_split('/\r?\n/', (string) file_get_contents($file)) ?: [])));
            }

            foreach (array_is_list($data) ? $data : [$data] as $row) {
                if (is_array($row)) {
                    yield $row;
                }
            }

            return;
        }

        $in = fopen($file, 'r') ?: throw new DatabaseException(__('The file to import is gone.'));

        try {
            $first = (string) fgets($in);
            rewind($in);

            if ($delimiter === 'auto') {
                $delimiter = collect([',', ';', "\t", '|'])->sortByDesc(fn (string $d) => substr_count($first, $d))->first();
            }
            $delimiter = $delimiter === '\t' ? "\t" : $delimiter;

            if (str_starts_with($first, "\xEF\xBB\xBF")) {
                fread($in, 3);
            }

            $names = null;
            while (($row = fgetcsv($in, null, $delimiter, '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if ($names === null) {
                    $names = $header ? array_map(fn ($v) => trim((string) $v), $row) : array_slice($known, 0, count($row));
                    if ($header) {
                        continue;
                    }
                }
                yield array_combine($names, array_slice(array_pad($row, count($names), null), 0, count($names)));
            }
        } finally {
            fclose($in);
        }
    }

    private function importRedis(DatabaseTransfer $transfer, DatabaseConnection $connection, User $user): void
    {
        $scope = $transfer->scope;
        $keyspace = new Keyspace($this->connector->redis($connection, $user, isset($scope['database']) ? (int) $scope['database'] : null));
        $file = $this->plainFile($transfer);
        $count = 0;
        $skipped = 0;

        try {
            $data = json_decode((string) file_get_contents($file), true);
            if (! is_array($data)) {
                $data = array_values(array_filter(array_map(fn (string $line) => json_decode($line, true), preg_split('/\r?\n/', (string) file_get_contents($file)) ?: [])));
            }

            foreach ($data as $item) {
                if (! is_array($item) || ! isset($item['key'], $item['type'])) {
                    continue;
                }
                $entry = ['key' => (string) $item['key'], 'type' => (string) $item['type'], 'ttl' => isset($item['ttl']) ? (int) $item['ttl'] : null, 'value' => $item['value'] ?? null];
                $keyspace->import($entry, (bool) ($scope['replace'] ?? false)) ? $count++ : $skipped++;
            }
        } finally {
            $keyspace->client->close();
            if ($file !== $transfer->absolutePath()) {
                @unlink($file);
            }
        }

        $transfer->forceFill(['rows' => $count])->save();
        $transfer->log(trans_choice(':count key imported|:count keys imported', $count, ['count' => number_format($count)]).($skipped > 0 ? ' · '.trans_choice(':count existing key left as it was|:count existing keys left as they were', $skipped, ['count' => $skipped]) : ''));
    }

    /* ------------------------------------------------------------------ files */

    private function newFile(DatabaseTransfer $transfer, string $name): string
    {
        $relative = DatabaseTransfer::DIR.'/'.$transfer->uuid.'/'.$name;
        File::ensureDirectoryExists(dirname(Storage::disk('local')->path($relative)), 0700);
        $transfer->forceFill(['file_path' => $relative, 'file_name' => $name])->save();

        return Storage::disk('local')->path($relative);
    }

    private function finishFile(DatabaseTransfer $transfer, string $file, bool $gzip): void
    {
        if ($gzip) {
            $in = fopen($file, 'r');
            $out = gzopen($file.'.gz', 'w6');

            if ($in === false || $out === false) {
                throw new DatabaseException(__('Could not compress the export.'));
            }

            while (! feof($in)) {
                gzwrite($out, (string) fread($in, 1024 * 1024));
            }

            fclose($in);
            gzclose($out);
            unlink($file);
            $file .= '.gz';
            $transfer->forceFill(['file_path' => $transfer->file_path.'.gz', 'file_name' => $transfer->file_name.'.gz'])->save();
        }

        $bytes = (int) filesize($file);
        $transfer->forceFill([
            'bytes' => $bytes,
            'expires_at' => now()->addDays((int) config('studio.databases.keep_exports_days', 7)),
        ])->save();
        $transfer->log(__('Ready: :name, :size.', ['name' => $transfer->file_name, 'size' => Values::bytes($bytes)]));
    }

    /** The uploaded file, unpacked when it is gzip. */
    private function plainFile(DatabaseTransfer $transfer): string
    {
        $path = $transfer->absolutePath();

        if (! is_file($path)) {
            throw new DatabaseException(__('The file to import is gone.'));
        }

        $head = (string) file_get_contents($path, false, null, 0, 2);
        if ($head !== "\x1f\x8b") {
            return $path;
        }

        $plain = $this->bench().'/import';
        $in = gzopen($path, 'r');
        $out = fopen($plain, 'w');

        if ($in === false || $out === false) {
            throw new DatabaseException(__('Could not unpack the file.'));
        }

        while (! gzeof($in)) {
            fwrite($out, (string) gzread($in, 1024 * 1024));
        }

        gzclose($in);
        fclose($out);
        $transfer->log(__('Unpacked: :size.', ['size' => Values::bytes((int) filesize($plain))]));

        return $plain;
    }

    /** A folder only Studio reads, for what one command needs. */
    private function bench(): string
    {
        $dir = storage_path('app/private/database-work/'.Str::random(24));
        File::ensureDirectoryExists($dir, 0700);
        @chmod($dir, 0700);

        return $dir;
    }

    /** @param  array{host: string, port: int, socket: string|null}  $endpoint */
    private function mysqlDefaults(string $bench, DatabaseConnection $connection, array $endpoint): string
    {
        $quote = fn (?string $v) => '"'.addcslashes((string) $v, "\"\\\n").'"';
        $lines = ['[client]', 'user='.$quote($connection->username), 'password='.$quote($connection->password)];
        $lines[] = isset($endpoint['socket']) ? 'socket='.$quote($endpoint['socket']) : 'host='.$quote($endpoint['host']);
        if (! isset($endpoint['socket'])) {
            $lines[] = 'port='.$endpoint['port'];
            $lines[] = 'protocol=TCP';
            if ($connection->tls) {
                $lines[] = 'ssl=1';
            }
        }

        File::put($bench.'/my.cnf', implode("\n", $lines)."\n");
        chmod($bench.'/my.cnf', 0600);

        return $bench.'/my.cnf';
    }

    /** The tool on this server: the configured one, else found on the PATH. */
    public function binary(string $tool): ?string
    {
        $configured = config('studio.databases.binaries.'.$tool);

        if (filled($configured)) {
            return is_executable((string) $configured) ? (string) $configured : null;
        }

        $finder = new ExecutableFinder;
        $names = match ($tool) {
            'mysqldump' => ['mariadb-dump', 'mysqldump'],
            'mysql' => ['mariadb', 'mysql'],
            default => [$tool],
        };

        foreach ($names as $name) {
            if (($path = $finder->find($name)) !== null) {
                return $path;
            }
        }

        return null;
    }

    private static function pgName(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private function timeout(): int
    {
        return (int) config('studio.databases.transfer_timeout', 21600);
    }

    /** Remove the files of the exports past their days, and of the transfers that are gone. */
    public function prune(): int
    {
        $pruned = 0;

        DatabaseTransfer::query()->where('kind', 'export')->whereNotNull('expires_at')->where('expires_at', '<', now())->whereNotNull('file_path')
            ->each(function (DatabaseTransfer $transfer) use (&$pruned) {
                Storage::disk('local')->deleteDirectory(DatabaseTransfer::DIR.'/'.$transfer->uuid);
                $transfer->forceFill(['file_path' => null])->save();
                $pruned++;
            });

        // an import's file is only needed while it runs
        DatabaseTransfer::query()->where('kind', 'import')->whereIn('status', ['success', 'failed'])->whereNotNull('file_path')->where('finished_at', '<', now()->subDay())
            ->each(function (DatabaseTransfer $transfer) use (&$pruned) {
                Storage::disk('local')->deleteDirectory(DatabaseTransfer::DIR.'/'.$transfer->uuid);
                $transfer->forceFill(['file_path' => null])->save();
                $pruned++;
            });

        // what a dead worker left running
        DatabaseTransfer::query()->where('status', 'running')->where('updated_at', '<', now()->subHours(7))
            ->update(['status' => 'failed', 'error' => __('The worker stopped before the end.'), 'finished_at' => now()]);

        Log::info('database transfers pruned', ['files' => $pruned]);

        return $pruned;
    }
}
