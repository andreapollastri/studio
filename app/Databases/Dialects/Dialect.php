<?php

namespace App\Databases\Dialects;

use App\Databases\DatabaseException;
use App\Databases\Links\Link;
use App\Databases\Result;

/**
 * What differs between MySQL, PostgreSQL and SQLite for a client: where the catalog is,
 * how a table is named in full, how a row is found again to change it, how a table is
 * created or changed. Built on a link, for one database (and one schema on PostgreSQL).
 */
abstract class Dialect
{
    public const OPERATORS = ['=', '!=', '<', '>', '<=', '>=', 'contains', 'not contains', 'starts', 'ends', 'in', 'null', 'not null', 'empty', 'sql'];

    public function __construct(
        public readonly Link $link,
        public readonly ?string $database = null,
        public readonly ?string $schema = null,
    ) {}

    public static function for(Link $link, ?string $database, ?string $schema = null): self
    {
        return match ($link->driver()->family()) {
            'mysql' => new Mysql($link, $database),
            'pgsql' => new Postgres($link, $database, $schema ?: 'public'),
            default => new Sqlite($link, $database),
        };
    }

    /** @return list<string> */
    abstract public function databases(): array;

    /** @return list<string> */
    public function schemas(): array
    {
        return [];
    }

    /** @return list<array{name: string, type: string, rows: int|null, size: int|null, comment: string|null}> */
    abstract public function objects(): array;

    /** @return list<array{name: string, type: string, nullable: bool, default: string|null, key: string|null, extra: string|null, comment: string|null}> */
    abstract public function columns(string $table): array;

    /** @return list<string> */
    abstract public function primaryKey(string $table): array;

    /** @return list<array{name: string, columns: string, unique: bool, primary: bool, definition: string|null}> */
    abstract public function indexes(string $table): array;

    /** @return list<array{name: string, type: string, definition: string}> */
    abstract public function constraints(string $table): array;

    abstract public function ddl(string $table, string $type): string;

    /** The table with its database or schema in front. */
    abstract public function qualified(string $table): string;

    /** The column that finds a row again when the table has no primary key: PostgreSQL's ctid, SQLite's rowid. */
    public function rowLocator(string $table): ?string
    {
        return null;
    }

    /** @return list<string> types to suggest in the forms */
    abstract public function types(): array;

    public function ident(string $name): string
    {
        return $this->link->ident($name);
    }

    /**
     * A page of a table: its filters, its order, the rows that identify each row.
     *
     * @param  list<array{column: string, op: string, value: string|null}>  $filters
     * @return array{result: Result, keys: list<string>, locator: string|null}
     */
    public function browse(string $table, array $filters, ?string $sort, string $direction, int $limit, int $offset): array
    {
        [$where, $bindings] = $this->where($table, $filters);
        $keys = $this->primaryKey($table);
        $locator = $keys === [] ? $this->rowLocator($table) : null;
        $select = $locator !== null ? $this->locatorSelect($locator).', *' : '*';
        $order = $sort !== null ? ' ORDER BY '.$this->ident($sort).($direction === 'desc' ? ' DESC' : ' ASC') : ($keys !== [] ? ' ORDER BY '.implode(', ', array_map($this->ident(...), $keys)) : '');

        $sql = 'SELECT '.$select.' FROM '.$this->qualified($table).$where.$order.' LIMIT '.max(1, $limit).' OFFSET '.max(0, $offset);

        return ['result' => $this->link->select($sql, $bindings), 'keys' => $keys, 'locator' => $locator];
    }

    protected function locatorSelect(string $locator): string
    {
        return $locator.' AS '.$this->ident('__studio_row');
    }

    /**
     * How many rows match, counted no further than $cap.
     *
     * @param  list<array{column: string, op: string, value: string|null}>  $filters
     */
    public function count(string $table, array $filters, int $cap = 100000): int
    {
        [$where, $bindings] = $this->where($table, $filters);

        return (int) $this->link->select('SELECT COUNT(*) FROM (SELECT 1 FROM '.$this->qualified($table).$where.' LIMIT '.($cap + 1).') AS studio_count', $bindings)->value();
    }

    /**
     * @param  list<array{column: string, op: string, value: string|null}>  $filters
     * @return array{0: string, 1: list<mixed>}
     */
    public function where(string $table, array $filters): array
    {
        $clauses = [];
        $bindings = [];
        $known = array_column($this->columns($table), 'name');

        foreach ($filters as $filter) {
            $op = $filter['op'];
            $value = (string) ($filter['value'] ?? '');

            if ($op === 'sql') {
                if (trim($value) !== '') {
                    $clauses[] = '('.$value.')';
                }

                continue;
            }

            if (! in_array($filter['column'], $known, true) || ! in_array($op, self::OPERATORS, true)) {
                continue;
            }

            $column = $this->ident($filter['column']);
            $text = $this->asText($column);

            switch ($op) {
                case 'null':
                    $clauses[] = $column.' IS NULL';
                    break;
                case 'not null':
                    $clauses[] = $column.' IS NOT NULL';
                    break;
                case 'empty':
                    $clauses[] = '('.$column.' IS NULL OR '.$text." = '')";
                    break;
                case 'contains':
                case 'not contains':
                case 'starts':
                case 'ends':
                    $clauses[] = $text.($op === 'not contains' ? ' NOT ' : ' ').$this->like().' ?';
                    $bindings[] = ($op === 'starts' ? '' : '%').addcslashes($value, '%_\\').($op === 'ends' ? '' : '%');
                    break;
                case 'in':
                    $items = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
                    if ($items === []) {
                        break;
                    }
                    $clauses[] = $text.' IN ('.implode(', ', array_fill(0, count($items), '?')).')';
                    array_push($bindings, ...$items);
                    break;
                default:
                    $clauses[] = $this->comparable($column).' '.$op.' ?';
                    $bindings[] = $value;
            }
        }

        return [$clauses === [] ? '' : ' WHERE '.implode(' AND ', $clauses), $bindings];
    }

    protected function asText(string $column): string
    {
        return $column;
    }

    protected function comparable(string $column): string
    {
        return $column;
    }

    protected function like(): string
    {
        return 'LIKE';
    }

    /**
     * Where a row is: its primary key's values, or the locator's.
     *
     * @param  array<string, mixed>  $identity
     * @return array{0: string, 1: list<mixed>}
     */
    public function identify(string $table, array $identity): array
    {
        $keys = $this->primaryKey($table);

        if ($keys === []) {
            $locator = $this->rowLocator($table);
            if ($locator === null || ! array_key_exists('__studio_row', $identity)) {
                throw new DatabaseException(__('This table has no primary key: its rows cannot be told apart to change one. Use the SQL editor.'));
            }

            return [' WHERE '.$this->locatorWhere($locator), [$identity['__studio_row']]];
        }

        $clauses = [];
        $bindings = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $identity)) {
                throw new DatabaseException(__('The row has lost its key :key.', ['key' => $key]));
            }
            $clauses[] = $this->ident($key).' = ?';
            $bindings[] = $identity[$key];
        }

        return [' WHERE '.implode(' AND ', $clauses), $bindings];
    }

    protected function locatorWhere(string $locator): string
    {
        return $locator.' = ?';
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>|null
     */
    public function fetchRow(string $table, array $identity): ?array
    {
        [$where, $bindings] = $this->identify($table, $identity);

        return $this->link->select('SELECT * FROM '.$this->qualified($table).$where.' LIMIT 1', $bindings)->first();
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $values
     */
    public function update(string $table, array $identity, array $values): int
    {
        if ($values === []) {
            return 0;
        }

        [$where, $bindings] = $this->identify($table, $identity);
        $set = implode(', ', array_map(fn (string $column) => $this->ident($column).' = ?', array_keys($values)));

        return $this->link->affect('UPDATE '.$this->qualified($table).' SET '.$set.$where.$this->onlyOne(), [...array_values($values), ...$bindings]);
    }

    /** @param  array<string, mixed>  $identity */
    public function delete(string $table, array $identity): int
    {
        [$where, $bindings] = $this->identify($table, $identity);

        return $this->link->affect('DELETE FROM '.$this->qualified($table).$where.$this->onlyOne(), $bindings);
    }

    /** @param  array<string, mixed>  $values */
    public function insert(string $table, array $values): int
    {
        if ($values === []) {
            return $this->link->affect('INSERT INTO '.$this->qualified($table).' DEFAULT VALUES');
        }

        $columns = implode(', ', array_map($this->ident(...), array_keys($values)));

        return $this->link->affect('INSERT INTO '.$this->qualified($table).' ('.$columns.') VALUES ('.implode(', ', array_fill(0, count($values), '?')).')', array_values($values));
    }

    /**
     * Rows for an INSERT of an export, written for this database.
     *
     * @param  list<string>  $columns
     * @param  list<list<mixed>>  $rows
     */
    public function insertStatement(string $table, array $columns, array $rows, bool $qualified = false): string
    {
        $name = $qualified ? $this->qualified($table) : $this->ident($table);
        $values = implode(",\n", array_map(fn (array $row) => '('.implode(', ', array_map($this->link->quote(...), $row)).')', $rows));

        return 'INSERT INTO '.$name.' ('.implode(', ', array_map($this->ident(...), $columns)).") VALUES\n".$values.";\n";
    }

    protected function onlyOne(): string
    {
        return '';
    }

    /**
     * A new table, written from the form's columns.
     *
     * @param  list<array{name: string, type: string, nullable: bool, default: string|null, primary: bool, increments: bool}>  $columns
     */
    abstract public function createTable(string $name, array $columns): string;

    /** @param  array{name: string, type: string, nullable: bool, default: string|null, primary: bool, increments: bool}  $column */
    abstract public function columnDefinition(array $column): string;

    /** @param  array{name: string, type: string, nullable: bool, default: string|null, primary: bool, increments: bool}  $column */
    public function addColumn(string $table, array $column): string
    {
        return 'ALTER TABLE '.$this->qualified($table).' ADD COLUMN '.$this->columnDefinition($column).';';
    }

    public function dropColumn(string $table, string $column): string
    {
        return 'ALTER TABLE '.$this->qualified($table).' DROP COLUMN '.$this->ident($column).';';
    }

    public function renameColumn(string $table, string $from, string $to): string
    {
        return 'ALTER TABLE '.$this->qualified($table).' RENAME COLUMN '.$this->ident($from).' TO '.$this->ident($to).';';
    }

    /** @param  list<string>  $columns */
    public function createIndex(string $table, string $name, array $columns, bool $unique): string
    {
        return 'CREATE '.($unique ? 'UNIQUE ' : '').'INDEX '.$this->ident($name).' ON '.$this->qualified($table).' ('.implode(', ', array_map($this->ident(...), $columns)).');';
    }

    abstract public function dropIndex(string $table, string $name): string;

    public function renameTable(string $from, string $to): string
    {
        return 'ALTER TABLE '.$this->qualified($from).' RENAME TO '.$this->ident($to).';';
    }

    public function dropTable(string $table, string $type = 'table', bool $ifExists = false): string
    {
        return 'DROP '.($type === 'view' ? 'VIEW' : 'TABLE').($ifExists ? ' IF EXISTS' : '').' '.$this->qualified($table).';';
    }

    public function truncate(string $table): string
    {
        return 'TRUNCATE TABLE '.$this->qualified($table).';';
    }

    public function createDatabase(string $name): string
    {
        return 'CREATE DATABASE '.$this->ident($name).';';
    }

    public function dropDatabase(string $name): string
    {
        return 'DROP DATABASE '.$this->ident($name).';';
    }

    /** A default value as the form wrote it: a number, a known expression, or text in quotes. */
    protected function defaultClause(?string $default): string
    {
        if ($default === null || $default === '') {
            return '';
        }

        $upper = strtoupper(trim($default));

        if (is_numeric($default) || in_array($upper, ['NULL', 'CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME', 'NOW()', 'TRUE', 'FALSE'], true) || str_starts_with($default, '(') || str_starts_with($default, "'")) {
            return ' DEFAULT '.$default;
        }

        return ' DEFAULT '.$this->link->quote($default);
    }
}
