<?php

namespace App\Databases;

/**
 * What one statement gave back: its rows (so many at most, $truncated when there were more)
 * with their column names, or the rows it changed.
 */
final class Result
{
    /**
     * @param  list<string>  $columns
     * @param  list<list<mixed>>  $rows  each row's values, in the columns' order
     */
    public function __construct(
        public readonly array $columns = [],
        public readonly array $rows = [],
        public readonly ?int $affected = null,
        public readonly bool $truncated = false,
        public readonly string $statement = '',
        public readonly int $ms = 0,
        public readonly ?string $error = null,
    ) {}

    public function returnsRows(): bool
    {
        return $this->columns !== [];
    }

    /** @return list<array<string, mixed>> */
    public function assoc(): array
    {
        return array_map(fn (array $row) => array_combine($this->columns, $row), $this->rows);
    }

    /** @return array<string, mixed>|null */
    public function first(): ?array
    {
        return $this->assoc()[0] ?? null;
    }

    /** @return list<mixed> */
    public function pluck(int|string $column = 0): array
    {
        $index = is_int($column) ? $column : array_search($column, $this->columns, true);

        return $index === false ? [] : array_map(fn (array $row) => $row[$index] ?? null, $this->rows);
    }

    public function value(): mixed
    {
        return $this->rows[0][0] ?? null;
    }

    public function withTiming(string $statement, int $ms): self
    {
        return new self($this->columns, $this->rows, $this->affected, $this->truncated, $statement, $ms, $this->error);
    }

    public static function failed(string $statement, string $error, int $ms = 0): self
    {
        return new self(statement: $statement, ms: $ms, error: $error);
    }

    /**
     * Small enough to keep between two requests: long values cut, binary shown as such.
     *
     * @return array{columns: list<string>, rows: list<list<mixed>>, affected: int|null, truncated: bool, statement: string, ms: int, error: string|null}
     */
    public function toArray(int $cell = 5000): array
    {
        return [
            'columns' => $this->columns,
            'rows' => array_map(fn (array $row) => array_map(fn ($v) => Values::display($v, $cell), $row), $this->rows),
            'affected' => $this->affected,
            'truncated' => $this->truncated,
            'statement' => $this->statement,
            'ms' => $this->ms,
            'error' => $this->error,
        ];
    }
}
