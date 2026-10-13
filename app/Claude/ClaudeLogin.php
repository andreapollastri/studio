<?php

namespace App\Claude;

use App\Models\ClaudeAccount;
use App\Server\StudioAdmin;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * A person's Claude subscriptions the way Anthropic allows them on a hosted
 * platform: the person signs in to the unmodified Claude Code binary with
 * `claude auth login`, in their own home on this server (ws-<handle>), and the
 * CLI keeps the login in the folder of that account (~/.claude for the first,
 * ~/.claude-<slug> for the others). Studio never sees, stores or forwards it;
 * it only asks the CLI whether someone is signed in.
 *
 * In local development (the "local" driver) the CLI and its login are the
 * developer's own, on this machine, whatever the account.
 */
final class ClaudeLogin
{
    public function __construct(private readonly StudioAdmin $admin) {}

    public static function onServer(): bool
    {
        return config('studio.driver') === 'native';
    }

    /**
     * What `claude auth status` says for the account's folder: signed in at all, and how (claude.ai is a subscription).
     *
     * @return array{logged_in: bool, method: string|null, error: string|null}
     */
    public function status(ClaudeAccount $account): array
    {
        try {
            if (! self::onServer()) {
                $result = Process::timeout(20)->run(['claude', 'auth', 'status']);
                $json = json_decode($result->output(), true);

                return ['logged_in' => $result->successful(), 'method' => is_array($json) ? ($json['authMethod'] ?? null) : null, 'error' => null];
            }

            if (! $handle = $account->user->ensureHandle()) {
                return ['logged_in' => false, 'method' => null, 'error' => __('No free name could be found for your home on the server: ask an administrator.')];
            }

            $answer = $this->admin->run('claude-status', $this->arguments($handle, $account));

            return ['logged_in' => (bool) ($answer['logged_in'] ?? false), 'method' => is_string($answer['auth_method'] ?? null) ? $answer['auth_method'] : null, 'error' => null];
        } catch (Throwable $e) {
            return ['logged_in' => false, 'method' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Ask the CLI and keep the answer on the account.
     *
     * @return array{logged_in: bool, method: string|null, error: string|null}
     */
    public function refresh(ClaudeAccount $account): array
    {
        $status = $this->status($account);

        if ($status['error'] === null) {
            $account->forceFill([
                'signed_in' => $status['logged_in'] && $status['method'] === 'claude.ai',
                'checked_at' => now(),
            ])->save();
        }

        return $status;
    }

    public function logout(ClaudeAccount $account): void
    {
        if (self::onServer() && filled($account->user->handle)) {
            $this->admin->run('claude-logout', $this->arguments((string) $account->user->handle, $account));
        }

        $account->forceFill(['signed_in' => false, 'checked_at' => now()])->save();
    }

    /** Sign out and remove the account's folder from the person's home: the first one (~/.claude) stays, signed out. */
    public function forget(ClaudeAccount $account): void
    {
        if (! self::onServer() || ! filled($account->user->handle)) {
            return;
        }

        $dir = $account->dir ?? ClaudeAccount::DEFAULT_DIR;

        $dir === ClaudeAccount::DEFAULT_DIR
            ? $this->admin->run('claude-logout', [(string) $account->user->handle])
            : $this->admin->run('claude-forget', [(string) $account->user->handle, $dir]);
    }

    /**
     * The helper's arguments for an account: the handle, and the folder unless it is the default one.
     *
     * @return list<string>
     */
    private function arguments(string $handle, ClaudeAccount $account): array
    {
        $dir = $account->dir ?? ClaudeAccount::DEFAULT_DIR;

        return $dir === ClaudeAccount::DEFAULT_DIR ? [$handle] : [$handle, $dir];
    }
}
