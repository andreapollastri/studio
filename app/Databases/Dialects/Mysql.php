<?php

namespace App\Databases\Dialects;

/** MySQL and MariaDB: information_schema, SHOW CREATE, backticks. */
final class Mysql extends Dialect
{
    public function databases(): array
    {
        return array_map('strval', $this->link->select('SHOW DATABASES')->pluck(0));
    }

    public function objects(): array
    {
        if (blank($this->database)) {
            return [];
        }

        $rows = $this->link->select(
            'SELECT TABLE_NAME, TABLE_TYPE, TABLE_ROWS, DATA_LENGTH + INDEX_LENGTH, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
            [$this->database],
        )->rows;

        return array_map(fn (array $r) => [
            'name' => (string) $r[0],
            'type' => $r[1] === 'VIEW' ? 'view' : 'table',
            'rows' => $r[2] === null ? null : (int) $r[2],
            'size' => $r[3] === null ? null : (int) $r[3],
            'comment' => filled($r[4]) && $r[1] !== 'VIEW' ? (string) $r[4] : null,
        ], $rows);
    }

    public function columns(string $table): array
    {
        $rows = $this->link->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$this->database, $table],
        )->rows;

        return array_map(fn (array $r) => [
            'name' => (string) $r[0],
            'type' => (string) $r[1],
            'nullable' => $r[2] === 'YES',
            // MariaDB writes no default as NULL, a literal as 'NULL' in quotes
            'default' => $r[3] === null || ($r[3] === 'NULL' && $r[2] === 'YES') ? null : (string) $r[3],
            'key' => match ($r[4]) {
                'PRI' => 'primary',
                'UNI' => 'unique',
                'MUL' => 'index',
                default => null,
            },
            'extra' => filled($r[5]) ? (string) $r[5] : null,
            'comment' => filled($r[6]) ? (string) $r[6] : null,
        ], $rows);
    }

    public function primaryKey(string $table): array
    {
        return array_map('strval', $this->link->select(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX",
            [$this->database, $table],
        )->pluck(0));
    }

    public function indexes(string $table): array
    {
        $rows = $this->link->select(
            'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR \', \'), MIN(NON_UNIQUE), MAX(INDEX_TYPE) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? GROUP BY INDEX_NAME ORDER BY INDEX_NAME = \'PRIMARY\' DESC, INDEX_NAME',
            [$this->database, $table],
        )->rows;

        return array_map(fn (array $r) => [
            'name' => (string) $r[0],
            'columns' => (string) $r[1],
            'unique' => (int) $r[2] === 0,
            'primary' => $r[0] === 'PRIMARY',
            'definition' => (string) $r[3],
        ], $rows);
    }

    public function constraints(string $table): array
    {
        $rows = $this->link->select(
            'SELECT k.CONSTRAINT_NAME, GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION SEPARATOR \', \'), k.REFERENCED_TABLE_NAME, GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION SEPARATOR \', \'), MAX(r.UPDATE_RULE), MAX(r.DELETE_RULE)
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
             GROUP BY k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME ORDER BY k.CONSTRAINT_NAME',
            [$this->database, $table],
        )->rows;

        return array_map(fn (array $r) => [
            'name' => (string) $r[0],
            'type' => 'foreign key',
            'definition' => "({$r[1]}) → {$r[2]} ({$r[3]}) ON UPDATE {$r[4]} ON DELETE {$r[5]}",
        ], $rows);
    }

    public function ddl(string $table, string $type): string
    {
        $row = $this->link->select('SHOW CREATE '.($type === 'view' ? 'VIEW' : 'TABLE').' '.$this->qualified($table))->rows[0] ?? [];

        return rtrim((string) ($row[1] ?? ''), ';').';';
    }

    public function qualified(string $table): string
    {
        return (filled($this->database) ? $this->ident((string) $this->database).'.' : '').$this->ident($table);
    }

    protected function onlyOne(): string
    {
        return ' LIMIT 1';
    }

    public function types(): array
    {
        return ['bigint unsigned', 'int', 'tinyint(1)', 'smallint', 'decimal(10,2)', 'double', 'varchar(255)', 'char(36)', 'text', 'mediumtext', 'longtext', 'json', 'date', 'datetime', 'timestamp', 'time', 'year', 'blob', 'enum(\'a\',\'b\')', 'boolean'];
    }

    public function columnDefinition(array $column): string
    {
        return $this->ident($column['name']).' '.$column['type']
            .($column['nullable'] ? ' NULL' : ' NOT NULL')
            .($column['increments'] ? ' AUTO_INCREMENT' : $this->defaultClause($column['default']));
    }

    public function createTable(string $name, array $columns): string
    {
        $lines = array_map(fn (array $c) => '  '.$this->columnDefinition($c), $columns);
        $primary = array_values(array_map(fn (array $c) => $this->ident($c['name']), array_filter($columns, fn (array $c) => $c['primary'] || $c['increments'])));

        if ($primary !== []) {
            $lines[] = '  PRIMARY KEY ('.implode(', ', $primary).')';
        }

        return 'CREATE TABLE '.$this->qualified($name)." (\n".implode(",\n", $lines)."\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    public function dropIndex(string $table, string $name): string
    {
        return $name === 'PRIMARY'
            ? 'ALTER TABLE '.$this->qualified($table).' DROP PRIMARY KEY;'
            : 'DROP INDEX '.$this->ident($name).' ON '.$this->qualified($table).';';
    }

    public function renameTable(string $from, string $to): string
    {
        return 'RENAME TABLE '.$this->qualified($from).' TO '.$this->qualified($to).';';
    }

    public function createDatabase(string $name): string
    {
        return 'CREATE DATABASE '.$this->ident($name).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;';
    }
}
