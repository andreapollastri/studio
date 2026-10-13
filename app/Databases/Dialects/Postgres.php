<?php

namespace App\Databases\Dialects;

/**
 * PostgreSQL: pg_catalog, a schema at a time (public by default), the ctid of a row when
 * the table has no primary key. A database is a connection of its own: switching one
 * reconnects.
 */
final class Postgres extends Dialect
{
    public function databases(): array
    {
        return array_map('strval', $this->link->select('SELECT datname FROM pg_database WHERE NOT datistemplate AND datallowconn ORDER BY datname')->pluck(0));
    }

    public function schemas(): array
    {
        return array_map('strval', $this->link->select("SELECT nspname FROM pg_namespace WHERE nspname NOT LIKE 'pg\\_%' AND nspname <> 'information_schema' ORDER BY nspname = 'public' DESC, nspname")->pluck(0));
    }

    public function objects(): array
    {
        $rows = $this->link->select(
            "SELECT c.relname, c.relkind, c.reltuples::bigint, CASE WHEN c.relkind IN ('r','m','p') THEN pg_total_relation_size(c.oid) END, obj_description(c.oid, 'pg_class')
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = ? AND c.relkind IN ('r', 'v', 'm', 'p', 'f') ORDER BY c.relname",
            [$this->schema],
        )->rows;

        return array_map(fn (array $r) => [
            'name' => (string) $r[0],
            'type' => in_array($r[1], ['v', 'm'], true) ? 'view' : 'table',
            'rows' => $r[2] === null || (int) $r[2] < 0 ? null : (int) $r[2],
            'size' => $r[3] === null ? null : (int) $r[3],
            'comment' => filled($r[4]) ? (string) $r[4] : null,
        ], $rows);
    }

    private function regclass(): string
    {
        return "format('%I.%I', ?::text, ?::text)::regclass";
    }

    public function columns(string $table): array
    {
        $keys = $this->primaryKey($table);
        $unique = $this->link->select(
            'SELECT a.attname FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) WHERE i.indrelid = '.$this->regclass().' AND i.indisunique AND NOT i.indisprimary',
            [$this->schema, $table],
        )->pluck(0);

        $rows = $this->link->select(
            'SELECT a.attname, format_type(a.atttypid, a.atttypmod), NOT a.attnotnull, pg_get_expr(d.adbin, d.adrelid), a.attidentity, a.attgenerated, col_description(a.attrelid, a.attnum)
             FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
             WHERE a.attrelid = '.$this->regclass().' AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum',
            [$this->schema, $table],
        )->rows;

        return array_map(fn (array $r) => [
            'name' => (string) $r[0],
            'type' => (string) $r[1],
            'nullable' => (bool) $r[2],
            'default' => $r[3] === null ? null : (string) $r[3],
            'key' => in_array($r[0], $keys, true) ? 'primary' : (in_array($r[0], $unique, true) ? 'unique' : null),
            'extra' => match (true) {
                $r[4] === 'a' => 'identity always',
                $r[4] === 'd' => 'identity',
                filled($r[5]) => 'generated',
                default => null,
            },
            'comment' => filled($r[6]) ? (string) $r[6] : null,
        ], $rows);
    }

    public function primaryKey(string $table): array
    {
        return array_map('strval', $this->link->select(
            'SELECT a.attname FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
             WHERE i.indrelid = '.$this->regclass().' AND i.indisprimary ORDER BY array_position(i.indkey::int2[], a.attnum)',
            [$this->schema, $table],
        )->pluck(0));
    }

    public function indexes(string $table): array
    {
        $rows = $this->link->select(
            'SELECT c.relname, i.indisunique, i.indisprimary, pg_get_indexdef(i.indexrelid),
                    (SELECT string_agg(a.attname, \', \' ORDER BY array_position(i.indkey::int2[], a.attnum)) FROM pg_attribute a WHERE a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey))
             FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE i.indrelid = '.$this->regclass().' ORDER BY i.indisprimary DESC, c.relname',
            [$this->schema, $table],
        )->rows;

        return array_map(fn (array $r) => [
            'name' => (string) $r[0],
            'columns' => (string) ($r[4] ?? ''),
            'unique' => (bool) $r[1],
            'primary' => (bool) $r[2],
            'definition' => (string) $r[3],
        ], $rows);
    }

    public function constraints(string $table): array
    {
        $rows = $this->link->select(
            'SELECT conname, contype, pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = '.$this->regclass()." AND contype IN ('f', 'c', 'u', 'x') ORDER BY contype, conname",
            [$this->schema, $table],
        )->rows;

        return array_map(fn (array $r) => [
            'name' => (string) $r[0],
            'type' => match ($r[1]) {
                'f' => 'foreign key',
                'c' => 'check',
                'u' => 'unique',
                default => 'exclusion',
            },
            'definition' => (string) $r[2],
        ], $rows);
    }

    public function ddl(string $table, string $type): string
    {
        if ($type === 'view') {
            $definition = (string) $this->link->select('SELECT pg_get_viewdef('.$this->regclass().', true)', [$this->schema, $table])->value();

            return 'CREATE OR REPLACE VIEW '.$this->qualified($table)." AS\n".rtrim($definition, "; \n").';';
        }

        $lines = array_map(fn (array $c) => '  '.$this->ident($c['name']).' '.$c['type']
            .($c['extra'] === 'identity' ? ' GENERATED BY DEFAULT AS IDENTITY' : ($c['extra'] === 'identity always' ? ' GENERATED ALWAYS AS IDENTITY' : ''))
            .($c['nullable'] ? '' : ' NOT NULL')
            .($c['default'] !== null ? ' DEFAULT '.$c['default'] : ''), $this->columns($table));

        $constraints = $this->link->select(
            'SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = '.$this->regclass()." ORDER BY contype = 'p' DESC, conname",
            [$this->schema, $table],
        )->rows;

        foreach ($constraints as $r) {
            $lines[] = '  CONSTRAINT '.$this->ident((string) $r[0]).' '.$r[1];
        }

        $sql = 'CREATE TABLE '.$this->qualified($table)." (\n".implode(",\n", $lines)."\n);";

        foreach ($this->indexes($table) as $index) {
            $owned = $this->link->select('SELECT 1 FROM pg_constraint WHERE conname = ? AND conrelid = '.$this->regclass(), [$index['name'], $this->schema, $table])->rows;
            if ($owned === []) {
                $sql .= "\n".$index['definition'].';';
            }
        }

        return $sql;
    }

    public function qualified(string $table): string
    {
        return $this->ident((string) $this->schema).'.'.$this->ident($table);
    }

    public function rowLocator(string $table): string
    {
        return 'ctid';
    }

    protected function locatorSelect(string $locator): string
    {
        return 'ctid::text AS '.$this->ident('__studio_row');
    }

    protected function locatorWhere(string $locator): string
    {
        return 'ctid = ?::tid';
    }

    protected function asText(string $column): string
    {
        return $column.'::text';
    }

    protected function comparable(string $column): string
    {
        return $column;
    }

    protected function like(): string
    {
        return 'ILIKE';
    }

    public function types(): array
    {
        return ['bigint', 'bigserial', 'integer', 'serial', 'smallint', 'numeric(10,2)', 'double precision', 'boolean', 'varchar(255)', 'text', 'uuid', 'jsonb', 'json', 'date', 'timestamp(0) without time zone', 'timestamptz', 'time', 'interval', 'bytea', 'inet', 'text[]'];
    }

    public function columnDefinition(array $column): string
    {
        return $this->ident($column['name']).' '.$column['type']
            .($column['increments'] ? ' GENERATED BY DEFAULT AS IDENTITY' : '')
            .($column['nullable'] ? '' : ' NOT NULL')
            .($column['increments'] ? '' : $this->defaultClause($column['default']));
    }

    public function createTable(string $name, array $columns): string
    {
        $lines = array_map(fn (array $c) => '  '.$this->columnDefinition($c), $columns);
        $primary = array_values(array_map(fn (array $c) => $this->ident($c['name']), array_filter($columns, fn (array $c) => $c['primary'] || $c['increments'])));

        if ($primary !== []) {
            $lines[] = '  PRIMARY KEY ('.implode(', ', $primary).')';
        }

        return 'CREATE TABLE '.$this->qualified($name)." (\n".implode(",\n", $lines)."\n);";
    }

    public function dropIndex(string $table, string $name): string
    {
        return 'DROP INDEX '.$this->ident((string) $this->schema).'.'.$this->ident($name).';';
    }

    public function truncate(string $table): string
    {
        return 'TRUNCATE TABLE '.$this->qualified($table).' RESTART IDENTITY;';
    }

    public function dropTable(string $table, string $type = 'table', bool $ifExists = false): string
    {
        return 'DROP '.($type === 'view' ? 'VIEW' : 'TABLE').($ifExists ? ' IF EXISTS' : '').' '.$this->qualified($table).';';
    }
}
