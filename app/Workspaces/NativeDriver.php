<?php

namespace App\Workspaces;

use App\Enums\WorkspaceStatus;
use App\Git\Providers\GitProvider;
use App\Models\Workspace;
use App\Server\ServerException;
use App\Server\StudioAdmin;
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
            if (! $user->hasGit()) {
                throw new ServerException(__('Connect your :provider account in Settings before opening a workspace.', ['provider' => GitProvider::current()->label()]));
            }

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
                'git_name' => $user->name,
                'git_email' => $user->email,
                ...$workspace->serviceInput(),
                ...$this->credentials($workspace),
            ]);

            $workspace->forceFill([
                'status' => WorkspaceStatus::Running,
                'app_url' => (string) ($result['app_url'] ?? 'https://'.$project->previewHostFor($user)),
                'bridge_url' => (string) ($result['bridge_url'] ?? 'http://127.0.0.1:'.$workspace->bridgePort()),
                'path' => $result['path'] ?? null,
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
            $this->admin->run('workspace-configure', [$this->handle($workspace), $project->slug], [
                'php_version' => $project->php_version,
                'preview_host' => $project->previewHostFor($workspace->user),
                ...$workspace->serviceInput(),
            ]);
        } catch (ServerException $e) {
            $workspace->markFailed($e->getMessage());
        }
    }

    /** Push the person's current git provider and Claude credentials into a workspace and restart its bridge. */
    public function syncCredentials(Workspace $workspace): void
    {
        if (! $workspace->path) {
            return;
        }

        try {
            $this->admin->run('workspace-env', [$this->handle($workspace), $workspace->project->slug], $this->credentials($workspace));
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
        $handle = $workspace->user->handle;

        if (! $handle) {
            throw new ServerException(__('Connect your :provider account in Settings before opening a workspace.', ['provider' => GitProvider::current()->label()]));
        }

        return $handle;
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(Workspace $workspace): array
    {
        $user = $workspace->user;
        $git = GitProvider::current();

        return [
            'git_token' => $user->git_token,
            'git_username' => $git->gitUsername(),
            // what the agent's CLI and Larapilot read; the helper clears the variables of any other provider
            'git_env' => $user->git_token ? $git->agentEnvironment($user->git_token) : (object) [],
            'claude_auth_mode' => $user->claude_auth_mode->value,
            'claude_env' => $user->claude_auth_mode->envName(),
            'claude_token' => $user->claude_token,
        ];
    }
}
