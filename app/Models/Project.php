<?php

namespace App\Models;

use App\Enums\SiteStatus;
use App\Git\Providers\GitProvider;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $repo_url
 * @property string $default_branch
 * @property string $php_version
 * @property string $db_engine
 * @property int|null $deploy_user_id
 * @property SiteStatus $site_status
 * @property string|null $larapilot_api_token
 * @property string|null $webhook_secret
 * @property string|null $webhook_id
 * @property string|null $deployed_sha
 * @property Carbon|null $deployed_at
 * @property string|null $last_error
 * @property array<string, mixed>|null $settings
 * @property-read User|null $deployUser
 * @property-read Collection<int, ProjectMcpServer> $mcpServers
 */
#[Fillable([
    'name', 'slug', 'repo_url', 'default_branch', 'php_version', 'db_engine', 'deploy_user_id',
    'site_status', 'larapilot_api_token', 'webhook_secret', 'webhook_id', 'deployed_sha', 'deployed_at', 'last_error', 'settings',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'site_status' => SiteStatus::class,
            'larapilot_api_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'deployed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')->withTimestamps();
    }

    /** @return HasMany<Workspace, $this> */
    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    /** Remote MCP servers the chats of this project get. */
    /** @return HasMany<ProjectMcpServer, $this> */
    public function mcpServers(): HasMany
    {
        return $this->hasMany(ProjectMcpServer::class);
    }

    /** The administrator whose git provider token clones and deploys the project site. */
    /** @return BelongsTo<User, $this> */
    public function deployUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deploy_user_id');
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    public function workspaceFor(User $user): ?Workspace
    {
        return $this->workspaces()->where('user_id', $user->id)->first();
    }

    /** `<slug>.<domain>`: where the deploy branch runs on this server. */
    public function siteHost(): string
    {
        return $this->slug.'.'.config('studio.domain');
    }

    public function siteUrl(): string
    {
        return (string) ($this->settings['site_url'] ?? 'https://'.$this->siteHost());
    }

    /** `<handle>-<slug>.<domain>`: one person's workspace on this project. */
    public function previewHostFor(User $user): string
    {
        return ($user->handle ?: 'user').'-'.$this->slug.'.'.config('studio.domain');
    }

    /** A repository Studio created on the git provider that still has to receive its first commit. */
    public function needsBootstrap(): bool
    {
        return (bool) ($this->settings['bootstrap'] ?? false);
    }

    public function isOnline(): bool
    {
        return $this->site_status === SiteStatus::Ready || $this->site_status === SiteStatus::Deploying;
    }

    public function dashboardUrl(): ?string
    {
        return $this->isOnline() ? rtrim($this->siteUrl(), '/').'/larapilot' : null;
    }

    /** @var array{scheduler: bool, queues: list<string>, reverb: bool, horizon: bool, pulse: bool} */
    public const FEATURE_DEFAULTS = ['scheduler' => true, 'queues' => ['default'], 'reverb' => false, 'horizon' => false, 'pulse' => false];

    /** Longest slug: `prj-<slug>` is a Linux user name (32 at most), `<handle>-<slug>` a host label (63). */
    public const SLUG_MAX = 28;

    /**
     * Server features of this project's hosts: the scheduler (cron every
     * minute), one queue worker per queue (or Horizon in their place), Reverb,
     * Pulse. Each is applied to the project site and to every workspace preview.
     *
     * @return array{scheduler: bool, queues: list<string>, reverb: bool, horizon: bool, pulse: bool}
     */
    public function features(): array
    {
        $stored = (array) ($this->settings['features'] ?? []);
        $queues = array_values(array_unique(array_filter(array_map(fn ($q) => trim((string) $q), (array) ($stored['queues'] ?? self::FEATURE_DEFAULTS['queues'])), fn (string $q) => $q !== '')));

        return [
            'scheduler' => (bool) ($stored['scheduler'] ?? self::FEATURE_DEFAULTS['scheduler']),
            'queues' => $queues === [] ? ['default'] : $queues,
            'reverb' => (bool) ($stored['reverb'] ?? self::FEATURE_DEFAULTS['reverb']),
            'horizon' => (bool) ($stored['horizon'] ?? self::FEATURE_DEFAULTS['horizon']),
            'pulse' => (bool) ($stored['pulse'] ?? self::FEATURE_DEFAULTS['pulse']),
        ];
    }

    /**
     * The feature switches as the server helper reads them.
     *
     * @return array{scheduler: string, queues: string, reverb: string, reverb_port: int, horizon: string, pulse: string}
     */
    public function serviceInput(): array
    {
        $features = $this->features();

        return [
            'scheduler' => $features['scheduler'] ? '1' : '0',
            'queues' => implode(',', $features['queues']),
            'reverb' => $features['reverb'] ? '1' : '0',
            'reverb_port' => $this->reverbPort(),
            'horizon' => $features['horizon'] ? '1' : '0',
            'pulse' => $features['pulse'] ? '1' : '0',
        ];
    }

    /** The loopback port Reverb listens on for this project, proxied by Caddy under /app. */
    public function reverbPort(): int
    {
        return 9000 + (int) $this->id;
    }

    /**
     * Where the project hosts may be opened from. Every host (the deploy site, each workspace
     * preview) always wants a person logged in to Studio with access to the project; an address
     * list narrows that to people coming from those addresses, for the site, the previews, or a
     * single branch. An old "public" mode in the settings is ignored.
     *
     * @return array{site: array{ips: list<string>}, previews: array{ips: list<string>}, branches: list<array{branch: string, ips: list<string>}>}
     */
    public function access(): array
    {
        $stored = (array) ($this->settings['access'] ?? []);
        $ips = fn (mixed $raw): array => array_values(array_filter(array_map(fn ($ip) => trim((string) $ip), (array) (((array) $raw)['ips'] ?? [])), fn (string $ip) => $ip !== ''));
        $branches = [];

        foreach ((array) ($stored['branches'] ?? []) as $override) {
            $branch = trim((string) (((array) $override)['branch'] ?? ''));

            if ($branch !== '' && $ips($override) !== []) {
                $branches[] = ['branch' => $branch, 'ips' => $ips($override)];
            }
        }

        return [
            'site' => ['ips' => $ips($stored['site'] ?? [])],
            'previews' => ['ips' => $ips($stored['previews'] ?? [])],
            'branches' => $branches,
        ];
    }

    /**
     * `<handle>-<slug>` is a preview host, `<slug>` a site host, on the same wildcard:
     * a slug equal to somebody's handle, a dash and another project's slug would hide that
     * preview. The host the slug would shadow, or null when it is free.
     */
    public static function previewHostShadowedBy(string $slug): ?string
    {
        $handles = User::query()->whereNotNull('handle')->pluck('handle');

        foreach ($handles as $handle) {
            if (! str_starts_with($slug, $handle.'-')) {
                continue;
            }

            $rest = substr($slug, strlen($handle) + 1);

            if ($rest !== '' && static::query()->where('slug', $rest)->exists()) {
                return $handle.'-'.$rest;
            }
        }

        return null;
    }

    /**
     * The reverse: a handle whose previews (`<handle>-<slug>`) would land on an existing
     * project's host. That host, or null when the handle is free.
     */
    public static function siteHostShadowedByHandle(string $handle): ?string
    {
        $slugs = static::query()->pluck('slug')->all();

        foreach ($slugs as $slug) {
            if (in_array($handle.'-'.$slug, $slugs, true)) {
                return $handle.'-'.$slug;
            }
        }

        return null;
    }

    /**
     * The address list for one host: a branch override wins over the host kind's list.
     *
     * @param  'site'|'preview'  $kind
     * @return array{ips: list<string>}
     */
    public function accessRuleFor(string $kind, ?string $branch): array
    {
        $access = $this->access();

        foreach ($access['branches'] as $override) {
            if ($branch !== null && $override['branch'] === $branch) {
                return ['ips' => $override['ips']];
            }
        }

        return $kind === 'site' ? $access['site'] : $access['previews'];
    }

    /** The repository path on the installation's git provider (`owner/repo`…), or null when the address belongs elsewhere. */
    public function repositoryPath(): ?string
    {
        return GitProvider::current()->repositoryPath($this->repo_url);
    }
}
