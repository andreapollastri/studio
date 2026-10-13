<?php

namespace App\Databases;

use PDOException;
use RuntimeException;
use Throwable;

/** Why a database could not be reached or refused a statement, in words a page can show. */
class DatabaseException extends RuntimeException
{
    public static function from(Throwable $e): self
    {
        if ($e instanceof self) {
            return $e;
        }

        $message = $e->getMessage();

        // "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'x' doesn't exist" → what the server said
        if ($e instanceof PDOException && preg_match('/^SQLSTATE\[[^\]]+\]:?\s*(?:\[\d+\]\s*)?(?:[^:]+:\s*\d+\s+)?(.*)$/s', $message, $m) && trim($m[1]) !== '') {
            $message = trim($m[1]);
        }

        return new self($message, (int) $e->getCode() === 0 ? 0 : (is_numeric($e->getCode()) ? (int) $e->getCode() : 0), $e);
    }
}
