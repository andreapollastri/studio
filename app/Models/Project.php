<?php

namespace App\Models;

use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Git\GitAccount;
use App\Git\Providers\GitProvider;
use App\Infrastructure\Git\Forge;
use App\Infrastructure\Git\Forges;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * @property string $uuid
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string|null $site_host the site's own address on a domain of Administration → Domains; null = `<slug>.<domain>`
 * @property int|null $site_domain_id
 * @property int|null $preview_domain_id the wildcard domain the previews live under; null = Studio's
 * @property string $repo_url
 * @property string $default_branch
 * @property string $php_version
 * @property string $db_engine
 * @property int|null $deploy_user_id
 * @property int|null $git_account_id the administrator's git account the repository was created or imported with
 * @property SiteStatus $site_status
 * @property string|null $larapilot_api_token
 * @property string|null $webhook_secret
 * @property string|null $webhook_id
 * @property string|null $deployed_sha
 * @property Carbon|null $deployed_at
 * @property string|null $last_error
 * @property array<string, mixed>|null $settings
 * @property-read User|null $deployUser
 * @property-read Domain|null $siteDomain
 * @property-read Domain|null $previewDomain
 * @property-read ConnectorAccount|null $repositoryAccount
 * @property-read Collection<int, ProjectMcpServer> $mcpServers
 * @property-read ProjectMember|null $membership the person's membership, when read through User::projects()
 */
#[Fillable([
    'name', 'slug', 'site_host', 'site_domain_id', 'preview_domain_id', 'repo_url', 'default_branch', 'php_version', 'db_engine', 'deploy_user_id', 'git_account_id',
    'site_status', 'larapilot_api_token', 'webhook_secret', 'webhook_id', 'deployed_sha', 'deployed_at', 'last_error', 'settings',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use HasPublicUuid;

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

    /** @return BelongsToMany<User, $this, ProjectMember, 'membership'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')->using(ProjectMember::class)->as('membership')->withPivot('role')->withTimestamps();
    }

    /** @return BelongsToMany<SshKey, $this> the system keys shared with the project */
    public function sshKeys(): BelongsToMany
    {
        return $this->belongsToMany(SshKey::class)->withPivot('developers')->withTimestamps();
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

    /** @return HasMany<ProjectResource, $this> what an administrator attached from the connectors */
    public function resources(): HasMany
    {
        return $this->hasMany(ProjectResource::class);
    }

    /**
     * Put people on the project with their roles, by user id; those left out leave it.
     *
     * @param  array<int, UserRole|string|null>  $roles  user id => role (null: their default)
     */
    public function syncMembers(array $roles): void
    {
        $users = User::query()->whereKey(array_keys($roles))->get()->keyBy('id');

        $this->members()->sync(collect($roles)->filter(fn ($role, $id) => $users->has($id))->mapWithKeys(function ($role, $id) use ($users) {
            $role = $role instanceof UserRole ? $role : UserRole::tryFrom((string) $role);

            return [$id => ['role' => ($role !== null && $role !== UserRole::Admin ? $role : $users[$id]->defaultRole())->value]];
        })->all());
    }

    /**
     * A key for a new project: its name as a host label, cut short, and six random
     * characters (no 0/o, 1/l/i), so "api" at one client and "api" at another live
     * side by side as api-9usaf9 and api-k2mx7p. It never changes: it names the
     * site's host, the Linux user, the databases and the folders on the server.
     */
    public static function newKey(string $name): string
    {
        $base = rtrim(substr(Str::slug($name), 0, self::SLUG_MAX - 7), '-') ?: 'project';

        if (in_array($base, ['studio', 'www', 'mail'], true)) {
            $base = 'project';
        }

        do {
            $key = $base.'-'.substr(str_shuffle(str_repeat('abcdefghjkmnpqrstuvwxyz23456789', 6)), 0, 6);
        } while (static::query()->where('slug', $key)->exists() || static::previewHostShadowedBy($key) !== null || User::query()->where('handle', $key)->exists());

        return $key;
    }

    /** The administrator whose git provider token clones and deploys the project site. */
    /** @return BelongsTo<User, $this> */
    public function deployUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deploy_user_id');
    }

    /**
     * The administrator's git account the repository was created or imported with: it registers
     * the push webhook and deploys the site, on whichever host the repository lives.
     *
     * @return BelongsTo<ConnectorAccount, $this>
     */
    public function repositoryAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class, 'git_account_id');
    }

    /**
     * The account that deploys the site: the repository account, else the deploying
     * administrator's account on the repository's host.
     */
    public function deployAccount(): ?GitAccount
    {
        $account = $this->repositoryAccount;

        if ($account === null || ! $account->matchesRemote($this->repo_url)) {
            $account = $this->deployUser?->gitAccounts()->orderBy('id')->get()->first(fn (ConnectorAccount $a) => $a->matchesRemote($this->repo_url));
        }

        return $account ? new GitAccount($account) : null;
    }

    /**
     * The git host of the repository: the provider of the account that deploys it, else of
     * any account on its host (a self-managed GitLab, an Azure DevOps organization are known
     * by their accounts), else the public host its address is on.
     */
    public function gitProvider(): ?GitProvider
    {
        if ($this->repositoryAccount?->matchesRemote($this->repo_url)) {
            return (new GitAccount($this->repositoryAccount))->provider();
        }

        $known = ConnectorAccount::query()->whereIn('connector', array_keys(Forges::ALL))->whereNotNull('host')->orderBy('id')->get()
            ->first(fn (ConnectorAccount $account) => $account->matchesRemote($this->repo_url));

        if ($known !== null) {
            return (new GitAccount($known))->provider();
        }

        // the public hosts: github.com, gitlab.com, bitbucket.org
        foreach (Forges::all() as $forge) {
            try {
                $host = $forge->host([]);
            } catch (Throwable) {
                continue;
            }

            if (Forge::remoteOn($host, $this->repo_url)) {
                return GitProvider::make($forge->key(), 'https://'.$host);
            }
        }

        return null;
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    public function workspaceFor(User $user): ?Workspace
    {
        return $this->workspaces()->where('user_id', $user->id)->first();
    }

    /** @return BelongsTo<Domain, $this> */
    public function siteDomain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'site_domain_id');
    }

    /** @return BelongsTo<Domain, $this> */
    public function previewDomain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'preview_domain_id');
    }

    /** `<slug>.<domain>`: the address every site has on Studio's own domain. */
    public function defaultSiteHost(): string
    {
        return $this->slug.'.'.config('studio.domain');
    }

    /** Where the deploy branch runs on this server: its own address when it has one, `<slug>.<domain>` otherwise. */
    public function siteHost(): string
    {
        return $this->site_host ?: $this->defaultSiteHost();
    }

    /**
     * The site's other addresses: `<slug>.<domain>` stays when the site moves to a domain of its
     * own, so that the team's links keep working.
     *
     * @return list<string>
     */
    public function siteAliases(): array
    {
        return $this->site_host ? [$this->defaultSiteHost()] : [];
    }

    /** The domain the previews and sandboxes live under: the project's wildcard domain, or Studio's. */
    public function previewDomainName(): string
    {
        return $this->previewDomain->name ?? (string) config('studio.domain');
    }

    public function siteUrl(): string
    {
        return (string) ($this->settings['site_url'] ?? 'https://'.$this->siteHost());
    }

    /** `<handle>-<slug>.<domain>`: one person's workspace on this project, under its preview domain. */
    public function previewHostFor(User $user): string
    {
        return ($user->handle ?: 'user').'-'.$this->slug.'.'.$this->previewDomainName();
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

    /** `<handle>-<slug>--c<id>`: the preview of one sandboxed conversation, cut to fit a host label (63). */
    public function sandboxHostFor(User $user, int $conversationId): string
    {
        $suffix = '--c'.$conversationId;
        $base = rtrim(substr(($user->handle ?: 'user').'-'.$this->slug, 0, 63 - strlen($suffix)), '-');

        return $base.$suffix.'.'.$this->previewDomainName();
    }

    /** @var array{browser: bool, sandboxes: bool, sandbox_limit: int} */
    public const AGENT_DEFAULTS = ['browser' => true, 'sandboxes' => true, 'sandbox_limit' => 3];

    /**
     * What the agent gets in this project's chats: a browser signed in to the preview it
     * works on (screenshots, console), and conversations in a sandbox of their own, so many
     * per person at once.
     *
     * @return array{browser: bool, sandboxes: bool, sandbox_limit: int}
     */
    public function agentSettings(): array
    {
        $stored = (array) ($this->settings['agent'] ?? []);

        return [
            'browser' => (bool) ($stored['browser'] ?? self::AGENT_DEFAULTS['browser']),
            'sandboxes' => (bool) ($stored['sandboxes'] ?? self::AGENT_DEFAULTS['sandboxes']),
            'sandbox_limit' => max(1, min(10, (int) ($stored['sandbox_limit'] ?? self::AGENT_DEFAULTS['sandbox_limit']))),
        ];
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
     * The project's timezone (an IANA name, UTC by default): APP_TIMEZONE of the site and of
     * every workspace preview, and TZ of the workspaces, where Claude and the terminal run.
     */
    public function timezone(): string
    {
        $timezone = (string) ($this->settings['timezone'] ?? '');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    /**
     * The feature switches and the timezone as the server helper reads them.
     *
     * @return array{scheduler: string, queues: string, reverb: string, reverb_port: int, horizon: string, pulse: string, timezone: string, public: string}
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
            'timezone' => $this->timezone(),
            // a public site never shows a debug page
            'public' => $this->isPublic('site') ? '1' : '0',
        ];
    }

    /** The loopback port Reverb listens on for this project, proxied by Caddy under /app. */
    public function reverbPort(): int
    {
        return 9000 + (int) $this->id;
    }

    /**
     * Where the project hosts may be opened from. Every host (the deploy site, each workspace
     * preview) wants a person logged in to Studio with access to the project, unless an
     * administrator made the site, or the site and the previews, public; an address list narrows
     * either to people coming from those addresses, for the site, the previews, or a single
     * branch. Public previews come only with a public site. An old "public" mode is ignored.
     *
     * @return array{site: array{ips: list<string>}, previews: array{ips: list<string>}, branches: list<array{branch: string, ips: list<string>}>, public: array{site: bool, previews: bool}}
     */
    public function access(): array
    {
        $stored = (array) ($this->settings['access'] ?? []);
        $ips = fn (mixed $raw): array => array_values(array_filter(array_map(fn ($ip) => trim((string) $ip), (array) (((array) $raw)['ips'] ?? [])), fn (string $ip) => $ip !== ''));
        $branches = [];
        $public = ['site' => (((array) ($stored['public'] ?? []))['site'] ?? false) === true, 'previews' => (((array) ($stored['public'] ?? []))['previews'] ?? false) === true];

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
            'public' => [
                'site' => $public['site'],
                'previews' => $public['site'] && $public['previews'],
            ],
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

    /**
     * Whether a host of that kind opens without the Studio login. Previews (sandboxes included)
     * are public only along with the site.
     *
     * @param  'site'|'preview'  $kind
     */
    public function isPublic(string $kind): bool
    {
        return $this->access()['public'][$kind === 'site' ? 'site' : 'previews'];
    }

    /** The repository path on its git host (`owner/repo`…), or null when no enabled host knows the address. */
    public function repositoryPath(): ?string
    {
        return $this->gitProvider()?->repositoryPath($this->repo_url);
    }
}
