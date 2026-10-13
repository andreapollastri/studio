<?php

namespace App\Ssh;

use App\Models\SshConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Studio's side of the SSH gateway. The page asks for a ticket for one of its
 * connections and opens the WebSocket with it; the gateway redeems the ticket
 * here, once, within a minute, and only then sees host and secret. The token
 * the gateway shows is derived from APP_KEY: both read the same .env.
 */
final class Gateway
{
    public static function token(): string
    {
        return hash_hmac('sha256', 'studio-ssh-gateway', (string) config('app.key'));
    }

    /** Where the browser opens the socket: STUDIO_SSH_URL, or /ssh/ws on Studio's own host. */
    public static function url(): string
    {
        if (filled($url = config('studio.ssh.url'))) {
            return (string) $url;
        }

        $app = rtrim((string) config('app.url'), '/');

        return (str_starts_with($app, 'https://') ? 'wss://'.substr($app, 8) : 'ws://'.Str::after($app, 'http://')).'/ssh/ws';
    }

    public static function issue(SshConnection $connection): string
    {
        $ticket = Str::random(48);
        Cache::put(self::key($ticket), ['connection_id' => $connection->id, 'user_id' => $connection->user_id], now()->addSeconds(config('studio.ssh.ticket_ttl', 60)));

        return $ticket;
    }

    /**
     * The connection behind a ticket, secret included; null when the ticket is
     * unknown, spent or expired, or its owner may no longer use the terminal.
     *
     * @return array<string, mixed>|null
     */
    public static function redeem(string $ticket): ?array
    {
        if (strlen($ticket) !== 48 || ! ctype_alnum($ticket)) {
            return null;
        }

        $issued = Cache::pull(self::key($ticket));
        if (! is_array($issued)) {
            return null;
        }

        $connection = SshConnection::query()->with(['user', 'key'])->whereKey($issued['connection_id'] ?? 0)->first();
        if ($connection === null || $connection->user_id !== $issued['user_id'] || ! $connection->user->canUseSsh() || ! $connection->hasCredential()) {
            return null;
        }

        $connection->forceFill(['last_used_at' => now()])->save();
        Log::info('SSH session opened', ['user' => $connection->user->email, 'connection' => $connection->name, 'target' => $connection->address()]);

        return $connection->forGateway();
    }

    private static function key(string $ticket): string
    {
        return 'ssh-ticket:'.hash('sha256', $ticket);
    }
}
