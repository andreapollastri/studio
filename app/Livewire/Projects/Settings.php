<?php

namespace App\Livewire\Projects;

use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Git\GitAccount;
use App\Infrastructure\Git\Forges;
use App\Jobs\ConfigureProjectSite;
use App\Mcp\McpProbe;
use App\Models\ConnectorAccount;
use App\Models\Domain;
use App\Models\Project;
use App\Models\ProjectMcpServer;
use App\Models\User;
use App\Models\Workspace;
use App\Server\IpMatcher;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A project's settings, for the administrators: what it is (name, repository,
 * deploy branch, stack, the account that deploys it), who works on it and as
 * what, the server features of its hosts, who may open them, the agent, the
 * MCP servers of its chats. The project key never changes.
 */
#[Layout('layouts::studio')]
class Settings extends Component
{
    use AuthorizesRequests;

    public const TABS = ['general', 'members', 'server', 'access', 'agent', 'mcp'];

    public Project $project;

    #[Url]
    public string $tab = 'general';

    // ---- General

    public string $name = '';

    public string $repo_url = '';

    public string $default_branch = '';

    public string $php_version = '';

    public string $db_engine = 'mariadb';

    /** uuid of the git account that reads the repository, registers its webhook and deploys the site */
    public string $deploy_account = '';

    // ---- Members: user uuid => '' (not a member) | pm | dev | client

    /** @var array<string, string> */
    public array $roles = [];

    // ---- Server

    /** uuid of the domain the site lives at; '' = `<key>.<domain>` only */
    public string $site_domain = '';

    /** the site's subdomain on a wildcard domain; '' = the domain itself */
    public string $site_label = '';

    /** uuid of the wildcard domain the previews live under; '' = Studio's */
    public string $preview_domain = '';

    public bool $scheduler = true;

    public string $queues = 'default';

    public bool $reverb = false;

    public bool $horizon = false;

    public bool $pulse = false;

    /** IANA name: APP_TIMEZONE of the site and the previews, TZ of the workspaces. */
    public string $timezone = 'UTC';

    // ---- Who may open the hosts

    public string $site_ips = '';

    public string $previews_ips = '';

    /** @var list<array{branch: string, ips: string}> */
    public array $branch_rules = [];

    // ---- What the agent gets

    public bool $agent_browser = true;

    public bool $agent_sandboxes = true;

    public int $agent_sandbox_limit = 3;

    // ---- MCP servers in the chats

    public ?int $mcp_id = null;

    public string $mcp_name = '';

    public string $mcp_url = '';

    public string $mcp_header_name = 'Authorization';

    /** Empty while editing means "keep the stored value". */
    public string $mcp_header_value = '';

    public bool $mcp_has_secret = false;

    /** @var list<string> */
    public array $mcp_roles = ProjectMcpServer::DEFAULT_ROLES;

    public function mount(Project $project): void
    {
        $this->authorize('manage', $project);

        $this->project = $project;
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'general';
        $this->loadForm();
    }

    /** What is stored, in the form. */
    private function loadForm(): void
    {
        $project = $this->project->refresh();
        $features = $project->features();
        $access = $project->access();
        $agent = $project->agentSettings();

        $this->name = $project->name;
        $this->repo_url = $project->repo_url;
        $this->default_branch = $project->default_branch;
        $this->php_version = $project->php_version;
        $this->db_engine = $project->db_engine;
        // the account deploying it today, else the first that could: a service account, then one of the administrator's
        $this->deploy_account = (string) ($project->deployAccount()->account ?? self::deployAccounts(auth()->user(), $project->repo_url)->first())?->uuid;

        $members = $project->members()->get()->keyBy('id');
        $this->roles = User::query()->where('role', '!=', UserRole::Admin->value)->orderBy('name')->get()
            ->mapWithKeys(fn (User $user) => [$user->uuid => $members->has($user->id)
                ? ($members[$user->id]->membership->role ?? $user->defaultRole())->value
                : ''])
            ->all();

        $this->site_domain = (string) $project->siteDomain?->uuid;
        $this->site_label = $project->site_host && $project->siteDomain && $project->site_host !== $project->siteDomain->name
            ? substr($project->site_host, 0, -strlen('.'.$project->siteDomain->name)) : '';
        $this->preview_domain = (string) $project->previewDomain?->uuid;
        $this->scheduler = $features['scheduler'];
        $this->queues = implode(', ', $features['queues']);
        $this->reverb = $features['reverb'];
        $this->horizon = $features['horizon'];
        $this->pulse = $features['pulse'];
        $this->timezone = $project->timezone();
        $this->agent_browser = $agent['browser'];
        $this->agent_sandboxes = $agent['sandboxes'];
        $this->agent_sandbox_limit = $agent['sandbox_limit'];
        $this->site_ips = implode("\n", $access['site']['ips']);
        $this->previews_ips = implode("\n", $access['previews']['ips']);
        $this->branch_rules = array_map(fn (array $rule) => ['branch' => $rule['branch'], 'ips' => implode(', ', $rule['ips'])], $access['branches']);
        $this->cancelMcp();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'general';
        $this->resetValidation();
    }

    /**
     * The git accounts that may deploy the project: the shared service accounts, and the
     * administrator's own, on the host of the repository.
     *
     * @return Collection<int, ConnectorAccount>
     */
    public static function deployAccounts(User $admin, ?string $remote = null): Collection
    {
        return ConnectorAccount::query()
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $admin->id))
            ->whereIn('connector', array_keys(Forges::ALL))
            ->orderByRaw('user_id is not null')->orderBy('name')->get()
            ->filter(fn (ConnectorAccount $account) => $remote === null || $remote === '' || $account->matchesRemote($remote))
            ->values();
    }

    /* ---------------------------------------------------------------- general */

    public function saveGeneral(): void
    {
        $this->authorize('manage', $this->project);

        $accounts = self::deployAccounts(auth()->user(), $this->repo_url);
        // the account deploying today stays a choice, even when it is another administrator's
        $current = $this->project->deployAccount()?->account;
        if ($current !== null && $accounts->doesntContain('id', $current->id)) {
            $accounts->push($current);
        }
        $account = $accounts->firstWhere('uuid', $this->deploy_account);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'repo_url' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) use ($account): void {
                $git = $account ? (new GitAccount($account))->provider() : null;
                if (! is_string($value) || $git === null || $git->repositoryPath($value) === null || ! $account->matchesRemote($value)) {
                    $fail(__('Use the HTTPS address of a repository on :host, the host of the deploying account.', ['host' => $account->host ?? __('its host')]));
                }
            }],
            'deploy_account' => ['required', Rule::in($accounts->pluck('uuid')->all())],
            'default_branch' => ['required', 'string', 'max:200', 'regex:#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', 'not_regex:#(\.\.|\.lock$)#'],
            'php_version' => ['required', Rule::in((array) config('studio.php_versions'))],
            'db_engine' => ['required', Rule::in(['mariadb', 'pgsql'])],
        ], [
            'deploy_account.required' => __('Choose the account that deploys the site: a service account, or one of yours on the repository\'s host.'),
            'deploy_account.in' => __('Choose the account that deploys the site: a service account, or one of yours on the repository\'s host.'),
            'default_branch.regex' => __('A branch name: letters, digits, dots, dashes, underscores and slashes.'),
            'default_branch.not_regex' => __('A branch name: letters, digits, dots, dashes, underscores and slashes.'),
        ]);

        $this->project->update([
            'name' => $validated['name'],
            'repo_url' => $validated['repo_url'],
            'default_branch' => $validated['default_branch'],
            'php_version' => $validated['php_version'],
            'db_engine' => $validated['db_engine'],
            'git_account_id' => $account?->id,
            'deploy_user_id' => $account->user_id ?? $this->project->deploy_user_id,
        ]);

        Flux::toast(variant: 'success', text: __('Project saved.'));
    }

    /* ---------------------------------------------------------------- members */

    public function saveMembers(): void
    {
        $this->authorize('manage', $this->project);

        $this->validate([
            'roles' => ['array'],
            'roles.*' => ['nullable', Rule::in(['', ...array_map(fn (UserRole $r) => $r->value, UserRole::projectRoles())])],
        ]);

        $ids = User::query()->whereIn('uuid', array_keys(array_filter($this->roles)))->pluck('id', 'uuid');
        $this->project->syncMembers(collect(array_filter($this->roles))->mapWithKeys(fn (string $role, string $uuid) => isset($ids[$uuid]) ? [$ids[$uuid] => $role] : [])->all());

        Flux::toast(variant: 'success', text: __('Members saved. A role changes what each person sees and may do from their next click.'));
    }

    /* ---------------------------------------------------------------- server, access, agent */

    public function addBranchRule(): void
    {
        $this->branch_rules[] = ['branch' => '', 'ips' => ''];
    }

    public function removeBranchRule(int $index): void
    {
        $this->branch_rules = array_values(array_filter($this->branch_rules, fn (int $i) => $i !== $index, ARRAY_FILTER_USE_KEY));
    }

    public function saveSettings(): void
    {
        $project = $this->project;
        $this->authorize('manage', $project);

        $this->validate([
            'queues' => ['required', 'string', 'regex:/^\s*[a-z0-9][a-z0-9_-]*(\s*,\s*[a-z0-9][a-z0-9_-]*)*\s*$/'],
            'timezone' => ['required', Rule::in(timezone_identifiers_list())],
            'agent_sandbox_limit' => ['required', 'integer', 'min:1', 'max:10'],
            'site_ips' => ['nullable', 'string', 'max:4000'],
            'previews_ips' => ['nullable', 'string', 'max:4000'],
            'branch_rules' => ['array', 'max:50'],
            'branch_rules.*.branch' => ['required', 'string', 'max:200', 'regex:#^[A-Za-z0-9][A-Za-z0-9._/-]*$#'],
            'branch_rules.*.ips' => ['required', 'string', 'max:4000'],
        ], [
            'queues.regex' => __('Queue names: lowercase letters, digits, dashes and underscores, separated by commas.'),
            'timezone.in' => __('Choose a timezone from the list.'),
            'branch_rules.*.branch.required' => __('Name the branch or remove the rule.'),
            'branch_rules.*.ips.required' => __('List the addresses for this branch, or remove the rule.'),
        ]);

        $addresses = $this->addresses($project);

        if ($addresses === null) {
            return;
        }

        $lists = ['site_ips' => $this->site_ips, 'previews_ips' => $this->previews_ips];

        foreach ($this->branch_rules as $index => $rule) {
            $lists["branch_rules.$index.ips"] = $rule['ips'];
        }

        foreach ($lists as $field => $raw) {
            foreach ($this->ipList($raw) as $ip) {
                if (! IpMatcher::isValidRule($ip)) {
                    $this->addError($field, __(':ip is not an IP address or a CIDR range.', ['ip' => $ip]));

                    return;
                }
            }
        }

        $project->update([...$addresses, 'settings' => array_merge($project->settings ?? [], [
            'features' => ['scheduler' => $this->scheduler, 'queues' => $this->queueList(), 'reverb' => $this->reverb, 'horizon' => $this->horizon, 'pulse' => $this->pulse],
            'timezone' => $this->timezone,
            'agent' => ['browser' => $this->agent_browser, 'sandboxes' => $this->agent_sandboxes, 'sandbox_limit' => $this->agent_sandbox_limit],
            'access' => [
                'site' => ['ips' => $this->ipList($this->site_ips)],
                'previews' => ['ips' => $this->ipList($this->previews_ips)],
                'branches' => array_map(fn (array $rule) => ['branch' => trim($rule['branch']), 'ips' => $this->ipList($rule['ips'])], $this->branch_rules),
            ],
        ])]);

        if ($project->site_status !== SiteStatus::New || $project->workspaces()->exists()) {
            ConfigureProjectSite::dispatch($project);
        }

        Flux::toast(variant: 'success', text: __('Settings saved. They are being applied to the site and the workspaces.'));
    }

    /** Another domain for the site: no subdomain, or the project's key when only the wildcard points here. */
    public function updatedSiteDomain(): void
    {
        $domain = $this->site_domain === '' ? null : Domain::query()->where('uuid', $this->site_domain)->first();
        $this->site_label = $domain && $domain->wildcardPointsHere() && ! $domain->namePointsHere() ? $this->project->slug : '';
        $this->resetValidation(['site_domain', 'site_label']);
    }

    /**
     * The site's address and the previews' domain, checked: a domain that points here for that
     * use (the one already in use may keep its place while its DNS is lost), a host no other
     * site has. Null, with the error on the field, when it is not right.
     *
     * @return array{site_host: string|null, site_domain_id: int|null, preview_domain_id: int|null}|null
     */
    private function addresses(Project $project): ?array
    {
        $site = $this->site_domain === '' ? null : Domain::query()->where('uuid', $this->site_domain)->first();
        $previews = $this->preview_domain === '' ? null : Domain::query()->where('uuid', $this->preview_domain)->first();
        $label = $site?->wildcard ? strtolower(trim($this->site_label)) : '';
        $host = null;

        if ($site !== null) {
            $host = $label === '' ? $site->name : $label.'.'.$site->name;
            $kept = $host === $project->site_host;

            if ($label !== '' && preg_match(Domain::LABEL, $label) !== 1) {
                $this->addError('site_label', __('One subdomain: letters, digits and dashes.'));

                return null;
            }

            if (! $kept && ! $site->isVerified()) {
                $this->addError('site_domain', __(':name does not point at this server: check it in Administration → Domains.', ['name' => $site->name]));

                return null;
            }

            if (! $kept && ($label === '' ? ! $site->namePointsHere() : ! $site->wildcardPointsHere())) {
                $this->addError($label === '' ? 'site_domain' : 'site_label', $label === ''
                    ? __(':name itself does not point here: give the site a subdomain of it.', ['name' => $site->name])
                    : __('*.:name does not point here: the site can only be at :name itself.', ['name' => $site->name]));

                return null;
            }

            if (Project::query()->whereKeyNot($project->id)->where('site_host', $host)->exists()
                || Workspace::query()->where('app_url', 'https://'.$host)->exists()) {
                $this->addError('site_label', __(':host is already the address of another site or of a preview.', ['host' => $host]));

                return null;
            }
        }

        if ($previews !== null && $previews->id !== $project->preview_domain_id && ! $previews->wildcardPointsHere()) {
            $this->addError('preview_domain', __('*.:name does not point at this server: previews cannot live there.', ['name' => $previews->name]));

            return null;
        }

        return ['site_host' => $host, 'site_domain_id' => $site?->id, 'preview_domain_id' => $previews?->id];
    }

    /* ---------------------------------------------------------------- MCP */

    /** Add a server to the project, or save the one being edited; applies to every chat's next message. */
    public function saveMcp(): void
    {
        $project = $this->project;
        $this->authorize('manage', $project);
        $this->mcp_name = strtolower(trim($this->mcp_name));
        $this->mcp_url = trim($this->mcp_url);
        $this->mcp_header_name = trim($this->mcp_header_name);

        $this->validate([
            'mcp_name' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('project_mcp_servers', 'name')->where('project_id', $project->id)->ignore($this->mcp_id)],
            'mcp_url' => ['required', 'url:https,http', 'max:500'],
            'mcp_header_name' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9-]+$/'],
            'mcp_header_value' => ['nullable', 'string', 'max:2000'],
            'mcp_roles' => ['required', 'array', 'min:1'],
            'mcp_roles.*' => [Rule::enum(UserRole::class)],
        ], [
            'mcp_name.regex' => __('Lowercase letters, digits, dashes and underscores: it becomes part of the tool names.'),
            'mcp_roles.required' => __('Tick at least one role.'),
        ]);

        $server = $this->mcp_id ? $project->mcpServers()->findOrFail($this->mcp_id) : new ProjectMcpServer(['project_id' => $project->id, 'enabled' => true]);
        $headers = $server->headers ?? [];

        if ($this->mcp_header_value !== '' && $this->mcp_header_name !== '') {
            $headers = [$this->mcp_header_name => $this->mcp_header_value];
        } elseif ($this->mcp_header_name === '') {
            $headers = [];
        } elseif ($headers !== [] && ! array_key_exists($this->mcp_header_name, $headers)) {
            $headers = [$this->mcp_header_name => (string) reset($headers)];
        }

        $server->fill([
            'name' => $this->mcp_name,
            'url' => $this->mcp_url,
            'headers' => $headers === [] ? null : $headers,
            'roles' => array_values(array_unique($this->mcp_roles)),
        ]);
        $server->status = null;
        $server->save();

        Flux::toast(variant: 'success', text: __('MCP server :name saved. Test it to see it answer.', ['name' => $server->name]));
        $this->cancelMcp();
    }

    public function editMcp(string $serverId): void
    {
        $server = $this->mcpServer($serverId);

        $this->mcp_id = $server->id;
        $this->mcp_name = $server->name;
        $this->mcp_url = $server->url;
        $this->mcp_header_name = (string) (array_key_first($server->headers ?? []) ?? '');
        $this->mcp_header_value = '';
        $this->mcp_has_secret = ($server->headers ?? []) !== [];
        $this->mcp_roles = $server->roles;
        $this->resetValidation();
    }

    public function cancelMcp(): void
    {
        $this->reset(['mcp_id', 'mcp_name', 'mcp_url', 'mcp_header_value', 'mcp_has_secret']);
        $this->mcp_header_name = 'Authorization';
        $this->mcp_roles = ProjectMcpServer::DEFAULT_ROLES;
        $this->resetValidation(['mcp_name', 'mcp_url', 'mcp_header_name', 'mcp_header_value', 'mcp_roles']);
    }

    public function toggleMcp(string $serverId): void
    {
        $server = $this->mcpServer($serverId);
        $server->update(['enabled' => ! $server->enabled]);
    }

    public function deleteMcp(string $serverId): void
    {
        $server = $this->mcpServer($serverId);
        $server->delete();

        if ($this->mcp_id === $server->id) {
            $this->cancelMcp();
        }
    }

    /** One initialize call to the server, with its header, as Claude Code would make it. */
    public function testMcp(string $serverId, McpProbe $probe): void
    {
        $server = $this->mcpServer($serverId);
        $server->update(['status' => $probe->check($server->url, $server->headers ?? []), 'checked_at' => now()]);
    }

    private function mcpServer(string $serverId): ProjectMcpServer
    {
        $this->authorize('manage', $this->project);

        return $this->project->mcpServers()->where('uuid', $serverId)->firstOrFail();
    }

    /** @return list<string> */
    private function ipList(string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $raw) ?: []), fn (string $ip) => $ip !== ''));
    }

    /** @return list<string> */
    private function queueList(): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $this->queues)), fn (string $q) => $q !== '')));
    }

    public function render(): View
    {
        $accounts = self::deployAccounts(auth()->user(), $this->repo_url);
        $current = $this->project->deployAccount()?->account;

        if ($current !== null && $accounts->doesntContain('id', $current->id)) {
            $accounts->push($current);
        }

        return view('livewire.projects.settings', [
            'title' => __(':project · Settings', ['project' => $this->project->name]),
            'admins' => User::query()->where('role', UserRole::Admin->value)->orderBy('name')->get(),
            'people' => User::query()->where('role', '!=', UserRole::Admin->value)->orderBy('name')->get(),
            'deployAccounts' => $accounts,
            'phpVersions' => (array) config('studio.php_versions'),
            'timezones' => $this->tab === 'server' ? timezone_identifiers_list() : [],
            // the verified domains, and the ones this project uses whatever their state
            'domains' => $this->tab === 'server' ? Domain::query()->where(fn ($q) => $q->where('status', 'verified')
                ->orWhereKey(array_filter([$this->project->site_domain_id, $this->project->preview_domain_id])))->orderBy('name')->get() : collect(),
            'mcpServers' => $this->tab === 'mcp' ? $this->project->mcpServers()->orderBy('name')->get() : collect(),
        ]);
    }
}
