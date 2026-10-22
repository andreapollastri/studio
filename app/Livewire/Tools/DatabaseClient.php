<?php

namespace App\Livewire\Tools;

use App\Databases\DatabaseException;
use App\Databases\Dialects\Dialect;
use App\Databases\Dialects\Mysql;
use App\Databases\Dialects\Postgres;
use App\Databases\Dialects\Sqlite;
use App\Databases\Driver;
use App\Databases\Links\Link;
use App\Databases\Links\PdoLink;
use App\Databases\Result;
use App\Databases\Statements;
use App\Databases\Values;
use App\Livewire\Concerns\UsesDatabaseConnection;
use App\Models\DatabaseConnection;
use App\Models\DatabaseQuery;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * The SQL client of a connection, as TablePlus is: its databases (and schemas), tables and
 * views on the left; a table's rows to filter, sort, page and edit in place, its structure
 * and its DDL; a SQL editor with its history and saved queries; imports and exports.
 *
 * Every request opens its own link (through the tunnel's master when there is one). A
 * person who may only read gets read-only transactions and only statements that read; a
 * production connection opens with its changes locked.
 */
#[Layout('layouts::app')]
class DatabaseClient extends Component
{
    use UsesDatabaseConnection;
    use WithFileUploads;

    #[Locked]
    public DatabaseConnection $connection;

    /** Inside the project's Db tab (App\Livewire\Studio\PaneDatabase): sized to the pane, no way back to the list. */
    #[Locked]
    public bool $embedded = false;

    #[Url]
    public ?string $db = null;

    #[Url]
    public ?string $schema = null;

    #[Url]
    public ?string $table = null;

    /** data, structure, ddl, sql, transfers */
    #[Url]
    public string $tab = 'data';

    public string $objectFilter = '';

    /** @var list<array{column: string, op: string, value: string}> */
    public array $filters = [];

    public ?string $sort = null;

    public string $direction = 'asc';

    public int $page = 1;

    public int $perPage = 100;

    // ---- SQL editor
    public string $sql = '';

    public ?string $resultsKey = null;

    public string $saveTitle = '';

    // ---- A row in its dialog
    public ?string $rowIdentity = null;

    /** @var array<string, string|null> */
    public array $rowValues = [];

    /** @var array<string, bool> */
    public array $rowNulls = [];

    /** @var array<string, bool> a new row: these columns take their default */
    public array $rowDefaults = [];

    // ---- SQL to review before it runs
    public string $reviewTitle = '';

    public string $reviewSql = '';

    // ---- Structure forms
    public string $newTableName = '';

    /** @var list<array{name: string, type: string, nullable: bool, default: string|null, primary: bool, increments: bool}> */
    public array $newColumns = [];

    /** @var array{name: string, type: string, nullable: bool, default: string|null, primary: bool, increments: bool} */
    public array $column = ['name' => '', 'type' => '', 'nullable' => true, 'default' => null, 'primary' => false, 'increments' => false];

    public string $indexName = '';

    /** @var list<string> */
    public array $indexColumns = [];

    public bool $indexUnique = false;

    public string $renameTo = '';

    public ?string $renaming = null;

    public string $newDatabase = '';

    // ---- Imports and exports
    public string $exportFormat = 'sql';

    /** @var list<string> */
    public array $exportTables = [];

    public bool $exportStructure = true;

    public bool $exportData = true;

    public bool $exportDrop = true;

    public bool $exportGzip = false;

    public string $exportEngine = 'auto';

    public ?TemporaryUploadedFile $importFile = null;

    public ?string $importDrive = null;

    public string $importFormat = 'sql';

    public ?string $importTable = null;

    public bool $importHeader = true;

    public bool $importTruncate = false;

    public bool $importContinue = false;

    public bool $importEmptyNull = true;

    public string $importDelimiter = 'auto';

    private ?Link $openLink = null;

    private ?Dialect $openDialect = null;

    public function mount(DatabaseConnection $connection): void
    {
        $this->connection = $connection;
        $this->guard();

        if ($connection->driver->isRedis()) {
            $this->redirectRoute('databases.redis', $connection, navigate: true);

            return;
        }

        $this->restorePlace();
        $this->db ??= $connection->driver === Driver::Sqlite ? 'main' : (filled($connection->database) ? (string) $connection->database : null);
        $this->schema ??= $connection->driver === Driver::Pgsql ? 'public' : null;
        $this->tab = in_array($this->tab, ['data', 'structure', 'ddl', 'sql', 'transfers'], true) ? $this->tab : 'data';
        $this->sql = (string) session()->get($this->draftKey(), '');
    }

    public function family(): string
    {
        return $this->connection->driver->family();
    }

    protected function link(): Link
    {
        return $this->openLink ??= $this->connector()->sql($this->connection, auth()->user(), $this->family() === 'pgsql' ? $this->db : null);
    }

    protected function dialect(): Dialect
    {
        return $this->openDialect ??= Dialect::for($this->link(), $this->family() === 'sqlite' ? null : $this->db, $this->schema);
    }

    public function dehydrate(): void
    {
        $this->openLink?->close();
        $this->openLink = null;
        $this->openDialect = null;
        $this->keepPlace();
    }

    /** Where the person is in this connection, for this session: kept on every request. */
    private function placeKey(): string
    {
        return 'databases.place.'.$this->connection->id;
    }

    private function keepPlace(): void
    {
        if ($this->connection->driver->isRedis()) {
            return;
        }

        session()->put($this->placeKey(), [
            'db' => $this->db, 'schema' => $this->schema, 'table' => $this->table, 'tab' => $this->tab,
            'filters' => $this->filters, 'sort' => $this->sort, 'direction' => $this->direction, 'page' => $this->page, 'perPage' => $this->perPage,
        ]);
        // the Databases page offers to go back to the last one opened on it
        if (! $this->embedded) {
            session()->put('databases.last', $this->connection->id);
        }
    }

    /**
     * Back to where the person was in this connection (database, table, tab, filters, sort, page)
     * when the address says nothing else: another page of Studio in between loses nothing.
     */
    private function restorePlace(): void
    {
        $place = session()->get($this->placeKey());

        if (! is_array($place) || $this->db !== null || $this->schema !== null || $this->table !== null || $this->tab !== 'data') {
            return;
        }

        $text = fn (string $key): ?string => is_string($place[$key] ?? null) && $place[$key] !== '' ? $place[$key] : null;
        $this->db = $text('db');
        $this->schema = $text('schema');
        $this->table = $text('table');
        $this->tab = $text('tab') ?? 'data';
        $this->filters = array_values(array_filter((array) ($place['filters'] ?? []), fn (mixed $f): bool => is_array($f) && is_string($f['column'] ?? null) && is_string($f['op'] ?? null) && is_string($f['value'] ?? null)));
        $this->sort = $text('sort');
        $this->direction = ($place['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $this->page = max(1, (int) ($place['page'] ?? 1));
        $this->perPage = in_array((int) ($place['perPage'] ?? 100), [50, 100, 250, 500, 1000], true) ? (int) $place['perPage'] : 100;
    }

    private function cacheKey(string $what): string
    {
        return 'db:'.$what.':'.auth()->id().':'.$this->connection->id.':'.md5($this->db.'|'.$this->schema.'|'.$this->connection->updated_at);
    }

    /** @return list<array{name: string, type: string, rows: int|null, size: int|null, comment: string|null}> */
    private function objects(): array
    {
        return Cache::remember($this->cacheKey('objects'), 300, fn () => $this->dialect()->objects());
    }

    /** @return list<string> */
    private function databases(): array
    {
        return Cache::remember($this->cacheKey('databases').':all', 300, fn () => $this->dialect()->databases());
    }

    /** @return list<string> */
    private function schemas(): array
    {
        return $this->family() === 'pgsql' ? Cache::remember($this->cacheKey('schemas'), 300, fn () => $this->dialect()->schemas()) : [];
    }

    /** @return list<array{name: string, type: string, nullable: bool, default: string|null, key: string|null, extra: string|null, comment: string|null}> */
    private function columns(string $table): array
    {
        return Cache::remember($this->cacheKey('columns').':'.md5($table), 120, fn () => $this->dialect()->columns($table));
    }

    private function forget(): void
    {
        foreach (['objects', 'schemas'] as $what) {
            Cache::forget($this->cacheKey($what));
        }
        Cache::forget($this->cacheKey('databases').':all');

        if ($this->table !== null) {
            Cache::forget($this->cacheKey('columns').':'.md5($this->table));
        }
    }

    public function refresh(): void
    {
        $this->forget();
        Flux::toast(text: __('Refreshed.'));
    }

    /* ------------------------------------------------------------------ navigation */

    public function useDatabase(string $database): void
    {
        $this->db = $database;
        $this->schema = $this->family() === 'pgsql' ? 'public' : null;
        $this->table = null;
        $this->resetGrid();
        $this->tab = $this->tab === 'transfers' || $this->tab === 'sql' ? $this->tab : 'data';
    }

    public function useSchema(string $schema): void
    {
        $this->schema = $schema;
        $this->table = null;
        $this->resetGrid();
    }

    public function openTable(string $table, string $tab = 'data'): void
    {
        $this->table = $table;
        $this->tab = $tab;
        $this->resetGrid();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['data', 'structure', 'ddl', 'sql', 'transfers'], true) ? $tab : 'data';
    }

    private function resetGrid(): void
    {
        $this->filters = [];
        $this->sort = null;
        $this->direction = 'asc';
        $this->page = 1;
    }

    public function sortBy(string $column): void
    {
        if ($this->sort === $column) {
            [$this->sort, $this->direction] = $this->direction === 'asc' ? [$column, 'desc'] : [null, 'asc'];
        } else {
            [$this->sort, $this->direction] = [$column, 'asc'];
        }
        $this->page = 1;
    }

    public function addFilter(?string $column = null): void
    {
        $this->filters = [...$this->filters, ['column' => $column ?? (string) ($this->columns((string) $this->table)[0]['name'] ?? ''), 'op' => 'contains', 'value' => '']];
    }

    public function removeFilter(int $index): void
    {
        $this->filters = array_values(array_filter($this->filters, fn (int $i) => $i !== $index, ARRAY_FILTER_USE_KEY));
        $this->page = 1;
    }

    public function updatedFilters(): void
    {
        $this->page = 1;
    }

    public function updatedPerPage(): void
    {
        $this->perPage = in_array($this->perPage, [50, 100, 250, 500, 1000], true) ? $this->perPage : 100;
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    /* ------------------------------------------------------------------ rows */

    /**
     * How the page names a row: its key's values (binary ones in base64), encoded.
     *
     * @param  array<string, mixed>  $identity
     */
    public static function rowKey(array $identity): string
    {
        return base64_encode((string) json_encode(array_map(fn ($v) => Values::isBinary(Values::scalar($v)) ? ['__b64' => base64_encode((string) Values::scalar($v))] : Values::scalar($v), $identity)));
    }

    /** @return array<string, mixed> */
    private function identity(string $encoded): array
    {
        $identity = json_decode((string) base64_decode($encoded, true), true);

        if (! is_array($identity)) {
            throw new DatabaseException(__('That row is not known.'));
        }

        return array_map(fn ($v) => is_array($v) && isset($v['__b64']) ? base64_decode((string) $v['__b64']) : $v, $identity);
    }

    /**
     * The whole value of a cell, for the editor in the grid.
     *
     * @return array{value: string|null, binary: bool}|null
     */
    public function cellValue(string $row, string $column): ?array
    {
        try {
            $values = $this->dialect()->fetchRow((string) $this->table, $this->identity($row));
        } catch (Throwable $e) {
            $this->fail($e);

            return null;
        }

        if ($values === null || ! array_key_exists($column, $values)) {
            Flux::toast(variant: 'warning', text: __('That row is gone: refresh the page.'));

            return null;
        }

        $value = Values::scalar($values[$column]);

        return ['value' => $value === null ? null : (string) Values::display($value), 'binary' => Values::isBinary($value)];
    }

    public function saveCell(string $row, string $column, ?string $value): void
    {
        try {
            $this->ensureWritable();
            $changed = $this->dialect()->update((string) $this->table, $this->identity($row), [$column => $value]);
            $this->audit('update', ['column' => $column]);
            Flux::toast(variant: $changed > 0 ? 'success' : 'warning', text: $changed > 0 ? __('Saved.') : __('Nothing changed: the row may be gone.'));
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    public function newRow(): void
    {
        $this->rowIdentity = null;
        $this->rowValues = [];
        $this->rowNulls = [];
        $this->rowDefaults = [];

        foreach ($this->columns((string) $this->table) as $column) {
            $this->rowValues[$column['name']] = '';
            $this->rowNulls[$column['name']] = false;
            $this->rowDefaults[$column['name']] = $column['default'] !== null || $column['extra'] !== null || $column['nullable'] || $column['key'] === 'primary';
        }

        Flux::modal('db-row')->show();
    }

    public function editRow(string $row, bool $duplicate = false): void
    {
        try {
            $values = $this->dialect()->fetchRow((string) $this->table, $this->identity($row));
        } catch (Throwable $e) {
            $this->fail($e);

            return;
        }

        if ($values === null) {
            Flux::toast(variant: 'warning', text: __('That row is gone: refresh the page.'));

            return;
        }

        $this->rowIdentity = $duplicate ? null : $row;
        $this->rowValues = [];
        $this->rowNulls = [];
        $this->rowDefaults = [];
        $columns = collect($this->columns((string) $this->table))->keyBy('name');

        foreach ($values as $name => $value) {
            $value = Values::scalar($value);
            $this->rowValues[$name] = $value === null ? '' : (string) Values::display($value);
            $this->rowNulls[$name] = $value === null;
            // a copy leaves its key and generated columns to the database
            $this->rowDefaults[$name] = $duplicate && ($columns[$name]['key'] ?? null) === 'primary' && ($columns[$name]['extra'] ?? null) !== null;
        }

        Flux::modal('db-row')->show();
    }

    public function saveRow(): void
    {
        try {
            $this->ensureWritable();
            $known = array_column($this->columns((string) $this->table), 'name');
            $values = [];

            foreach ($this->rowValues as $name => $value) {
                if (! in_array($name, $known, true) || ($this->rowIdentity === null && ($this->rowDefaults[$name] ?? false))) {
                    continue;
                }
                $values[$name] = ($this->rowNulls[$name] ?? false) ? null : (string) $value;
            }

            if ($this->rowIdentity === null) {
                $this->dialect()->insert((string) $this->table, $values);
                $this->audit('insert');
                Flux::toast(variant: 'success', text: __('Row added.'));
            } else {
                $original = $this->dialect()->fetchRow((string) $this->table, $this->identity($this->rowIdentity)) ?? [];
                $changes = [];
                foreach ($values as $name => $value) {
                    $before = Values::scalar($original[$name] ?? null);
                    if (Values::isBinary($before)) {
                        continue;
                    }
                    if (($before === null ? null : (string) Values::display($before)) !== $value) {
                        $changes[$name] = $value;
                    }
                }
                $this->dialect()->update((string) $this->table, $this->identity($this->rowIdentity), $changes);
                $this->audit('update', ['columns' => array_keys($changes)]);
                Flux::toast(variant: 'success', text: $changes === [] ? __('Nothing changed.') : __('Row saved.'));
            }

            Flux::modal('db-row')->close();
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    /** @param  list<string>  $rows */
    public function deleteRows(array $rows): void
    {
        try {
            $this->ensureWritable();
            $deleted = 0;

            foreach (array_slice($rows, 0, 1000) as $row) {
                $deleted += $this->dialect()->delete((string) $this->table, $this->identity($row));
            }

            $this->audit('delete', ['rows' => $deleted]);
            Flux::toast(variant: 'success', text: trans_choice(':count row deleted.|:count rows deleted.', $deleted, ['count' => $deleted]));
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    /* ------------------------------------------------------------------ structure */

    /** Show SQL Studio wrote, to read and change before it runs. */
    private function review(string $title, string $sql): void
    {
        $this->reviewTitle = $title;
        $this->reviewSql = $sql;
        Flux::modal('db-review')->show();
    }

    public function applyReview(): void
    {
        try {
            $this->ensureWritable();
            $statements = Statements::split($this->reviewSql, $this->family());
            $results = $this->link()->script($statements, 10);
            $failed = collect($results)->firstWhere('error', '!==', null);
            DatabaseQuery::record($this->connection, auth()->user(), $this->db, $this->reviewSql, $failed?->error, (int) collect($results)->sum('ms'));
            $this->audit('structure', ['sql' => Str::limit($this->reviewSql, 300)]);
            $this->forget();

            if ($failed !== null) {
                Flux::toast(variant: 'danger', text: $failed->error);

                return;
            }

            if (preg_match('/^\s*DROP\s+(TABLE|VIEW)\b/i', $this->reviewSql) && $this->table !== null) {
                $this->table = null;
            }

            if ($this->renaming !== null && $this->table === $this->renaming && $this->renameTo !== '') {
                $this->table = $this->renameTo;
                $this->renaming = null;
            }

            if ($this->newTableName !== '' && str_contains($this->reviewSql, $this->newTableName)) {
                $this->table = $this->newTableName;
                $this->tab = 'structure';
                $this->newTableName = '';
            }

            Flux::modal('db-review')->close();
            Flux::toast(variant: 'success', text: __('Done.'));
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    public function startNewTable(): void
    {
        $this->newTableName = '';
        $increments = match ($this->family()) {
            'mysql' => 'bigint unsigned',
            'pgsql' => 'bigint',
            default => 'INTEGER',
        };
        $this->newColumns = [
            ['name' => 'id', 'type' => $increments, 'nullable' => false, 'default' => null, 'primary' => true, 'increments' => true],
            ['name' => 'name', 'type' => $this->family() === 'sqlite' ? 'TEXT' : 'varchar(255)', 'nullable' => false, 'default' => null, 'primary' => false, 'increments' => false],
            ['name' => 'created_at', 'type' => $this->family() === 'sqlite' ? 'DATETIME' : 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false, 'increments' => false],
            ['name' => 'updated_at', 'type' => $this->family() === 'sqlite' ? 'DATETIME' : 'timestamp', 'nullable' => true, 'default' => null, 'primary' => false, 'increments' => false],
        ];

        Flux::modal('db-new-table')->show();
    }

    public function addNewColumn(): void
    {
        $this->newColumns = [...$this->newColumns, ['name' => '', 'type' => $this->family() === 'sqlite' ? 'TEXT' : 'varchar(255)', 'nullable' => true, 'default' => null, 'primary' => false, 'increments' => false]];
    }

    public function removeNewColumn(int $index): void
    {
        $this->newColumns = array_values(array_filter($this->newColumns, fn (int $i) => $i !== $index, ARRAY_FILTER_USE_KEY));
    }

    public function reviewNewTable(): void
    {
        $this->validate([
            'newTableName' => ['required', 'string', 'max:64'],
            'newColumns' => ['required', 'array', 'min:1'],
            'newColumns.*.name' => ['required', 'string', 'max:64', 'distinct'],
            'newColumns.*.type' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_ (),\'.\[\]]+$/'],
            'newColumns.*.default' => ['nullable', 'string', 'max:255'],
        ]);

        Flux::modal('db-new-table')->close();
        $this->review(__('New table :name', ['name' => $this->newTableName]), $this->dialect()->createTable($this->newTableName, array_map(fn (array $c) => [...$c, 'nullable' => (bool) $c['nullable'], 'primary' => (bool) $c['primary'], 'increments' => (bool) $c['increments']], $this->newColumns)));
    }

    public function startAddColumn(): void
    {
        $this->column = ['name' => '', 'type' => $this->family() === 'sqlite' ? 'TEXT' : 'varchar(255)', 'nullable' => true, 'default' => null, 'primary' => false, 'increments' => false];
        $this->resetValidation();
        Flux::modal('db-column')->show();
    }

    public function reviewAddColumn(): void
    {
        $this->validate([
            'column.name' => ['required', 'string', 'max:64'],
            'column.type' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_ (),\'.\[\]]+$/'],
            'column.default' => ['nullable', 'string', 'max:255'],
        ]);

        Flux::modal('db-column')->close();
        $this->review(__('Add the column :name', ['name' => $this->column['name']]), $this->dialect()->addColumn((string) $this->table, [...$this->column, 'nullable' => (bool) $this->column['nullable'], 'primary' => false, 'increments' => false]));
    }

    public function reviewDropColumn(string $column): void
    {
        $this->review(__('Drop the column :name', ['name' => $column]), $this->dialect()->dropColumn((string) $this->table, $column));
    }

    public function reviewRenameColumn(string $column, string $to): void
    {
        if (trim($to) === '' || $to === $column) {
            return;
        }

        $this->review(__('Rename the column :name', ['name' => $column]), $this->dialect()->renameColumn((string) $this->table, $column, trim($to)));
    }

    public function startAddIndex(): void
    {
        $this->indexName = '';
        $this->indexColumns = [];
        $this->indexUnique = false;
        $this->resetValidation();
        Flux::modal('db-index')->show();
    }

    public function reviewAddIndex(): void
    {
        $this->validate([
            'indexColumns' => ['required', 'array', 'min:1'],
            'indexName' => ['nullable', 'string', 'max:64'],
        ]);

        $name = $this->indexName !== '' ? $this->indexName : Str::limit((string) $this->table.'_'.implode('_', $this->indexColumns).($this->indexUnique ? '_unique' : '_index'), 60, '');
        Flux::modal('db-index')->close();
        $this->review(__('New index :name', ['name' => $name]), $this->dialect()->createIndex((string) $this->table, $name, $this->indexColumns, $this->indexUnique));
    }

    public function reviewDropIndex(string $name): void
    {
        $this->review(__('Drop the index :name', ['name' => $name]), $this->dialect()->dropIndex((string) $this->table, $name));
    }

    public function reviewTruncate(string $table): void
    {
        $this->review(__('Empty :name', ['name' => $table]), $this->dialect()->truncate($table));
    }

    public function reviewDrop(string $table): void
    {
        $type = collect($this->objects())->firstWhere('name', $table)['type'] ?? 'table';
        $this->table = $table;
        $this->review(__('Drop :name', ['name' => $table]), $this->dialect()->dropTable($table, (string) $type));
    }

    public function reviewRename(string $table, string $to): void
    {
        if (trim($to) === '' || $to === $table) {
            return;
        }

        $this->renaming = $table;
        $this->renameTo = trim($to);
        $this->table = $table;
        $this->review(__('Rename :name', ['name' => $table]), $this->dialect()->renameTable($table, trim($to)));
    }

    public function reviewNewDatabase(string $name): void
    {
        if (trim($name) === '') {
            return;
        }

        $this->review(__('New database :name', ['name' => trim($name)]), $this->dialect()->createDatabase(trim($name)));
    }

    public function reviewDropDatabase(string $name): void
    {
        $this->review(__('Drop the database :name', ['name' => $name]), $this->dialect()->dropDatabase($name));
    }

    /* ------------------------------------------------------------------ SQL editor */

    public function updatedSql(): void
    {
        session()->put($this->draftKey(), mb_substr($this->sql, 0, 200000));
    }

    private function draftKey(): string
    {
        return 'databases.draft.'.$this->connection->id;
    }

    /**
     * Run what is in the editor, or the part of it selected. Writes on a connection that may
     * only be read are refused; on production, locked, refused, unlocked, asked about first.
     *
     * @return array{confirm?: string}|null
     */
    public function runSql(?string $text = null, bool $confirmed = false): ?array
    {
        $text = $text !== null && trim($text) !== '' ? $text : $this->sql;
        $statements = Statements::split($text, $this->family());

        if ($statements === []) {
            Flux::toast(variant: 'warning', text: __('Nothing to run.'));

            return null;
        }

        $writes = array_values(array_filter($statements, fn (string $s) => ! Statements::isRead($s, $this->family())));

        if ($writes !== []) {
            if (! $this->canWrite()) {
                $this->store([Result::failed($writes[0], __('You may only read this connection: only SELECT, SHOW, EXPLAIN and the like run here.'))]);

                return null;
            }

            if ($this->isLocked()) {
                $this->store([Result::failed($writes[0], __('This is a production database: unlock changes first (the lock at the top) to run statements that write.'))]);

                return null;
            }

            if ($this->connection->environment->isProduction() && ! $confirmed) {
                return ['confirm' => trans_choice(':count statement changes data on :name (production). Run it?|:count statements change data on :name (production). Run them?', count($writes), ['count' => count($writes), 'name' => $this->connection->name])];
            }
        }

        $started = hrtime(true);

        try {
            $results = $this->link()->script($statements, (int) config('studio.databases.row_limit', 1000), readOnly: ! $this->canWrite());
        } catch (Throwable $e) {
            $results = [Result::failed($statements[0], DatabaseException::from($e)->getMessage())];
        }

        $failed = collect($results)->first(fn (Result $r) => $r->error !== null);
        DatabaseQuery::record($this->connection, auth()->user(), $this->db, $text, $failed?->error, (int) round((hrtime(true) - $started) / 1e6));

        if ($writes !== []) {
            $this->forget();
            $this->audit('sql', ['statements' => count($writes)]);
        }

        // USE another_database: the client follows
        foreach ($results as $result) {
            if ($result->error === null && $this->family() === 'mysql' && preg_match('/^\s*USE\s+`?([^`;\s]+)`?\s*;?\s*$/i', $result->statement, $m)) {
                $this->db = $m[1];
            }
        }

        $this->store($results);

        return null;
    }

    /** @param  list<Result>  $results */
    private function store(array $results): void
    {
        $this->tab = 'sql';
        $this->resultsKey = 'db:results:'.auth()->id().':'.Str::random(20);
        Cache::put($this->resultsKey, array_map(fn (Result $r) => $r->toArray(), $results), now()->addHours(2));
    }

    public function loadQuery(string $id): void
    {
        $query = $this->connection->queries()->where('user_id', auth()->id())->where('uuid', $id)->first();

        if ($query !== null) {
            $this->sql = $query->sql;
            $this->updatedSql();
            $this->tab = 'sql';
        }
    }

    public function saveQuery(): void
    {
        $this->validate(['saveTitle' => ['required', 'string', 'max:120'], 'sql' => ['required', 'string']]);

        DatabaseQuery::query()->create([
            'database_connection_id' => $this->connection->id,
            'user_id' => auth()->id(),
            'database' => $this->db,
            'sql' => $this->sql,
            'title' => trim($this->saveTitle),
            'saved' => true,
        ]);

        $this->saveTitle = '';
        Flux::modal('db-save-query')->close();
        Flux::toast(variant: 'success', text: __('Query saved.'));
    }

    public function forgetQuery(string $id): void
    {
        $this->connection->queries()->where('user_id', auth()->id())->where('uuid', $id)->delete();
    }

    public function clearHistory(): void
    {
        $this->connection->queries()->where('user_id', auth()->id())->where('saved', false)->delete();
    }

    /** The rows of the last statement that gave some, all of them, to a file. */
    public function exportResult(string $format): void
    {
        $results = $this->resultsKey ? Cache::get($this->resultsKey, []) : [];
        $last = collect(is_array($results) ? $results : [])->reverse()->first(fn ($r) => is_array($r) && ($r['columns'] ?? []) !== [] && ($r['error'] ?? null) === null);

        if ($last === null) {
            Flux::toast(variant: 'warning', text: __('Run a query that returns rows first.'));

            return;
        }

        $this->queueTransfer('export', in_array($format, ['csv', 'json'], true) ? $format : 'csv', ['database' => $this->db, 'schema' => $this->schema, 'query' => $last['statement']]);
        $this->tab = 'transfers';
        Flux::toast(text: __('Export started: it is in Import & export.'));
    }

    /* ------------------------------------------------------------------ imports and exports */

    public function exportTable(string $table, string $format = 'sql'): void
    {
        $this->exportTables = [$table];
        $this->exportFormat = $format;
        $this->tab = 'transfers';
    }

    public function startExport(): void
    {
        $this->validate([
            'exportFormat' => ['required', 'in:sql,csv,json'],
            'exportTables' => [$this->exportFormat === 'sql' ? 'nullable' : 'required', 'array'],
            'exportEngine' => ['required', 'in:auto,native,studio'],
        ], ['exportTables.required' => __('Choose the tables to export.')]);

        if ($this->family() !== 'sqlite' && blank($this->db)) {
            Flux::toast(variant: 'warning', text: __('Choose a database first.'));

            return;
        }

        $this->queueTransfer('export', $this->exportFormat, [
            'database' => $this->db,
            'schema' => $this->schema,
            'tables' => $this->exportTables,
            'structure' => $this->exportStructure,
            'data' => $this->exportData,
            'drop' => $this->exportDrop,
            'gzip' => $this->exportGzip,
            'engine' => $this->exportEngine,
        ]);

        Flux::toast(variant: 'success', text: __('Export started.'));
    }

    public function updatedImportFile(): void
    {
        $name = strtolower((string) $this->importFile?->getClientOriginalName());
        $this->importFormat = match (true) {
            str_contains($name, '.csv') || str_contains($name, '.tsv') => 'csv',
            str_contains($name, '.json') => 'json',
            default => 'sql',
        };
        $this->importTable ??= $this->table;
    }

    public function startImport(): void
    {
        try {
            $this->ensureWritable();
        } catch (Throwable $e) {
            $this->fail($e);

            return;
        }

        $drive = $this->importDrive !== null ? $this->driveFiles(['sql', 'gz', 'csv', 'tsv', 'json'])->firstWhere('uuid', $this->importDrive) : null;

        $this->validate([
            'importFile' => [$drive === null ? 'required' : 'nullable', 'file', 'max:'.((int) config('studio.databases.max_import_mb', 512) * 1024)],
            'importFormat' => ['required', 'in:sql,csv,json'],
            'importTable' => [$this->importFormat === 'sql' ? 'nullable' : 'required', 'nullable', 'string'],
            'importDelimiter' => ['required', Rule::in(['auto', ',', ';', '\t', '|'])],
        ], ['importFile.required' => __('Choose a file, or one of your drive.')]);

        if ($this->family() !== 'sqlite' && blank($this->db)) {
            Flux::toast(variant: 'warning', text: __('Choose a database first.'));

            return;
        }

        $this->queueTransfer('import', $this->importFormat, [
            'database' => $this->db,
            'schema' => $this->schema,
            'table' => $this->importFormat === 'sql' ? null : $this->importTable,
            'header' => $this->importHeader,
            'truncate' => $this->importTruncate,
            'continue' => $this->importContinue,
            'empty_null' => $this->importEmptyNull,
            'delimiter' => $this->importDelimiter,
        ], $this->importFile, $drive);

        $this->forget();
        $this->reset(['importFile', 'importDrive', 'importTruncate', 'importContinue']);
        Flux::toast(variant: 'success', text: __('Import started.'));
    }

    /** @param  array<string, mixed>  $context */
    private function audit(string $action, array $context = []): void
    {
        if (! $this->connection->isOwnedBy(auth()->user()) || $this->connection->environment->isProduction()) {
            Log::info('database change', ['user' => auth()->user()->email, 'connection' => $this->connection->name, 'database' => $this->db, 'table' => $this->table, 'action' => $action, ...$context]);
        }
    }

    public function render(): View
    {
        $data = [
            'error' => null, 'databases' => [], 'schemas' => [], 'objects' => [], 'object' => null,
            'grid' => null, 'count' => null, 'columns' => [], 'indexes' => [], 'constraints' => [], 'ddl' => null,
        ];

        try {
            $data['databases'] = $this->family() === 'sqlite' ? [] : $this->databases();
            $data['schemas'] = $this->schemas();

            if ($this->family() === 'sqlite' || filled($this->db)) {
                $data['objects'] = $this->objects();
            }

            if ($this->table !== null) {
                $data['object'] = collect($data['objects'])->firstWhere('name', $this->table);

                if ($data['object'] === null) {
                    $this->table = null;
                } else {
                    $data['columns'] = $this->columns($this->table);

                    if ($this->tab === 'data') {
                        $data['grid'] = $this->dialect()->browse($this->table, $this->filters, $this->sort, $this->direction, $this->perPage, ($this->page - 1) * $this->perPage);
                        $data['count'] = $this->dialect()->count($this->table, $this->filters);
                    } elseif ($this->tab === 'structure') {
                        $data['indexes'] = $this->dialect()->indexes($this->table);
                        $data['constraints'] = $this->dialect()->constraints($this->table);
                    } elseif ($this->tab === 'ddl') {
                        $data['ddl'] = $this->dialect()->ddl($this->table, (string) $data['object']['type']);
                    }
                }
            }
        } catch (Throwable $e) {
            $data['error'] = DatabaseException::from($e)->getMessage();
        }

        $user = auth()->user();

        return view('livewire.tools.database-client', [
            ...$data,
            'canWrite' => $this->canWrite(),
            'locked' => $this->isLocked(),
            'results' => $this->resultsKey ? Cache::get($this->resultsKey, []) : [],
            'history' => $this->tab === 'sql' ? $this->connection->queries()->where('user_id', $user->id)->where('saved', false)->latest('id')->limit(50)->get() : collect(),
            'saved' => $this->tab === 'sql' ? $this->connection->queries()->where('user_id', $user->id)->where('saved', true)->orderBy('title')->get() : collect(),
            'transfers' => $this->tab === 'transfers' ? $this->transfersList() : collect(),
            'drive' => $this->tab === 'transfers' ? $this->driveFiles(['sql', 'gz', 'csv', 'tsv', 'json']) : collect(),
            'types' => $this->safeTypes(),
            'operators' => Dialect::OPERATORS,
        ])->title($this->connection->name.' · '.__('Databases'));
    }

    /** @return list<string> */
    private function safeTypes(): array
    {
        return match ($this->family()) {
            'mysql' => (new Mysql($this->linkless()))->types(),
            'pgsql' => (new Postgres($this->linkless()))->types(),
            default => (new Sqlite($this->linkless()))->types(),
        };
    }

    /** A link that opens nothing, for what a dialect knows without asking the database. */
    private function linkless(): Link
    {
        return new PdoLink(null, $this->connection->driver);
    }
}
