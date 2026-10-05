<?php

namespace App\Server;

use App\Enums\SiteStatus;
use App\Enums\WorkspaceStatus;
use App\Git\Providers\GitProvider;
use App\Models\Project;
use App\Models\Workspace;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * `<slug>.<domain>`: the deploy branch of a project, served by this server.
 * Created once, deployed on every push to the deploy branch (the git provider's webhook)
 * or by hand from the admin page.
 */
final class ProjectSites
{
    public function __construct(private readonly StudioAdmin $admin, private readonly WorkspaceManager $workspaces) {}

    public function provision(Project $project): void
    {
        $project->forceFill(['site_status' => SiteStatus::Provisioning, 'last_error' => null])->save();

        try {
            $token = $this->deployToken($project);
            $this->assertTokenWorks($token);

            if ($this->isLocal()) {
                // Development: no server to talk to, pretend the site exists.
                $project->forceFill(['site_status' => SiteStatus::Ready, 'larapilot_api_token' => $project->larapilot_api_token ?: Str::random(40)])->save();
            } else {
                if ($project->needsBootstrap()) {
                    // A repository Studio just created on the git provider: fill it with the latest
                    // Laravel, Laravel Boost and Larapilot, push, then provision it like any other.
                    $this->admin->run('project-bootstrap', [$project->slug], [
                        'repo_url' => $project->repo_url,
                        'branch' => $project->default_branch,
                        'php_version' => $project->php_version,
                        ...$this->gitInput($token),
                        'git_name' => $project->deployUser->name ?? 'Studio',
                        'git_email' => $project->deployUser->email ?? 'studio@localhost',
                    ]);

                    $project->forceFill(['settings' => array_merge($project->settings ?? [], ['bootstrap' => false])])->save();
                }

                $result = $this->admin->run('project-create', [$project->slug], [
                    'repo_url' => $project->repo_url,
                    'branch' => $project->default_branch,
                    'php_version' => $project->php_version,
                    'db_engine' => $project->db_engine,
                    'site_host' => $project->siteHost(),
                    ...$this->gitInput($token),
                    ...$project->serviceInput(),
                ]);

                $project->forceFill([
                    'site_status' => SiteStatus::Ready,
                    'larapilot_api_token' => $result['larapilot_api_token'] ?? $project->larapilot_api_token,
                    'deployed_sha' => $result['sha'] ?? null,
                    'deployed_at' => now(),
                ])->save();
            }

            $this->ensureWebhook($project, $token);
        } catch (ServerException|RuntimeException $e) {
            $project->forceFill(['site_status' => SiteStatus::Failed, 'last_error' => Str::limit($this->explain($e), 1000)])->save();
        }
    }

    public function deploy(Project $project): void
    {
        // Nothing to deploy onto yet: never provisioned, or the provisioning failed.
        // Deploy means "make the site exist", and the real reason shows if it fails again.
        if ($project->site_status === SiteStatus::New || $project->deployed_at === null) {
            $this->provision($project);

            return;
        }

        $project->forceFill(['site_status' => SiteStatus::Deploying, 'last_error' => null])->save();

        try {
            if ($this->isLocal()) {
                $project->forceFill(['site_status' => SiteStatus::Ready, 'deployed_sha' => 'local', 'deployed_at' => now()])->save();

                return;
            }

            $token = $this->deployToken($project);
            $this->assertTokenWorks($token);

            $result = $this->admin->run('project-deploy', [$project->slug], [
                'branch' => $project->default_branch,
                'php_version' => $project->php_version,
                ...$this->gitInput($token),
            ]);

            $project->forceFill([
                'site_status' => SiteStatus::Ready,
                'deployed_sha' => $result['sha'] ?? $project->deployed_sha,
                'deployed_at' => now(),
            ])->save();
        } catch (ServerException|RuntimeException $e) {
            $project->forceFill(['site_status' => SiteStatus::Failed, 'last_error' => Str::limit($this->explain($e), 1000)])->save();
        }
    }

    /** Apply the project settings to the site and to every workspace of the project on the server. */
    public function configure(Project $project): void
    {
        if ($this->isLocal()) {
            return;
        }

        if ($project->site_status !== SiteStatus::New) {
            try {
                $this->admin->run('project-configure', [$project->slug], [
                    'php_version' => $project->php_version,
                    'site_host' => $project->siteHost(),
                    ...$project->serviceInput(),
                ]);
                $project->forceFill(['last_error' => null])->save();
            } catch (ServerException $e) {
                $project->forceFill(['last_error' => Str::limit($e->getMessage(), 1000)])->save();
            }
        }

        $project->workspaces()
            ->whereNot('status', WorkspaceStatus::Deleted->value)
            ->whereNotNull('path')
            ->get()
            ->each(fn (Workspace $workspace) => $this->workspaces->driverFor($workspace)->configure($workspace));
    }

    /**
     * Take the project off the server, then out of the database: every workspace
     * first (each row's deletion queues its removal from the server), the site,
     * the push webhook on the forge, and last the row itself. The row stays, marked
     * "deleting", while this runs: a queued job needs it, and the page shows why.
     */
    public function destroy(Project $project): void
    {
        $project->forceFill(['site_status' => SiteStatus::Deleting, 'last_error' => null])->save();

        $project->workspaces()->get()->each->delete();

        // Also for a site whose creation failed halfway: the helper removes whatever is there.
        if (! $this->isLocal()) {
            try {
                $this->admin->run('project-delete', [$project->slug]);
            } catch (ServerException $e) {
                Log::error("The site of {$project->slug} could not be removed from the server: {$e->getMessage()}");
            }
        }

        if ($project->webhook_id && ($repo = $project->repositoryPath()) && ($token = $project->deployUser?->git_token)) {
            GitProvider::current()->deleteWebhook($token, $repo, $project->webhook_id);
        }

        $project->delete();
    }

    /** The push webhook that deploys the site; registered once, with the deploying administrator's token. */
    private function ensureWebhook(Project $project, string $token): void
    {
        if ($project->webhook_id || ! ($repo = $project->repositoryPath())) {
            return;
        }

        $secret = $project->webhook_secret ?: Str::random(40);
        $project->forceFill(['webhook_secret' => $secret])->save();

        $id = GitProvider::current()->createPushWebhook($token, $repo, route('api.git.webhook', $project), $secret, $project->default_branch);

        $project->forceFill(['webhook_id' => $id])->save();
    }

    private function deployToken(Project $project): string
    {
        $token = $project->deployUser?->git_token;

        if (! $token) {
            throw new RuntimeException(__('No administrator with a :provider token is set to deploy this project.', ['provider' => GitProvider::current()->label()]));
        }

        return $token;
    }

    /** Fail before the helper runs when GitHub (or the forge) already refuses the token. */
    private function assertTokenWorks(string $token): void
    {
        GitProvider::current()->whoAmI($token);
    }

    private function explain(ServerException|RuntimeException $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'Invalid username or token') || str_contains($message, 'Password authentication is not supported')) {
            return __('GitHub refused the deployer\'s token. Paste a new personal access token in Settings → Connections, then deploy again.');
        }

        return $message;
    }

    /**
     * How the helper reaches the repository: the token, the user name git sends
     * with it, and the Larapilot setting that turns the forge integration on.
     *
     * @return array<string, string>
     */
    private function gitInput(string $token): array
    {
        $git = GitProvider::current();

        return ['git_token' => $token, 'git_username' => $git->gitUsername(), 'git_provider' => $git->key()];
    }

    private function isLocal(): bool
    {
        return config('studio.driver') === 'local';
    }
}
