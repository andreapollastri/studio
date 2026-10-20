<?php

namespace App\Cursor;

use App\Models\CursorAccount;
use App\Server\StudioAdmin;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * A person's Cursor subscriptions the way the CLI is published: the person
 * signs in to the unmodified `agent` binary with `agent login`, in their own
 * home on this server (ws-<handle>), and the CLI keeps the login in the folder
 * of that account (~/.cursor for the first, ~/.cursor-<slug> for the others).
 * Studio never sees, stores or forwards it; it only asks the CLI whether
 * someone is signed in.
 *
 * An API key is a different product: it is billed apart from the subscription
 * and Studio does not keep one.
 *
 * In local development (the "local" driver) the sign-in terminal runs
 * `agent login` on this computer, in that subscription's folder, and status
 * asks the CLI there.
 */
final class CursorLogin
{
    public function __construct(private readonly StudioAdmin $admin) {}

    public static function onServer(): bool
    {
        return config('studio.driver') === 'native';
    }

    /**
     * What `agent status` says for the account's folder.
     *
     * @return array{logged_in: bool, email: string|null, error: string|null}
     */
    public function status(CursorAccount $account): array
    {
        try {
            if (! self::onServer()) {
                $home = getenv('HOME') ?: '';
                $folder = $home !== '' ? $home.DIRECTORY_SEPARATOR.$account->dir : null;
                $result = Process::timeout(20)->env(array_filter([
                    'NO_OPEN_BROWSER' => '1',
                    'AGENT_CLI_CREDENTIAL_STORE' => 'file',
                    'CURSOR_CONFIG_DIR' => $folder,
                    'XDG_CONFIG_HOME' => $folder,
                ]))->run(['agent', 'status', '--format', 'json']);
                $read = self::interpret($result->output(), $result->successful());

                return ['logged_in' => $read['logged_in'], 'email' => $read['email'], 'error' => null];
            }

            if (! $handle = $account->user->ensureHandle()) {
                return ['logged_in' => false, 'email' => null, 'error' => __('No free name could be found for your home on the server: ask an administrator.')];
            }

            $answer = $this->admin->run('cursor-status', $this->arguments($handle, $account));
            $email = is_string($answer['email'] ?? null) && filter_var($answer['email'], FILTER_VALIDATE_EMAIL) ? $answer['email'] : null;

            return ['logged_in' => (bool) ($answer['logged_in'] ?? false), 'email' => $email, 'error' => null];
        } catch (Throwable $e) {
            return ['logged_in' => false, 'email' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Ask the CLI and keep the answer on the account. The e-mail, when the CLI
     * says one, is for the person looking at the page: it is not stored.
     *
     * @return array{logged_in: bool, email: string|null, error: string|null}
     */
    public function refresh(CursorAccount $account): array
    {
        $status = $this->status($account);

        if ($status['error'] === null) {
            $account->forceFill([
                'signed_in' => $status['logged_in'],
                'checked_at' => now(),
            ])->save();
        }

        return $status;
    }

    public function logout(CursorAccount $account): void
    {
        if (self::onServer() && filled($account->user->handle)) {
            $this->admin->run('cursor-logout', $this->arguments((string) $account->user->handle, $account));
        }

        $account->forceFill(['signed_in' => false, 'checked_at' => now()])->save();
    }

    /** Sign out and remove the account's folder from the person's home: the first one (~/.cursor) stays, signed out. */
    public function forget(CursorAccount $account): void
    {
        if (! self::onServer() || ! filled($account->user->handle)) {
            return;
        }

        $account->dir === CursorAccount::DEFAULT_DIR
            ? $this->admin->run('cursor-logout', [(string) $account->user->handle])
            : $this->admin->run('cursor-forget', [(string) $account->user->handle, $account->dir]);
    }

    /**
     * Whether `agent status` says this login is signed in, from its exit and its
     * JSON, keeping nothing else the CLI printed.
     *
     * @return array{logged_in: bool, email: string|null}
     */
    public static function interpret(string $output, bool $ok): array
    {
        $json = self::object($output);
        $flag = self::flag($json);
        $loggedIn = $flag ?? ($ok && ! self::denied($json));
        $email = $loggedIn ? self::email($json) : null;

        return ['logged_in' => $loggedIn, 'email' => $email];
    }

    /**
     * The helper's arguments for an account: the handle, and the folder unless it is the default one.
     *
     * @return list<string>
     */
    private function arguments(string $handle, CursorAccount $account): array
    {
        return $account->dir === CursorAccount::DEFAULT_DIR ? [$handle] : [$handle, $account->dir];
    }

    /** @return array<string, mixed>|null */
    private static function object(string $output): ?array
    {
        $decoded = json_decode(trim($output), true);

        if (is_array($decoded)) {
            return $decoded;
        }

        foreach (array_reverse(preg_split('/\R/', trim($output)) ?: []) as $line) {
            $decoded = json_decode(trim($line), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>|null  $json */
    private static function flag(?array $json): ?bool
    {
        if ($json === null) {
            return null;
        }

        foreach (['authenticated', 'loggedIn', 'logged_in', 'isAuthenticated'] as $key) {
            if (is_bool($json[$key] ?? null)) {
                return $json[$key];
            }
        }

        return null;
    }

    /** @param  array<string, mixed>|null  $json */
    private static function denied(?array $json): bool
    {
        if ($json === null) {
            return false;
        }

        return in_array($json['status'] ?? null, ['not_authenticated', 'logged_out', 'unauthenticated'], true);
    }

    /** @param  array<string, mixed>|null  $json */
    private static function email(?array $json): ?string
    {
        if ($json === null) {
            return null;
        }

        $candidates = [
            $json['email'] ?? null,
            is_array($json['user'] ?? null) ? ($json['user']['email'] ?? null) : null,
            is_array($json['account'] ?? null) ? ($json['account']['email'] ?? null) : null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }

        return null;
    }
}
