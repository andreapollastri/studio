<?php

namespace App\Databases\Links;

use App\Databases\DatabaseException;
use App\Databases\Driver;
use App\Databases\Result;
use App\Databases\Statements;
use App\Databases\Tunnels;
use App\Databases\Values;
use App\Models\SshConnection;
use App\Models\User;
use Generator;

/**
 * A SQLite file on another host, through that host's own sqlite3 (3.33 or later, for its
 * JSON output) over the SSH tunnel's master. Statements go as one script on its standard
 * input, each between two markers, so every one gets its rows or its changes back and a
 * failure says which statement it was. The file must exist: a mistyped path never creates
 * an empty database.
 */
final class SqliteShellLink implements Link
{
    private const SHELL = <<<'SH'
        p=$1; shift
        case $p in "~/"*) p="$HOME/${p#\~/}";; esac
        [ -f "$p" ] || { echo "no such file: $p" >&2; exit 3; }
        command -v sqlite3 >/dev/null 2>&1 || { echo "sqlite3 is not installed on this host" >&2; exit 4; }
        exec sqlite3 -bail -json -cmd '.timeout 5000' "$@" "$p"
        SH;

    public function __construct(
        private readonly Tunnels $tunnels,
        private readonly SshConnection $ssh,
        private readonly User $as,
        private readonly string $path,
    ) {}

    public function driver(): Driver
    {
        return Driver::Sqlite;
    }

    public function select(string $sql, array $bindings = []): Result
    {
        $result = $this->script([Statements::bind($sql, $bindings, $this->quote(...))], 0)[0];

        return $result->error === null ? $result : throw new DatabaseException($result->error);
    }

    public function affect(string $sql, array $bindings = []): int
    {
        return (int) $this->select($sql, $bindings)->affected;
    }

    public function exec(string $sql): int
    {
        return $this->affect($sql);
    }

    public function script(array $statements, int $limit, bool $readOnly = false): array
    {
        $script = '';

        foreach ($statements as $i => $sql) {
            $sql = rtrim(trim($sql), ';');
            if ($limit > 0 && Statements::isQuery($sql, 'sqlite')) {
                $sql = 'SELECT * FROM ('.$sql.') LIMIT '.($limit + 1);
            }
            $script .= "SELECT 'studio:{$i}' AS studio_marker;\n{$sql};\nSELECT changes() AS studio_changes;\n";
        }

        $started = hrtime(true);
        $run = $this->tunnels->exec($this->ssh, $this->as, ['sh', '-c', self::SHELL, 'sh', $this->path, ...($readOnly ? ['-readonly'] : [])], $script, (int) config('studio.databases.timeout', 120));
        $ms = (int) round((hrtime(true) - $started) / 1e6);

        if (in_array($run->exitCode(), [3, 4], true) || ($run->exitCode() !== 0 && trim($run->output()) === '')) {
            if (! str_contains($run->output(), 'studio_marker')) {
                throw new DatabaseException(trim($run->errorOutput()) ?: __('sqlite3 failed on :host.', ['host' => $this->ssh->host]));
            }
        }

        $results = [];
        $current = null;
        $rows = null;

        foreach ($this->segments($run->output()) as $segment) {
            $first = $segment[0] ?? [];

            if (array_keys($first) === ['studio_marker']) {
                $current = (int) substr((string) $first['studio_marker'], 7);
                $rows = null;

                continue;
            }

            if (array_keys($first) === ['studio_changes'] && $current !== null) {
                $results[$current] = $rows !== null
                    ? $this->rowsResult($rows, $limit)->withTiming($statements[$current], $ms)
                    : (new Result(affected: (int) $first['studio_changes']))->withTiming($statements[$current], $ms);
                $current = null;

                continue;
            }

            $rows = $segment;
        }

        if ($current !== null && $rows !== null && $run->exitCode() === 0) {
            $results[$current] = $this->rowsResult($rows, $limit)->withTiming($statements[$current], $ms);
            $current = null;
        }

        if ($run->exitCode() !== 0) {
            $failed = $current ?? count($results);
            $error = (string) preg_replace('/^(Parse|Runtime) error near line \d+:\s*/', '', trim($run->errorOutput()));
            $results[$failed] = Result::failed($statements[$failed] ?? '', $error !== '' ? $error : __('sqlite3 stopped with exit code :code.', ['code' => $run->exitCode()]), $ms);
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * The JSON arrays sqlite3 printed, one per statement that gave rows.
     *
     * @return list<list<array<string, mixed>>>
     */
    private function segments(string $output): array
    {
        $segments = [];

        foreach (preg_split('/\n(?=\[)/', trim($output)) ?: [] as $chunk) {
            $decoded = json_decode(trim($chunk), true);
            if (is_array($decoded) && array_is_list($decoded)) {
                $segments[] = $decoded;
            }
        }

        return $segments;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function rowsResult(array $rows, int $limit): Result
    {
        $truncated = $limit > 0 && count($rows) > $limit;
        $rows = $truncated ? array_slice($rows, 0, $limit) : $rows;
        $columns = array_map('strval', array_keys($rows[0] ?? []));

        return new Result($columns, array_map(fn (array $row) => array_values($row), $rows), truncated: $truncated);
    }

    public function cursor(string $sql): Generator
    {
        $sql = rtrim(trim($sql), ';');
        $offset = 0;
        $first = true;

        do {
            $page = $this->select('SELECT * FROM ('.$sql.') LIMIT 5000 OFFSET '.$offset);
            if ($first) {
                yield $page->columns;
                $first = false;
            }
            foreach ($page->rows as $row) {
                yield $row;
            }
            $offset += 5000;
        } while (count($page->rows) === 5000);
    }

    public function quote(mixed $value): string
    {
        $value = Values::scalar($value);

        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            Values::isBinary($value) => "X'".bin2hex((string) $value)."'",
            default => "'".str_replace("'", "''", (string) $value)."'",
        };
    }

    public function ident(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    public function close(): void {}

    /** Run a whole file of SQL on the host, for an import: sqlite3 reads it as it comes. */
    public function import(string $file): void
    {
        $input = fopen($file, 'r') ?: throw new DatabaseException(__('The file to import is gone.'));

        try {
            $run = $this->tunnels->exec($this->ssh, $this->as, ['sh', '-c', self::SHELL, 'sh', $this->path], $input, (int) config('studio.databases.transfer_timeout', 21600));
        } finally {
            fclose($input);
        }

        if (! $run->successful()) {
            throw new DatabaseException(trim($run->errorOutput()) ?: __('sqlite3 stopped with exit code :code.', ['code' => $run->exitCode()]));
        }
    }

    /**
     * sqlite3's own .dump of the file, or of some tables, into a local file.
     *
     * @param  list<string>  $tables
     */
    public function dump(string $into, array $tables = []): void
    {
        $command = '.dump'.($tables === [] ? '' : ' '.implode(' ', array_map(fn (string $t) => "'".str_replace("'", "''", $t)."'", $tables)))."\n";
        $run = $this->tunnels->exec($this->ssh, $this->as, ['sh', '-c', self::SHELL, 'sh', $this->path, '-readonly'], $command, (int) config('studio.databases.transfer_timeout', 21600), $into);

        if (! $run->successful()) {
            throw new DatabaseException(trim($run->errorOutput()) ?: __('sqlite3 stopped with exit code :code.', ['code' => $run->exitCode()]));
        }
    }
}
