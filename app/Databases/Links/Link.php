<?php

namespace App\Databases\Links;

use App\Databases\Driver;
use App\Databases\Result;
use Generator;

/**
 * An open connection to a SQL database, for one request: through PDO, or through the
 * sqlite3 of another host over SSH. Studio's own statements (the catalog, the data grid)
 * go through select() and affect() with bindings; what a person typed goes through
 * script(), statement by statement, each with its rows or the rows it changed.
 */
interface Link
{
    public function driver(): Driver;

    /**
     * Rows of a statement Studio wrote, its values bound.
     *
     * @param  list<mixed>  $bindings
     */
    public function select(string $sql, array $bindings = []): Result;

    /**
     * A statement Studio wrote that changes something: the rows it changed.
     *
     * @param  list<mixed>  $bindings
     */
    public function affect(string $sql, array $bindings = []): int;

    /** A statement as it is, nothing bound (an import's): the rows it changed. */
    public function exec(string $sql): int;

    /**
     * Statements a person typed, in order, until one fails: rows (at most $limit, the
     * result says when there were more) or the rows changed. Read-only: inside a
     * transaction that cannot write, rolled back.
     *
     * @param  list<string>  $statements
     * @return list<Result>
     */
    public function script(array $statements, int $limit, bool $readOnly = false): array;

    /**
     * Every row of a query, as they come, for an export: the first value is the list of the
     * column names, then each row as a list.
     *
     * @return Generator<int, list<mixed>>
     */
    public function cursor(string $sql): Generator;

    /** A value as a literal of this database. */
    public function quote(mixed $value): string;

    /** The name of a table, column or database, quoted. */
    public function ident(string $name): string;

    public function close(): void;
}
