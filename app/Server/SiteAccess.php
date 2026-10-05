<?php

namespace App\Server;

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
     * The project behind a host: `<slug>.<domain>` is the site, a workspace's
     * `app_url` is a preview.
     *
     * @return array{project: Project, kind: 'site'|'preview', workspace: Workspace|null}|null
     */
    public function resolveHost(string $host): ?array
    {
        $host = strtolower(trim($host));
        $domain = strtolower((string) config('studio.domain'));

        if ($domain === '' || ! str_ends_with($host, '.'.$domain)) {
            return null;
        }

        $label = substr($host, 0, -strlen('.'.$domain));
        $project = Project::query()->where('slug', $label)->first();

        if ($project) {
            return ['project' => $project, 'kind' => 'site', 'workspace' => null];
        }

        $workspace = Workspace::query()->with('project')->where('app_url', 'https://'.$host)->first();

        if ($workspace) {
            return ['project' => $workspace->project, 'kind' => 'preview', 'workspace' => $workspace];
        }

        return null;
    }

    /**
     * Always a person logged in to Studio who has access to the project: a member in any role,
     * or an administrator. An address list narrows it: from anywhere else, nobody gets in.
     *
     * @param  'site'|'preview'  $kind
     * @return self::ALLOW|self::LOGIN|self::FORBIDDEN
     */
    public function decide(Project $project, string $kind, ?Workspace $workspace, string $ip, ?User $user): string
    {
        $branch = $kind === 'site' ? $project->default_branch : $workspace?->branch;
        $rule = $project->accessRuleFor($kind, $branch);

        if ($rule['ips'] !== [] && ! IpMatcher::matchesAny($ip, $rule['ips'])) {
            return self::FORBIDDEN;
        }

        if ($user === null) {
            return self::LOGIN;
        }

        return $user->canAccess($project) ? self::ALLOW : self::FORBIDDEN;
    }
}
