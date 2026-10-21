<?php

namespace App\Server;

use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;

/**
 * Who may open a project host. Caddy asks Studio on every request to every
 * project host (the forward_auth of the Caddyfile); this is the answer.
 */
final class SiteAccess
{
    public const ALLOW = 'allow';

    public const LOGIN = 'login';

    public const FORBIDDEN = 'forbidden';

    /** The browser's own sign-in (basic authentication): the public address of a site asks this way, never with Studio's page. */
    public const BASIC = 'basic';

    /**
     * The project behind a host, by the hosts Studio keeps: a project's site at its own address
     * (`site_host`) or at `<slug>.<domain>`, a workspace's `app_url` (a preview), a sandboxed
     * conversation's host (a preview too, with the sandbox's branch, which the access rules may
     * single out). `public_address`: the host is the site's public address, on a domain of
     * Administration → Domains; `public`: the project made it public there. Studio's own
     * addresses, the team's, are neither.
     *
     * @return array{project: Project, kind: 'site'|'preview', workspace: Workspace|null, branch?: string|null, public_address: bool, public: bool}|null
     */
    public function resolveHost(string $host): ?array
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return null;
        }

        $project = Project::query()->where('site_host', $host)->first() ?? $this->defaultSite($host);

        if ($project) {
            $publicAddress = $project->site_host === $host;

            return ['project' => $project, 'kind' => 'site', 'workspace' => null, 'public_address' => $publicAddress, 'public' => $publicAddress && $project->isPublished()];
        }

        $workspace = Workspace::query()->with('project')->where('app_url', 'https://'.$host)->first();

        if ($workspace) {
            return ['project' => $workspace->project, 'kind' => 'preview', 'workspace' => $workspace, 'public_address' => false, 'public' => false];
        }

        $conversation = Conversation::query()->with('workspace.project')->where('sandbox_host', $host)->first();

        if ($conversation) {
            return ['project' => $conversation->workspace->project, 'kind' => 'preview', 'workspace' => $conversation->workspace, 'branch' => $conversation->sandbox['branch'] ?? null, 'public_address' => false, 'public' => false];
        }

        return null;
    }

    /** `<slug>.<domain>`, on Studio's own domain: every site's address, also when it has one of its own. */
    private function defaultSite(string $host): ?Project
    {
        $domain = strtolower((string) config('studio.domain'));

        if ($domain === '' || ! str_ends_with($host, '.'.$domain)) {
            return null;
        }

        return Project::query()->where('slug', substr($host, 0, -strlen('.'.$domain)))->first();
    }

    /**
     * A person with access to the project: a member in any role, or an administrator. On the
     * team's addresses they come through Studio's login; on the public address ($basic) through
     * the browser's own sign-in, with the email and password of their Studio account, so the
     * client's domain never shows Studio. A public address the project made public ($public)
     * lets anybody in: it runs with APP_ENV=staging, where Larapilot's /larapilot dashboard asks
     * for a sign-in of its own. An address list narrows all of it: from anywhere else, nobody gets in.
     *
     * @param  'site'|'preview'  $kind
     * @return self::ALLOW|self::LOGIN|self::BASIC|self::FORBIDDEN
     */
    public function decide(Project $project, string $kind, ?Workspace $workspace, string $ip, ?User $user, ?string $branch = null, bool $public = false, bool $basic = false): string
    {
        $branch = $kind === 'site' ? $project->default_branch : ($branch ?? $workspace?->branch);
        $rule = $project->accessRuleFor($kind, $branch);

        if ($rule['ips'] !== [] && ! IpMatcher::matchesAny($ip, $rule['ips'])) {
            return self::FORBIDDEN;
        }

        if ($public) {
            return self::ALLOW;
        }

        if ($user !== null && $user->canAccess($project)) {
            return self::ALLOW;
        }

        // the browser asks again: somebody else may have the account it takes
        if ($basic) {
            return self::BASIC;
        }

        return $user === null ? self::LOGIN : self::FORBIDDEN;
    }
}
