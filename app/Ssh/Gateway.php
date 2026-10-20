<?php

namespace App\Ssh;

use App\Enums\UserRole;
use App\Livewire\Studio\Files;
use App\Models\ClaudeAccount;
use App\Models\CursorAccount;
use App\Models\Project;
use App\Models\SshConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Support\SecurityLog;
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
     * server, in the folder of one of their subscriptions: the gateway runs the
     * official CLI on a terminal and relays it.
     */
    public static function issueLogin(ClaudeAccount $account): string
    {
        $ticket = Str::random(48);
        Cache::put(self::key($ticket), ['kind' => 'claude-login', 'user_id' => $account->user_id, 'account_id' => $account->id], now()->addSeconds(config('studio.ssh.ticket_ttl', 60)));

        return $ticket;
    }

    /**
     * A ticket for the person's own `agent login`, in their home on this
     * server, in the folder of one of their Cursor subscriptions: the gateway
     * runs the official CLI on a terminal and relays it.
     */
    public static function issueCursorLogin(CursorAccount $account): string
    {
        $ticket = Str::random(48);
        Cache::put(self::key($ticket), ['kind' => 'cursor-login', 'user_id' => $account->user_id, 'account_id' => $account->id], now()->addSeconds(config('studio.ssh.ticket_ttl', 60)));

        return $ticket;
    }

    /**
     * A ticket for the Terminal tab: a shell in this workspace (or a sandbox's
     * worktree of it), which its bridge runs as the workspace's user.
     */
    public static function issueShell(Workspace $workspace, User $user, ?string $sandbox = null): string
    {
        $ticket = Str::random(48);
        Cache::put(self::key($ticket), ['kind' => 'workspace-shell', 'user_id' => $user->id, 'workspace_id' => $workspace->id, 'sandbox' => $sandbox], now()->addSeconds(config('studio.ssh.ticket_ttl', 60)));

        return $ticket;
    }

    /**
     * A ticket for the Terminal's Develop mode: a shell on the project's own site, as its user.
     */
    public static function issueSiteShell(Project $project, User $user): string
    {
        $ticket = Str::random(48);
        Cache::put(self::key($ticket), ['kind' => 'site-shell', 'user_id' => $user->id, 'project_id' => $project->id], now()->addSeconds(config('studio.ssh.ticket_ttl', 60)));

        return $ticket;
    }

    /** Who may open a shell on a project's site: its administrators and developers, on a server Studio manages. */
    public static function canDevelop(User $user, Project $project): bool
    {
        return config('studio.driver') === 'native'
            && $project->isOnline()
            && ($user->isAdmin() || $user->roleIn($project) === UserRole::Dev);
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
            $account = $user?->claudeAccounts()->whereKey($issued['account_id'] ?? 0)->first();

            if ($user === null || $account === null || ! $account->isSubscription() || ! $user->canChat() || ! filled($user->handle) || config('studio.driver') !== 'native') {
                return null;
            }
            Log::info('Claude sign-in started', ['user' => $user->email, 'account' => $account->name]);

            return ['kind' => 'claude-login', 'user_id' => $user->id, 'handle' => $user->handle, 'dir' => $account->dir ?? ClaudeAccount::DEFAULT_DIR];
        }

        if (($issued['kind'] ?? null) === 'cursor-login') {
            $cursor = $user?->cursorAccounts()->whereKey($issued['account_id'] ?? 0)->first();

            if ($user === null || $cursor === null || ! $user->canChat() || ! filled($user->handle) || config('studio.driver') !== 'native') {
                return null;
            }
            Log::info('Cursor sign-in started', ['user' => $user->email, 'account' => $cursor->name]);

            return ['kind' => 'cursor-login', 'user_id' => $user->id, 'handle' => $user->handle, 'dir' => $cursor->dir];
        }

        if (($issued['kind'] ?? null) === 'site-shell') {
            $project = Project::query()->whereKey($issued['project_id'] ?? 0)->first();

            if ($user === null || $project === null || ! self::canDevelop($user, $project)) {
                return null;
            }
            Log::info('Site terminal opened', ['user' => $user->email, 'project' => $project->slug]);
            SecurityLog::record('site.shell', ['user' => $user->email, 'project' => $project->slug], 'notice');

            return ['kind' => 'site-shell', 'user_id' => $user->id, 'key' => $project->slug, 'php' => (string) $project->php_version];
        }

        if (($issued['kind'] ?? null) === 'workspace-shell') {
            $workspace = Workspace::query()->with('project')->whereKey($issued['workspace_id'] ?? 0)->first();

            // who may still use the terminal of a workspace that still runs
            if ($user === null || $workspace === null || ! Files::allows($user, $workspace) || ! $workspace->isRunning() || ! $workspace->hasBridge()) {
                return null;
            }
            Log::info('Workspace terminal opened', ['user' => $user->email, 'workspace' => $workspace->id, 'owner' => $workspace->user_id, 'sandbox' => $issued['sandbox'] ?? null]);

            return [
                'kind' => 'workspace-shell',
                'user_id' => $user->id,
                'bridge_url' => (string) $workspace->bridge_url,
                'bridge_token' => (string) $workspace->bridge_token,
                'sandbox' => $issued['sandbox'] ?? null,
            ];
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
