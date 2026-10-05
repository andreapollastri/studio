<?php

namespace App\Server;

/**
 * Does an address belong to a whitelist of IPs and CIDR ranges? IPv4 and IPv6,
 * exact entries or `a.b.c.d/n`.
 */
final class IpMatcher
{
    /** @param  list<string>  $rules */
    public static function matchesAny(string $ip, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (self::matches($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $ip, string $rule): bool
    {
        $rule = trim($rule);
        $address = @inet_pton($ip);

        if ($address === false || $rule === '') {
            return false;
        }

        if (! str_contains($rule, '/')) {
            $target = @inet_pton($rule);

            return $target !== false && $target === $address;
        }

        [$network, $bits] = explode('/', $rule, 2);
        $base = @inet_pton(trim($network));

        if ($base === false || ! is_numeric($bits) || strlen($base) !== strlen($address)) {
            return false;
        }

        $bits = (int) $bits;
        $max = strlen($base) * 8;

        if ($bits < 0 || $bits > $max) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $rest = $bits % 8;

        if ($fullBytes > 0 && substr($address, 0, $fullBytes) !== substr($base, 0, $fullBytes)) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($address[$fullBytes]) & $mask) === (ord($base[$fullBytes]) & $mask);
    }

    /** Validate one whitelist entry as a person would type it. */
    public static function isValidRule(string $rule): bool
    {
        $rule = trim($rule);

        if ($rule === '') {
            return false;
        }

        if (! str_contains($rule, '/')) {
            return @inet_pton($rule) !== false;
        }

        [$network, $bits] = explode('/', $rule, 2);
        $base = @inet_pton(trim($network));

        return $base !== false && ctype_digit($bits) && (int) $bits <= strlen($base) * 8;
    }
}
