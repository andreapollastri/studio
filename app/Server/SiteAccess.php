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

    /**
     * The project behind a host, by the hosts Studio keeps: a project's site at its own address
     * (`site_host`) or at `<slug>.<domain>`, a workspace's `app_url` (a preview), a sandboxed
     * conversation's host (a preview too, with the sandbox's branch, which the access rules may
     * single out). Whatever the domain: Studio's, or one of Administration → Domains.
     *
     * @return array{project: Project, kind: 'site'|'preview', workspace: Workspace|null, branch?: string|null}|null
     */
    public function resolveHost(string $host): ?array
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return null;
        }

        $project = Project::query()->where('site_host', $host)->first() ?? $this->defaultSite($host);

        if ($project) {
            return ['project' => $project, 'kind' => 'site', 'workspace' => null];
        }

        $workspace = Workspace::query()->with('project')->where('app_url', 'https://'.$host)->first();

        if ($workspace) {
            return ['project' => $workspace->project, 'kind' => 'preview', 'workspace' => $workspace];
        }

        $conversation = Conversation::query()->with('workspace.project')->where('sandbox_host', $host)->first();

        if ($conversation) {
            return ['project' => $conversation->workspace->project, 'kind' => 'preview', 'workspace' => $conversation->workspace, 'branch' => $conversation->sandbox['branch'] ?? null];
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
     * A person logged in to Studio who has access to the project: a member in any role, or an
     * administrator. A public host lets anybody in, except to its /larapilot dashboard, which
     * still wants the login. An address list narrows both: from anywhere else, nobody gets in.
     *
     * @param  'site'|'preview'  $kind
     * @return self::ALLOW|self::LOGIN|self::FORBIDDEN
     */
    public function decide(Project $project, string $kind, ?Workspace $workspace, string $ip, ?User $user, ?string $branch = null, string $path = '/'): string
    {
        $branch = $kind === 'site' ? $project->default_branch : ($branch ?? $workspace?->branch);
        $rule = $project->accessRuleFor($kind, $branch);

        if ($rule['ips'] !== [] && ! IpMatcher::matchesAny($ip, $rule['ips'])) {
            return self::FORBIDDEN;
        }

        if ($project->isPublic($kind) && ! self::isDashboard($path)) {
            return self::ALLOW;
        }

        if ($user === null) {
            return self::LOGIN;
        }

        return $user->canAccess($project) ? self::ALLOW : self::FORBIDDEN;
    }

    /**
     * Larapilot's dashboard, /larapilot and below, however the path is spelled: any case,
     * percent-encoded, with doubled slashes, dot segments or behind /index.php.
     */
    public static function isDashboard(string $path): bool
    {
        $segments = [];

        foreach (explode('/', strtolower(rawurldecode($path))) as $segment) {
            match ($segment) {
                '', '.' => null,
                '..' => array_pop($segments),
                default => $segments[] = $segment,
            };
        }

        if (($segments[0] ?? null) === 'index.php') {
            array_shift($segments);
        }

        return ($segments[0] ?? null) === 'larapilot';
    }
}
