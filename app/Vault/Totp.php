<?php

namespace App\Vault;

use PragmaRX\Google2FA\Google2FA;
use Throwable;

/**
 * Two-factor codes for items of the password manager: the base32 secret of
 * an authenticator setup, or the otpauth:// URI its QR code carries.
 */
final class Totp
{
    /** The base32 secret in a pasted value, or null when there is none to use. */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (str_starts_with(strtolower($value), 'otpauth://')) {
            parse_str((string) parse_url($value, PHP_URL_QUERY), $query);
            $value = is_string($query['secret'] ?? null) ? $query['secret'] : '';
        }

        $secret = strtoupper((string) preg_replace('/[\s-]+/', '', $value));

        return preg_match('/^[A-Z2-7]{16,}=*$/', $secret) ? rtrim($secret, '=') : null;
    }

    /** @return array{code: string, remaining: int}|null */
    public static function now(?string $secret): ?array
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        try {
            $code = (new Google2FA)->getCurrentOtp($secret);
        } catch (Throwable) {
            return null;
        }

        return ['code' => $code, 'remaining' => 30 - (time() % 30)];
    }
}
