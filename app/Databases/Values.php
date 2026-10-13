<?php

namespace App\Databases;

/**
 * How a value from a database reaches a page, a CSV or a JSON file: text as it is (cut when
 * asked), binary as a note of its size or as hex, resources read.
 */
final class Values
{
    public static function scalar(mixed $value): mixed
    {
        if (is_resource($value)) {
            $value = (string) stream_get_contents($value);
        }

        return $value;
    }

    public static function isBinary(mixed $value): bool
    {
        return is_string($value) && $value !== '' && ! mb_check_encoding($value, 'UTF-8');
    }

    /** null stays null; binary becomes "0x…" (cut) so a page and a JSON encoder can carry it. */
    public static function display(mixed $value, int $limit = 0): mixed
    {
        $value = self::scalar($value);

        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        $value = (string) $value;

        if (self::isBinary($value)) {
            $hex = '0x'.strtoupper(bin2hex($limit > 0 ? substr($value, 0, intdiv($limit, 2)) : $value));

            return $limit > 0 && strlen($value) * 2 > $limit ? $hex.'… ('.self::bytes(strlen($value)).')' : $hex;
        }

        return $limit > 0 && mb_strlen($value) > $limit ? mb_substr($value, 0, $limit).'…' : $value;
    }

    /** A value for a CSV cell: null as \N would surprise; it is empty, as most tools write it. */
    public static function csv(mixed $value): string
    {
        $value = self::display($value);

        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    public static function bytes(int|float|null $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return ($i === 0 ? (int) $bytes : number_format($bytes, $bytes < 10 ? 1 : 0)).' '.$units[$i];
    }
}
