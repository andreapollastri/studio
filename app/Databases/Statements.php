<?php

namespace App\Databases;

/**
 * SQL as people write it, cut into statements: quotes, identifiers, comments, PostgreSQL's
 * dollar quoting, MySQL's DELIMITER and SQLite's triggers are respected. Fed in chunks it
 * splits a dump of any size as it is read.
 *
 * It also tells a statement that only reads from one that may write, for the connections a
 * person may only read: the keywords outside strings and comments decide.
 */
final class Statements
{
    private string $pending = '';

    private string $buffer = '';

    /** none, squote, dquote, btick, line, block, dollar */
    private string $mode = 'none';

    private string $tag = '';

    private string $delimiter = ';';

    /** Nothing but blanks and comments in the statement so far: where a DELIMITER line may stand. */
    private bool $fresh = true;

    public function __construct(private readonly string $family) {}

    /** @return list<string> */
    public static function split(string $sql, string $family): array
    {
        $splitter = new self($family);

        return [...$splitter->feed($sql), ...$splitter->finish()];
    }

    /**
     * The statements this chunk completes.
     *
     * @return list<string>
     */
    public function feed(string $chunk): array
    {
        $this->pending .= $chunk;

        return $this->consume(false);
    }

    /** @return list<string> */
    public function finish(): array
    {
        $out = $this->consume(true);
        $last = $this->emit();

        return $last === null ? $out : [...$out, $last];
    }

    /** @return list<string> */
    private function consume(bool $final): array
    {
        $out = [];
        $text = $this->pending;
        $length = strlen($text);
        // what a token may need to look ahead: a dollar tag, a doubled quote, a delimiter
        $guard = $final ? 0 : 80;
        $i = 0;

        while ($i < $length - $guard) {
            if ($this->mode === 'none') {
                if ($this->family === 'mysql' && $this->fresh && preg_match('/\G[ \t\r\n]*DELIMITER[ \t]+(\S+)[^\n]*(\n|$)/i', $text, $m, 0, $i)) {
                    if ($m[2] === '' && ! $final) {
                        break;
                    }
                    $this->delimiter = $m[1];
                    $this->buffer = '';
                    $this->fresh = true;
                    $i += strlen($m[0]);

                    continue;
                }

                $stops = "'\"`-#/\$[".$this->delimiter[0];
                $span = strcspn($text, $stops, $i);
                if ($span > 0) {
                    $take = min($span, $length - $i);
                    $plain = substr($text, $i, $take);
                    $this->buffer .= $plain;
                    $this->fresh = $this->fresh && trim($plain) === '';
                    $i += $take;

                    continue;
                }

                $c = $text[$i];

                if (substr_compare($text, $this->delimiter, $i, strlen($this->delimiter)) === 0) {
                    if ($this->family === 'sqlite' && $this->insideTrigger()) {
                        $this->buffer .= $this->delimiter;
                    } elseif (($statement = $this->emit()) !== null) {
                        $out[] = $statement;
                    }
                    $i += strlen($this->delimiter);

                    continue;
                }

                $next = $text[$i + 1] ?? '';
                [$mode, $width] = match (true) {
                    $c === "'" => ['squote', 1],
                    $c === '"' => ['dquote', 1],
                    $c === '`' && $this->family !== 'pgsql' => ['btick', 1],
                    $c === '[' && $this->family === 'sqlite' => ['bracket', 1],
                    $c === '-' && $next === '-' => ['line', 2],
                    $c === '#' && $this->family === 'mysql' => ['line', 1],
                    $c === '/' && $next === '*' => ['block', 2],
                    default => ['none', 1],
                };

                if ($c === '$' && $this->family === 'pgsql' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $text, $m, 0, $i)) {
                    [$mode, $width, $this->tag] = ['dollar', strlen($m[0]), $m[0]];
                }

                $this->mode = $mode;
                $this->fresh = $this->fresh && in_array($mode, ['line', 'block'], true);
                $this->buffer .= substr($text, $i, $width);
                $i += $width;

                continue;
            }

            $i = $this->inside($text, $i, $length, $final);
        }

        $this->pending = (string) substr($text, $i);

        return $out;
    }

    /** Within a string, an identifier or a comment: up to where it ends. */
    private function inside(string $text, int $i, int $length, bool $final): int
    {
        $backslash = $this->family === 'mysql' && in_array($this->mode, ['squote', 'dquote'], true);

        $end = match ($this->mode) {
            'squote' => "'",
            'dquote' => '"',
            'btick' => '`',
            'bracket' => ']',
            'line' => "\n",
            'block' => '*/',
            default => $this->tag,
        };

        $stops = $end[0].($backslash ? '\\' : '');
        $span = strcspn($text, $stops, $i);
        $this->buffer .= substr($text, $i, $span);
        $i += $span;

        if ($i >= $length || (! $final && $i + max(2, strlen($end)) > $length)) {
            return $i;
        }

        if ($backslash && $text[$i] === '\\') {
            $this->buffer .= substr($text, $i, 2);

            return $i + 2;
        }

        if (substr_compare($text, $end, $i, strlen($end)) !== 0) {
            $this->buffer .= $text[$i];

            return $i + 1;
        }

        // a doubled quote is the quote itself
        if (in_array($this->mode, ['squote', 'dquote', 'btick'], true) && ($text[$i + 1] ?? '') === $end) {
            $this->buffer .= $end.$end;

            return $i + 2;
        }

        $this->buffer .= $end;
        $this->mode = 'none';

        return $i + strlen($end);
    }

    /** SQLite's CREATE TRIGGER … BEGIN …; …; END: its semicolons inside belong to it. */
    private function insideTrigger(): bool
    {
        return (bool) preg_match('/^\s*CREATE\s+(TEMP\s+|TEMPORARY\s+)?TRIGGER\b/i', $this->buffer)
            && ! preg_match('/\bEND\s*$/i', $this->buffer);
    }

    private function emit(): ?string
    {
        $statement = trim($this->buffer);
        $this->buffer = '';
        $this->fresh = true;

        return self::stripComments($statement, $this->family) === '' ? null : $statement;
    }

    public static function stripComments(string $sql, string $family = 'mysql'): string
    {
        $sql = (string) preg_replace('~/\*.*?\*/~s', ' ', $sql);
        $sql = (string) preg_replace($family === 'mysql' ? '/(--|#)[^\n]*/' : '/--[^\n]*/', ' ', $sql);

        return trim($sql);
    }

    /**
     * The words of a statement outside its strings, quoted names and comments, upper-cased.
     *
     * @return list<string>
     */
    public static function keywords(string $sql, string $family = 'mysql'): array
    {
        $bare = (string) preg_replace([
            '~/\*.*?\*/~s',
            $family === 'mysql' ? '/(--|#)[^\n]*/' : '/--[^\n]*/',
            "/'(?:[^'\\\\]|\\\\.|'')*'/s",
            '/"(?:[^"\\\\]|\\\\.|"")*"/s',
            '/`(?:[^`]|``)*`/s',
            '/\$([A-Za-z_][A-Za-z0-9_]*)?\$.*?\$\1\$/s',
        ], ' ', $sql);

        preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $bare, $m);

        return array_map('strtoupper', $m[0]);
    }

    /** Only reads: what a connection someone may only read lets through. */
    public static function isRead(string $sql, string $family): bool
    {
        $words = self::keywords($sql, $family);
        $first = $words[0] ?? '';

        if (! in_array($first, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'VALUES', 'TABLE', 'WITH', 'PRAGMA', 'HELP', 'USE'], true)) {
            return false;
        }

        $writes = ['INSERT', 'UPDATE', 'DELETE', 'MERGE', 'REPLACE', 'INTO', 'CREATE', 'DROP', 'ALTER', 'TRUNCATE', 'GRANT', 'REVOKE', 'CALL', 'DO', 'LOAD', 'COPY', 'ATTACH', 'DETACH', 'VACUUM', 'REINDEX', 'LOCK'];

        if (array_intersect($words, $writes) !== []) {
            return false;
        }

        // EXPLAIN ANALYZE runs the statement; a PRAGMA with a value sets it
        return ! ($first === 'EXPLAIN' && in_array('ANALYZE', $words, true))
            && ! ($first === 'PRAGMA' && str_contains(self::stripComments($sql, $family), '='));
    }

    /** A statement that returns rows when it runs, to cap them: SELECT, VALUES, TABLE, WITH … SELECT. */
    public static function isQuery(string $sql, string $family): bool
    {
        $words = self::keywords($sql, $family);
        $first = $words[0] ?? '';

        if ($first === 'WITH') {
            return array_intersect($words, ['INSERT', 'UPDATE', 'DELETE', 'MERGE']) === [];
        }

        return in_array($first, ['SELECT', 'VALUES', 'TABLE'], true) && ! in_array('INTO', $words, true) && ! in_array('FOR', $words, true);
    }

    /**
     * Placeholders replaced by literals, for a database reached without a driver that binds
     * (SQLite through its shell): the question marks outside strings and quoted names.
     *
     * @param  list<mixed>  $bindings
     * @param  callable(mixed): string  $quote
     */
    public static function bind(string $sql, array $bindings, callable $quote): string
    {
        if ($bindings === []) {
            return $sql;
        }

        $out = '';
        $quoteChar = null;
        $n = 0;

        for ($i = 0, $length = strlen($sql); $i < $length; $i++) {
            $c = $sql[$i];

            if ($quoteChar !== null) {
                $out .= $c;
                if ($c === $quoteChar) {
                    $quoteChar = null;
                }

                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $quoteChar = $c;
                $out .= $c;

                continue;
            }

            $out .= $c === '?' && array_key_exists($n, $bindings) ? $quote($bindings[$n++]) : $c;
        }

        return $out;
    }
}
