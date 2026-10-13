<?php

namespace App\Databases\Links;

use App\Databases\DatabaseException;
use App\Databases\Driver;
use App\Databases\Result;
use App\Databases\Statements;
use App\Databases\Values;
use Generator;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * MySQL, MariaDB, PostgreSQL, or SQLite on Studio's disk, through PDO. The rows a person's
 * statement returns are capped: MySQL's sql_select_limit, a LIMIT around PostgreSQL's
 * queries, and SQLite stepping no further than needed.
 */
final class PdoLink implements Link
{
    public function __construct(private ?PDO $pdo, private readonly Driver $driver) {}

    public function driver(): Driver
    {
        return $this->driver;
    }

    public function pdo(): PDO
    {
        return $this->pdo ?? throw new DatabaseException(__('The connection is closed.'));
    }

    public function select(string $sql, array $bindings = []): Result
    {
        try {
            $statement = $this->pdo()->prepare($sql);
            $statement->execute($bindings);

            return $this->rows($statement, 0);
        } catch (PDOException $e) {
            throw DatabaseException::from($e);
        }
    }

    public function affect(string $sql, array $bindings = []): int
    {
        try {
            $statement = $this->pdo()->prepare($sql);
            $statement->execute($bindings);

            return $statement->rowCount();
        } catch (PDOException $e) {
            throw DatabaseException::from($e);
        }
    }

    public function exec(string $sql): int
    {
        try {
            return (int) $this->pdo()->exec($sql);
        } catch (PDOException $e) {
            throw DatabaseException::from($e);
        }
    }

    public function script(array $statements, int $limit, bool $readOnly = false): array
    {
        $family = $this->driver->family();
        $results = [];

        if ($readOnly) {
            $this->begin(true);
        }

        try {
            foreach ($statements as $sql) {
                $started = hrtime(true);

                try {
                    $result = $this->one($sql, $limit, $family);
                    $results[] = $result->withTiming($sql, self::ms($started));
                } catch (Throwable $e) {
                    $results[] = Result::failed($sql, DatabaseException::from($e)->getMessage(), self::ms($started));

                    break;
                }
            }
        } finally {
            if ($readOnly) {
                $this->rollBack();
            }
        }

        return $results;
    }

    private function one(string $sql, int $limit, string $family): Result
    {
        $pdo = $this->pdo();

        if ($family === 'mysql') {
            $pdo->exec('SET SESSION sql_select_limit = '.($limit + 1));

            try {
                return $this->rows($this->query($sql), $limit);
            } finally {
                $pdo->exec('SET SESSION sql_select_limit = DEFAULT');
            }
        }

        if ($family === 'pgsql' && Statements::isQuery($sql, $family)) {
            // a savepoint lets the plain statement run when the wrapped one is refused
            $wrapped = 'SELECT * FROM ('.rtrim(trim($sql), ';').') AS studio_result LIMIT '.($limit + 1);
            $inside = $pdo->inTransaction();

            if ($inside) {
                $pdo->exec('SAVEPOINT studio_wrap');
            }

            try {
                $result = $this->rows($this->query($wrapped), $limit);
                if ($inside) {
                    $pdo->exec('RELEASE SAVEPOINT studio_wrap');
                }

                return $result;
            } catch (PDOException) {
                // refused wrapped: the statement runs as it is below
                if ($pdo->inTransaction()) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT studio_wrap');
                }
            }
        }

        return $this->rows($this->query($sql), $limit);
    }

    private function query(string $sql): PDOStatement
    {
        $statement = $this->pdo()->query($sql);

        return $statement === false ? throw new DatabaseException(__('The statement failed.')) : $statement;
    }

    private function rows(PDOStatement $statement, int $limit): Result
    {
        $count = $statement->columnCount();

        if ($count === 0) {
            return new Result(affected: $statement->rowCount());
        }

        $columns = [];
        for ($i = 0; $i < $count; $i++) {
            $meta = $statement->getColumnMeta($i);
            $columns[] = (string) ($meta['name'] ?? 'column '.($i + 1));
        }

        $rows = [];
        $truncated = false;

        while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            if ($limit > 0 && count($rows) >= $limit) {
                $truncated = true;

                break;
            }
            $rows[] = array_map(Values::scalar(...), $row);
        }

        $statement->closeCursor();

        return new Result($columns, $rows, truncated: $truncated);
    }

    public function cursor(string $sql): Generator
    {
        $pdo = $this->pdo();

        try {
            if ($this->driver->family() === 'pgsql') {
                // a cursor, so a table of any size never sits whole in memory
                $pdo->beginTransaction();
                $pdo->exec('DECLARE studio_export NO SCROLL CURSOR FOR '.rtrim(trim($sql), ';'));
                $first = true;

                do {
                    $batch = $this->query('FETCH 2000 FROM studio_export');
                    if ($first) {
                        yield $this->columnsOf($batch);
                        $first = false;
                    }
                    $n = 0;
                    while (($row = $batch->fetch(PDO::FETCH_NUM)) !== false) {
                        $n++;
                        yield array_map(Values::scalar(...), $row);
                    }
                } while ($n > 0);

                $pdo->exec('CLOSE studio_export');
                $pdo->commit();

                return;
            }

            if ($this->driver->family() === 'mysql') {
                $pdo->setAttribute(self::buffered(), false);
            }

            $statement = $this->query($sql);
            yield $this->columnsOf($statement);

            while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                yield array_map(Values::scalar(...), $row);
            }

            $statement->closeCursor();
        } catch (PDOException $e) {
            throw DatabaseException::from($e);
        } finally {
            if ($this->driver->family() === 'mysql') {
                $pdo->setAttribute(self::buffered(), true);
            }
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /** @return list<string> */
    private function columnsOf(PDOStatement $statement): array
    {
        $columns = [];
        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $columns[] = (string) ($statement->getColumnMeta($i)['name'] ?? 'column '.($i + 1));
        }

        return $columns;
    }

    public function begin(bool $readOnly = false): void
    {
        match (true) {
            ! $readOnly => $this->pdo()->beginTransaction(),
            $this->driver->family() === 'mysql' => $this->pdo()->exec('START TRANSACTION READ ONLY'),
            $this->driver->family() === 'pgsql' => $this->pdo()->exec('BEGIN READ ONLY'),
            default => $this->pdo()->exec('PRAGMA query_only = ON'),
        };
    }

    public function rollBack(): void
    {
        try {
            if ($this->driver->family() === 'sqlite') {
                if ($this->pdo()->inTransaction()) {
                    $this->pdo()->rollBack();
                }
                $this->pdo()->exec('PRAGMA query_only = OFF');

                return;
            }

            $this->pdo()->exec('ROLLBACK');
        } catch (Throwable) {
            // nothing was open any more
        }
    }

    public function quote(mixed $value): string
    {
        $value = Values::scalar($value);

        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $this->driver->family() === 'pgsql' ? ($value ? 'TRUE' : 'FALSE') : ($value ? '1' : '0'),
            is_int($value), is_float($value) => (string) $value,
            Values::isBinary($value) => match ($this->driver->family()) {
                'pgsql' => "'\\x".bin2hex((string) $value)."'::bytea",
                'mysql' => '0x'.bin2hex((string) $value),
                default => "X'".bin2hex((string) $value)."'",
            },
            default => (string) $this->pdo()->quote((string) $value),
        };
    }

    public function ident(string $name): string
    {
        return $this->driver->family() === 'mysql'
            ? '`'.str_replace('`', '``', $name).'`'
            : '"'.str_replace('"', '""', $name).'"';
    }

    public function close(): void
    {
        $this->pdo = null;
    }

    /** Pdo\Mysql's constant on PHP 8.4 and later, PDO's before. */
    private static function buffered(): int
    {
        return defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') ? (int) constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY') : (int) constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
    }

    private static function ms(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e6);
    }
}
