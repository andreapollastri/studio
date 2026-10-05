<?php

namespace App\Server;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Proof, on a protected project host, that the visitor is a Studio member.
 *
 * Studio's own session cookie never leaves the Studio host: a project app runs
 * code written by whoever works on it, and must not see a cookie that opens
 * Studio. Instead, Studio hands the browser a one-time token in a redirect to
 * the project host, and the host's gatekeeper swaps it for a pass cookie that
 * is valid for that host only.
 */
final class SitePass
{
    public const COOKIE = 'studio_site_pass';

    /** The path, on a project host, where the gatekeeper turns a token into a pass. */
    public const CALLBACK = '/.studio-auth';

    private const TOKEN_TTL = 120;

    private const PASS_TTL = 12 * 3600;

    public function mintToken(User $user, string $host): string
    {
        return $this->seal(['u' => $user->id, 'h' => strtolower($host), 'e' => now()->getTimestamp() + self::TOKEN_TTL, 'n' => Str::random(24)]);
    }

    /** The user a token stands for, once: a second use, another host or an old token give null. */
    public function redeemToken(string $token, string $host): ?User
    {
        $data = $this->open($token);

        if ($data === null || ($data['h'] ?? null) !== strtolower($host) || (int) ($data['e'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        if (! Cache::add('site-pass-token:'.(string) ($data['n'] ?? ''), true, self::TOKEN_TTL * 2)) {
            return null;
        }

        return User::query()->find((int) ($data['u'] ?? 0));
    }

    public function passFor(User $user, string $host): string
    {
        return $this->seal(['u' => $user->id, 'h' => strtolower($host), 'e' => now()->getTimestamp() + self::PASS_TTL]);
    }

    public function userFromPass(?string $pass, string $host): ?User
    {
        $data = $pass ? $this->open($pass) : null;

        if ($data === null || ($data['h'] ?? null) !== strtolower($host) || (int) ($data['e'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return User::query()->find((int) ($data['u'] ?? 0));
    }

    public function passLifetimeMinutes(): int
    {
        return intdiv(self::PASS_TTL, 60);
    }

    /** @param  array<string, mixed>  $data */
    private function seal(array $data): string
    {
        return Crypt::encryptString((string) json_encode($data));
    }

    /** @return array<string, mixed>|null */
    private function open(string $sealed): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString($sealed), true);
        } catch (DecryptException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
