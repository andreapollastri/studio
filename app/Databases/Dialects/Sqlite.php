<?php

namespace App\Databases\Dialects;

/** SQLite: sqlite_master and the pragma functions, a row's rowid when it has no primary key. */
final class Sqlite extends Dialect
{
    public function databases(): array
    {
        return ['main'];
    }

    public function objects(): array
    {
        $rows = $this->link->select("SELECT name, type FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name")->rows;

        return array_map(fn (array $r) => ['name' => (string) $r[0], 'type' => (string) $r[1], 'rows' => null, 'size' => null, 'comment' => null], $rows);
    }

    public function columns(string $table): array
    {
        $rows = $this->link->select('SELECT name, type, "notnull", dflt_value, pk FROM pragma_table_info(?)', [$table])->rows;
        $unique = [];

        foreach ($this->indexes($table) as $index) {
            if ($index['unique'] && ! $index['primary'] && ! str_contains($index['columns'], ',')) {
                $unique[] = $index['columns'];
            }
        }

        return array_map(fn (array $r) => [
            'name' => (string) $r[0],
            'type' => (string) $r[1],
            'nullable' => ! (int) $r[2] && ! (int) $r[4],
            'default' => $r[3] === null ? null : (string) $r[3],
            'key' => (int) $r[4] > 0 ? 'primary' : (in_array($r[0], $unique, true) ? 'unique' : null),
            'extra' => (int) $r[4] > 0 && strtoupper((string) $r[1]) === 'INTEGER' && $this->autoincrements($table) ? 'autoincrement' : null,
            'comment' => null,
        ], $rows);
    }

    private function autoincrements(string $table): bool
    {
        return str_contains(strtoupper($this->ddl($table, 'table')), 'AUTOINCREMENT');
    }

    public function primaryKey(string $table): array
    {
        $rows = $this->link->select('SELECT name, pk FROM pragma_table_info(?) WHERE pk > 0 ORDER BY pk', [$table])->rows;

        return array_map(fn (array $r) => (string) $r[0], $rows);
    }

    public function indexes(string $table): array
    {
        $rows = $this->link->select('SELECT name, "unique", origin FROM pragma_index_list(?)', [$table])->rows;

        return array_map(function (array $r) {
            $columns = $this->link->select('SELECT name FROM pragma_index_info(?) ORDER BY seqno', [(string) $r[0]])->pluck(0);
            $sql = $this->link->select("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = ?", [(string) $r[0]])->value();

            return [
                'name' => (string) $r[0],
                'columns' => implode(', ', array_map('strval', $columns)),
                'unique' => (bool) $r[1],
                'primary' => $r[2] === 'pk',
                'definition' => $sql === null ? (string) $r[2] : (string) $sql,
            ];
        }, $rows);
    }

    public function constraints(string $table): array
    {
        $rows = $this->link->select('SELECT id, "table", "from", "to", on_update, on_delete FROM pragma_foreign_key_list(?) ORDER BY id, seq', [$table])->rows;
        $keys = [];

        foreach ($rows as $r) {
            $keys[$r[0]]['from'][] = $r[2];
            $keys[$r[0]]['to'][] = $r[3];
            $keys[$r[0]]['table'] = $r[1];
            $keys[$r[0]]['rules'] = "ON UPDATE {$r[4]} ON DELETE {$r[5]}";
        }

        return array_map(fn (array $k, int|string $id) => [
            'name' => 'fk_'.$id,
            'type' => 'foreign key',
            'definition' => '('.implode(', ', $k['from']).') → '.$k['table'].' ('.implode(', ', array_map('strval', $k['to'])).') '.$k['rules'],
        ], $keys, array_keys($keys));
    }

    public function ddl(string $table, string $type): string
    {
        $sql = $this->link->select("SELECT sql FROM sqlite_master WHERE tbl_name = ? AND sql IS NOT NULL ORDER BY type = 'table' DESC, type = 'view' DESC, name", [$table])->pluck(0);

        return implode(";\n\n", array_map('strval', $sql)).';';
    }

    public function qualified(string $table): string
    {
        return $this->ident($table);
    }

    public function rowLocator(string $table): ?string
    {
        return str_contains(strtoupper($this->ddl($table, 'table')), 'WITHOUT ROWID') ? null : 'rowid';
    }

    public function types(): array
    {
        return ['INTEGER', 'TEXT', 'REAL', 'NUMERIC', 'BLOB', 'VARCHAR(255)', 'BOOLEAN', 'DATETIME', 'DATE', 'JSON'];
    }

    public function columnDefinition(array $column): string
    {
        return $this->ident($column['name']).' '.$column['type']
            .($column['increments'] ? ' PRIMARY KEY AUTOINCREMENT' : '')
            .($column['nullable'] || $column['increments'] ? '' : ' NOT NULL')
            .($column['increments'] ? '' : $this->defaultClause($column['default']));
    }

    public function createTable(string $name, array $columns): string
    {
        $lines = array_map(fn (array $c) => '  '.$this->columnDefinition($c), $columns);
        $increments = array_filter($columns, fn (array $c) => $c['increments']);
        $primary = array_values(array_map(fn (array $c) => $this->ident($c['name']), array_filter($columns, fn (array $c) => $c['primary'] && ! $c['increments'])));

        if ($primary !== [] && $increments === []) {
            $lines[] = '  PRIMARY KEY ('.implode(', ', $primary).')';
        }

        return 'CREATE TABLE '.$this->qualified($name)." (\n".implode(",\n", $lines)."\n);";
    }

    public function dropIndex(string $table, string $name): string
    {
        return 'DROP INDEX '.$this->ident($name).';';
    }

    public function truncate(string $table): string
    {
        return 'DELETE FROM '.$this->qualified($table).';';
    }

    public function createDatabase(string $name): string
    {
        return '-- '.__('A SQLite database is a file: create it on the host, then add a connection to it.');
    }
}
