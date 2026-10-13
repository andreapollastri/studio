<?php

namespace App\Claude;

use App\Enums\ClaudeAuthMode;
use App\Models\User;
use App\Server\StudioAdmin;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * A person's Claude subscription the way Anthropic allows it on a hosted
 * platform: the person signs in to the unmodified Claude Code binary with
 * `claude auth login`, in their own home on this server (ws-<handle>), and the
 * CLI keeps the login in ~/.claude/.credentials.json. Studio never sees,
 * stores or forwards it; it only asks the CLI whether someone is signed in.
 *
 * In local development (the "local" driver) the CLI and its login are the
 * developer's own, on this machine.
 */
final class ClaudeLogin
{
    public function __construct(private readonly StudioAdmin $admin) {}

    public static function onServer(): bool
    {
        return config('studio.driver') === 'native';
    }

    /**
     * What `claude auth status` says: signed in at all, and how (claude.ai is a subscription).
     *
     * @return array{logged_in: bool, method: string|null, error: string|null}
     */
    public function status(User $user): array
    {
        try {
            if (! self::onServer()) {
                $result = Process::timeout(20)->run(['claude', 'auth', 'status']);
                $json = json_decode($result->output(), true);

                return ['logged_in' => $result->successful(), 'method' => is_array($json) ? ($json['authMethod'] ?? null) : null, 'error' => null];
            }

            if (! filled($user->handle)) {
                return ['logged_in' => false, 'method' => null, 'error' => __('Connect your git provider first: your home on the server is named after it.')];
            }

            $answer = $this->admin->run('claude-status', [(string) $user->handle]);

            return ['logged_in' => (bool) ($answer['logged_in'] ?? false), 'method' => is_string($answer['auth_method'] ?? null) ? $answer['auth_method'] : null, 'error' => null];
        } catch (Throwable $e) {
            return ['logged_in' => false, 'method' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Ask the CLI and keep the answer on the person: a claude.ai login is their subscription.
     *
     * @return array{logged_in: bool, method: string|null, error: string|null}
     */
    public function refresh(User $user): array
    {
        $status = $this->status($user);

        if ($status['error'] !== null) {
            return $status;
        }

        if ($status['logged_in'] && $status['method'] === 'claude.ai') {
            $user->forceFill(['claude_auth_mode' => ClaudeAuthMode::Subscription, 'claude_token' => null, 'claude_checked_at' => now()])->save();
        } elseif ($user->claude_auth_mode === ClaudeAuthMode::Subscription) {
            $user->forceFill(['claude_auth_mode' => ClaudeAuthMode::None, 'claude_checked_at' => now()])->save();
        } else {
            $user->forceFill(['claude_checked_at' => now()])->save();
        }

        return $status;
    }

    public function logout(User $user): void
    {
        if (self::onServer() && filled($user->handle)) {
            $this->admin->run('claude-logout', [(string) $user->handle]);
        }

        if ($user->claude_auth_mode === ClaudeAuthMode::Subscription) {
            $user->forceFill(['claude_auth_mode' => ClaudeAuthMode::None, 'claude_checked_at' => now()])->save();
        }
    }
}
