<?php

namespace App\Mcp;

use App\Infrastructure\Cipi\CipiApp;
use App\Models\Project;
use App\Models\ProjectResource;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * The MCP servers a project's chats get from what is attached to it, with no setup: for each
 * Cipi app, the Cipi agent's MCP server inside the app (`cipi-<app>`: its health, deploys and
 * releases, logs, Artisan, database queries and schema, the anonymizer) and, when the
 * administrators turn it on, the Cipi server's own (`cipi-server-<name>`: every app of the
 * server). Only those who act on the resource get them; administrators always.
 */
final class NativeServers
{
    /** How long what the app's .env says about its agent is kept: each turn would read it otherwise. */
    private const FRESH = 300;

    /**
     * The servers for this person's chats on this project.
     *
     * @return array<string, array{type: string, url: string, headers: array<string, string>}>
     */
    public static function for(Project $project, User $user): array
    {
        $servers = [];

        foreach (self::cipiApps($project) as $resource) {
            if (! $resource->isVisibleTo($user)) {
                continue;
            }

            if ($resource->givesMcpTo($user, 'app') && ($agent = self::agent($resource)) !== null && $agent['enabled'] && $agent['token'] !== null) {
                $servers[self::appName($resource)] = ['type' => 'http', 'url' => $agent['url'], 'headers' => ['Authorization' => 'Bearer '.$agent['token']]];
            }

            if ($resource->givesMcpTo($user, 'server')) {
                $servers[self::serverName($resource)] = CipiApp::fromResource($resource)->serverMcp();
            }
        }

        return $servers;
    }

    /**
     * What the project's settings page shows: each server that comes with its resources, whether
     * it is on and why not, and who gets it. No token.
     *
     * @return list<array{name: string, kind: string, resource: ProjectResource, url: string, on: bool, ready: bool, reason: string|null}>
     */
    public static function describe(Project $project): array
    {
        $rows = [];

        foreach (self::cipiApps($project) as $resource) {
            $agent = self::agent($resource);
            $rows[] = [
                'name' => self::appName($resource),
                'kind' => 'app',
                'resource' => $resource,
                'url' => $agent['url'] ?? '',
                'on' => (bool) $resource->setting('mcp_app', true),
                'ready' => $agent !== null && $agent['enabled'] && $agent['token'] !== null,
                'reason' => $agent === null ? __('The app could not be read.') : $agent['reason'],
            ];
            $rows[] = [
                'name' => self::serverName($resource),
                'kind' => 'server',
                'resource' => $resource,
                'url' => CipiApp::fromResource($resource)->serverMcp()['url'],
                'on' => (bool) $resource->setting('mcp_server', false),
                'ready' => true,
                'reason' => null,
            ];
        }

        return $rows;
    }

    /** @return list<ProjectResource> */
    private static function cipiApps(Project $project): array
    {
        return $project->resources()->with(['account', 'project'])->where('section', 'apps')
            ->whereHas('account', fn ($q) => $q->where('connector', 'cipi'))
            ->orderBy('label')->get()->all();
    }

    /**
     * The agent as the app's .env describes it, cached (sealed: it holds the token).
     *
     * @return array{installed: bool, enabled: bool, url: string, token: string|null, reason: string|null}|null
     */
    public static function agent(ProjectResource $resource): ?array
    {
        // a server that does not answer is not asked again on every turn either
        return decrypt(Cache::remember(self::cacheKey($resource), self::FRESH, function () use ($resource) {
            try {
                return encrypt(CipiApp::fromResource($resource)->agent());
            } catch (Throwable $e) {
                report($e);

                return encrypt(null);
            }
        }));
    }

    /** Read the app again on the next turn: its settings or its .env changed. */
    public static function forget(ProjectResource $resource): void
    {
        Cache::forget(self::cacheKey($resource));
    }

    private static function cacheKey(ProjectResource $resource): string
    {
        return 'cipi-agent:'.$resource->uuid.':'.md5((string) json_encode([$resource->settings, $resource->account->updated_at]));
    }

    public static function appName(ProjectResource $resource): string
    {
        return Str::limit('cipi-'.Str::slug($resource->resource_id), 40, '');
    }

    public static function serverName(ProjectResource $resource): string
    {
        return rtrim(Str::limit('cipi-server-'.Str::slug($resource->account->name), 40, ''), '-');
    }
}
