<?php

namespace App\Ssh;

use App\Models\SshConnection;
use App\Models\User;
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

    /** A ticket for one session of this person on this connection, with their own credential. */
    public static function issue(SshConnection $connection, User $user): string
    {
        $ticket = Str::random(48);
        Cache::put(self::key($ticket), ['connection_id' => $connection->id, 'user_id' => $user->id], now()->addSeconds(config('studio.ssh.ticket_ttl', 60)));

        return $ticket;
    }

    /**
     * A ticket for the person's own `claude auth login`, in their home on this
     * server: the gateway runs the official CLI on a terminal and relays it.
     */
    public static function issueLogin(User $user): string
    {
        $ticket = Str::random(48);
        Cache::put(self::key($ticket), ['kind' => 'claude-login', 'user_id' => $user->id], now()->addSeconds(config('studio.ssh.ticket_ttl', 60)));

        return $ticket;
    }

    /**
     * The connection behind a ticket, with the secret of the person it was
     * issued to; null when the ticket is unknown, spent or expired, or that
     * person may no longer use the terminal or see the connection.
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

        $user = User::query()->whereKey($issued['user_id'] ?? 0)->first();

        if (($issued['kind'] ?? null) === 'claude-login') {
            if ($user === null || ! $user->canChat() || ! filled($user->handle) || config('studio.driver') !== 'native') {
                return null;
            }
            Log::info('Claude sign-in started', ['user' => $user->email]);

            return ['kind' => 'claude-login', 'user_id' => $user->id, 'handle' => $user->handle];
        }

        $connection = SshConnection::query()->with('key')->whereKey($issued['connection_id'] ?? 0)->first();
        if ($user === null || $connection === null || ! $user->canUseSsh() || ! $connection->isVisibleTo($user) || ! $connection->canConnect($user)) {
            return null;
        }

        if ($connection->isOwnedBy($user)) {
            $connection->forceFill(['last_used_at' => now()])->save();
        }
        Log::info('SSH session opened', ['user' => $user->email, 'connection' => $connection->name, 'owner' => $connection->user_id, 'target' => $connection->address()]);

        return $connection->forGateway($user);
    }

    private static function key(string $ticket): string
    {
        return 'ssh-ticket:'.hash('sha256', $ticket);
    }
}
