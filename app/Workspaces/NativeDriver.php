<?php

namespace App\Workspaces;

use App\Accounts\Accounts;
use App\Enums\WorkspaceStatus;
use App\Models\Workspace;
use App\Server\ServerException;
use App\Server\StudioAdmin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Workspaces on this very server: a Linux user per person, a clone per
 * person × project, a PHP-FPM pool and a Caddy host for the preview, a
 * bridge service per workspace. All of it through `studio-admin`.
 */
final class NativeDriver implements WorkspaceDriver
{
    public function __construct(private readonly StudioAdmin $admin) {}

    public function provision(Workspace $workspace): void
    {
        $user = $workspace->user;
        $project = $workspace->project;

        $workspace->forceFill(['driver' => 'native', 'status' => WorkspaceStatus::Creating, 'last_error' => null])->save();

        try {
            if (! $git = Accounts::gitAccount($user, $project)) {
                throw new ServerException(self::missingGitAccount($project->repo_url));
            }

            $this->ensureGitCli($git->provider()->key());

            $bridgeToken = $workspace->bridge_token ?: Str::random(48);
            $workspace->forceFill(['bridge_token' => $bridgeToken])->save();
            $workspace->callback_token ?: $workspace->issueCallbackToken();

            $result = $this->admin->run('workspace-create', [$this->handle($workspace), $project->slug], [
                'repo_url' => $project->repo_url,
                'branch' => $project->default_branch,
                'php_version' => $project->php_version,
                'db_engine' => $project->db_engine,
                'preview_host' => $project->previewHostFor($user),
                'bridge_port' => $workspace->bridgePort(),
                'bridge_token' => $bridgeToken,
                ...$workspace->serviceInput(),
                ...$this->credentials($workspace),
            ]);

            $workspace->forceFill([
                'status' => WorkspaceStatus::Running,
                'app_url' => (string) ($result['app_url'] ?? 'https://'.$project->previewHostFor($user)),
                'bridge_url' => (string) ($result['bridge_url'] ?? 'http://127.0.0.1:'.$workspace->bridgePort()),
                'path' => $result['path'] ?? null,
                'git_account_id' => Accounts::git($user, $project)?->account->id,
                'last_seen_at' => now(),
            ])->save();
        } catch (ServerException $e) {
            $workspace->markFailed($e->getMessage());
        }
    }

    public function start(Workspace $workspace): void
    {
        if (! $workspace->path) {
            $this->provision($workspace);

            return;
        }

        // The helper rewrites the preview's Caddy host when it is missing: it needs Reverb's port.
        $this->transition($workspace, 'workspace-start', WorkspaceStatus::Running, [
            ...$workspace->serviceInput(),
        ]);
    }

    public function stop(Workspace $workspace): void
    {
        $this->transition($workspace, 'workspace-stop', WorkspaceStatus::Stopped);
    }

    public function destroy(Workspace $workspace): void
    {
        $this->transition($workspace, 'workspace-delete', WorkspaceStatus::Deleted);
    }

    public function refresh(Workspace $workspace): void
    {
        if (! $workspace->path) {
            return;
        }

        try {
            $result = $this->admin->run('workspace-status', [$this->handle($workspace), $workspace->project->slug]);

            $workspace->forceFill([
                'status' => match ((string) ($result['status'] ?? '')) {
                    'running' => WorkspaceStatus::Running,
                    'stopped' => WorkspaceStatus::Stopped,
                    'missing' => WorkspaceStatus::Deleted,
                    default => $workspace->status,
                },
                'last_seen_at' => now(),
            ])->save();
        } catch (ServerException $e) {
            $workspace->markFailed($e->getMessage());
        }
    }

    public function configure(Workspace $workspace): void
    {
        if (! $workspace->path) {
            return;
        }

        $project = $workspace->project;

        try {
            $host = $project->previewHostFor($workspace->user);
            $this->admin->run('workspace-configure', [$this->handle($workspace), $project->slug], [
                'php_version' => $project->php_version,
                'preview_host' => $host,
                ...$workspace->serviceInput(),
            ]);
            // The previews may have moved to another domain: the gatekeeper finds the workspace there now
            $workspace->forceFill(['app_url' => 'https://'.$host])->save();
        } catch (ServerException $e) {
            $workspace->markFailed($e->getMessage());
        }
    }

    /**
     * Push the git account the person works on this project with (token, CLI variables, commit
     * identity) into the workspace and restart its bridge: running turns stop, and resume.
     */
    public function syncCredentials(Workspace $workspace): void
    {
        if (! $workspace->path) {
            return;
        }

        try {
            $this->admin->run('workspace-env', [$this->handle($workspace), $workspace->project->slug], $this->credentials($workspace));
            $workspace->forceFill(['git_account_id' => Accounts::git($workspace->user, $workspace->project)?->account->id])->save();
        } catch (ServerException $e) {
            $workspace->markFailed($e->getMessage());
        }
    }

    /** @param  array<string, mixed>  $input */
    private function transition(Workspace $workspace, string $command, WorkspaceStatus $then, array $input = []): void
    {
        try {
            $this->admin->run($command, [$this->handle($workspace), $workspace->project->slug], $input);
            $workspace->forceFill(['status' => $then, 'last_error' => null, 'last_seen_at' => now()])->save();
        } catch (ServerException $e) {
            $workspace->markFailed($e->getMessage());
        }
    }

    private function handle(Workspace $workspace): string
    {
        $handle = $workspace->user->ensureHandle();

        if (! $handle) {
            throw new ServerException(__('No free name could be found for your home on the server: ask an administrator.'));
        }

        return $handle;
    }

    /**
     * The CLI the agent opens pull requests with on this host (gh, glab, az): the installer puts
     * the one of the host it was given in place; another host's is installed the first time a workspace needs it.
     */
    private function ensureGitCli(string $provider): void
    {
        if ($provider === config('studio.git.provider') || Cache::get('studio:git-cli:'.$provider)) {
            return;
        }

        $this->admin->run('git-cli', [$provider]);
        Cache::forever('studio:git-cli:'.$provider, true);
    }

    /** What to tell a person who has no account on the host of a project's repository. */
    public static function missingGitAccount(string $repoUrl): string
    {
        $host = (string) (parse_url($repoUrl, PHP_URL_HOST) ?: $repoUrl);

        return __('Add your account on :host (Settings → Accounts → Git) before opening this workspace: git works as you, with your identity.', ['host' => $host]);
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(Workspace $workspace): array
    {
        $git = Accounts::gitAccount($workspace->user, $workspace->project);

        return [
            // the token, the user name git sends with it, what the agent's CLI and Larapilot read
            // (the helper clears the variables of any other host) and the identity commits carry
            ...($git?->workspaceInput() ?? ['git_token' => null, 'git_username' => 'x-access-token', 'git_env' => (object) []]),
            // Claude goes with every turn (the account of the project, see BridgeClient::sendTurn):
            // no key is left in the workspace.
            'claude_api_key' => null,
        ];
    }
}
