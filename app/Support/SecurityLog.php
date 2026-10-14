<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The security log: one line per sign-in, failed attempt, two-factor change,
 * passkey, password change, door and policy, with the address it came from.
 * A file of its own (storage/logs/security.log) with a fixed shape,
 *
 *   [2026-10-14 10:00:00] production.WARNING: login.failed email=someone@agency.test ip=203.0.113.9 agent="…"
 *
 * so that fail2ban on a server reads it and bans an address after too many
 * refusals (installer/templates/fail2ban-*.conf), and Administration → System
 * shows the latest of it.
 */
final class SecurityLog
{
    public const CHANNEL = 'security';

    /** @param  array<string, mixed>  $fields */
    public static function record(string $event, array $fields = [], string $level = 'info'): void
    {
        // a queue worker or a command has no visitor: no address then
        $request = app()->bound('request') ? request() : null;

        $fields += [
            'ip' => $request?->ip(),
            'agent' => $request === null || $request->ip() === null ? null : Str::limit((string) $request->userAgent(), 120, ''),
        ];

        $line = $event;

        foreach ($fields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $value = is_bool($value) ? ($value ? 'yes' : 'no') : str_replace(["\n", "\r", '"'], ' ', (string) $value);
            $line .= ' '.$key.'='.(preg_match('/[\s=]/', $value) ? '"'.$value.'"' : $value);
        }

        Log::channel(self::CHANNEL)->log($level, $line);
    }

    /**
     * The last lines of the log, newest first.
     *
     * @return list<array{at: string, level: string, message: string}>
     */
    public static function recent(int $lines = 40): array
    {
        $path = (string) config('logging.channels.'.self::CHANNEL.'.path', storage_path('logs/security.log'));

        if (! is_file($path) || ! is_readable($path) || ($size = filesize($path)) === false) {
            return [];
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        // sixty-odd kilobytes from the end hold more than enough lines
        $from = max(0, $size - 65536);
        fseek($handle, $from);
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        $rows = preg_split('/\R/', $tail) ?: [];

        if ($from > 0) {
            array_shift($rows); // a line cut in the middle
        }

        $events = [];

        foreach (array_reverse($rows) as $row) {
            if (preg_match('/^\[([^\]]+)\] \S+\.([A-Z]+): (.*)$/', $row, $m)) {
                $events[] = ['at' => $m[1], 'level' => $m[2], 'message' => $m[3]];
            }

            if (count($events) >= $lines) {
                break;
            }
        }

        return $events;
    }
}
