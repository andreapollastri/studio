<?php

namespace App\Livewire\Admin;

use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Git\Providers\GitProvider;
use App\Jobs\ConfigureProjectSite;
use App\Jobs\DeployProjectSite;
use App\Jobs\DestroyProjectSite;
use App\Jobs\ProvisionProjectSite;
use App\Mcp\McpProbe;
use App\Models\Project;
use App\Models\ProjectMcpServer;
use App\Models\User;
use App\Server\IpMatcher;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

#[Layout('layouts::app')]
#[Title('Projects · Administration')]
class Projects extends Component
{
    use AuthorizesRequests;

    public ?int $editingId = null;

    public string $name = '';

    public string $slug = '';

    public string $repo_url = '';

    public string $default_branch = 'develop';

    public string $php_version = '8.4';

    public string $db_engine = 'mariadb';

    /** @var list<int> */
    public array $members = [];

    /** "existing" points at a repository; "new" creates one on the git provider with Laravel, Boost and Larapilot in it. */
    public string $source = 'existing';

    public string $new_owner = '';

    public string $new_name = '';

    public bool $new_private = true;

    /**
     * Where the administrator's token may create repositories: organizations, groups, workspaces or projects.
     *
     * @var list<array{value: string, label: string}>
     */
    public array $owners = [];

    // ---- Project settings: server features and who may open the hosts

    public ?int $settingsId = null;

    /** Which part of the settings dialog is open: server, access or mcp. */
    public string $settingsTab = 'server';

    public bool $scheduler = true;

    public string $queues = 'default';

    public bool $reverb = false;

    public bool $horizon = false;

    public bool $pulse = false;

    // Every host wants a Studio login with access to the project; these lists narrow it to addresses.
    public string $site_ips = '';

    public string $previews_ips = '';

    /** @var list<array{branch: string, ips: string}> */
    public array $branch_rules = [];

    // ---- MCP servers in the chats of the project

    public ?int $mcp_id = null;

    public string $mcp_name = '';

    public string $mcp_url = '';

    public string $mcp_header_name = 'Authorization';

    /** Empty while editing means "keep the stored value". */
    public string $mcp_header_value = '';

    public bool $mcp_has_secret = false;

    /** @var list<string> */
    public array $mcp_roles = ProjectMcpServer::DEFAULT_ROLES;

    public function mount(): void
    {
        $this->authorize('create', Project::class);
        $this->php_version = self::defaultPhp();
    }

    /** 8.4 where the server has it, otherwise the newest PHP it has (Ubuntu 26.04 ships only 8.5). */
    public static function defaultPhp(): string
    {
        $versions = array_values(array_map('strval', (array) config('studio.php_versions')));

        if ($versions === [] || in_array('8.4', $versions, true)) {
            return '8.4';
        }

        usort($versions, fn (string $a, string $b): int => version_compare($a, $b));

        return (string) end($versions);
    }

    public function create(): void
    {
        $this->resetForm();

        $token = auth()->user()->git_token;
        $owners = $token ? GitProvider::current()->owners($token) : [];
        $this->owners = array_map(fn ($value, $label) => ['value' => (string) $value, 'label' => $label], array_keys($owners), array_values($owners));

        Flux::modal('project-form')->show();
    }

    public function edit(int $projectId): void
    {
        $project = Project::query()->findOrFail($projectId);

        $this->resetForm();

        $this->editingId = $project->id;
        $this->name = $project->name;
        $this->slug = $project->slug;
        $this->repo_url = $project->repo_url;
        $this->default_branch = $project->default_branch;
        $this->php_version = $project->php_version;
        $this->db_engine = $project->db_engine;
        $this->members = array_values(array_map(intval(...), $project->members()->pluck('users.id')->all()));

        Flux::modal('project-form')->show();
    }

    public function save(): void
    {
        $this->slug = Str::slug($this->slug ?: $this->name);

        $creatingRepository = $this->editingId === null && $this->source === 'new';

        if ($creatingRepository && $this->new_name === '') {
            $this->new_name = $this->slug;
        }

        $git = GitProvider::current();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:'.Project::SLUG_MAX, 'regex:/^[a-z0-9][a-z0-9-]*$/', 'not_in:studio,www,mail', Rule::unique('projects', 'slug')->ignore($this->editingId), function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && ($host = Project::previewHostShadowedBy($value)) !== null) {
                    $fail(__('":slug" would take the host of an existing workspace preview (:host).', ['slug' => $value, 'host' => $host]));
                }
            }],
            'repo_url' => [Rule::requiredIf(! $creatingRepository), 'nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) use ($git): void {
                if (is_string($value) && $value !== '' && $git->repositoryPath($value) === null) {
                    $fail(__('Use the HTTPS address of a :provider repository, like :example', ['provider' => $git->label(), 'example' => $git->repositoryExample()]));
                }
            }],
            'new_owner' => [Rule::requiredIf($creatingRepository && ! $git->hasPersonalNamespace()), 'nullable', 'string', 'max:200', Rule::in(['', ...array_column($this->owners, 'value')])],
            'new_name' => [Rule::requiredIf($creatingRepository), 'nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'default_branch' => ['required', 'string', 'max:200', 'regex:#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', 'not_regex:#(\.\.|\.lock$)#'],
            'php_version' => ['required', Rule::in((array) config('studio.php_versions'))],
            'db_engine' => ['required', Rule::in(['mariadb', 'pgsql'])],
            'members' => ['array'],
            'members.*' => ['integer', Rule::exists('users', 'id')],
        ], [
            'new_owner.required' => __('Choose where the repository goes: :what.', ['what' => Str::lower($git->ownerLabel())]),
            'slug.max' => __('The slug becomes a Linux user and a host label: :max characters at most.', ['max' => Project::SLUG_MAX]),
            'default_branch.regex' => __('A branch name: letters, digits, dots, dashes, underscores and slashes.'),
            'default_branch.not_regex' => __('A branch name: letters, digits, dots, dashes, underscores and slashes.'),
        ]);

        $attributes = Arr::except($validated, ['members', 'new_owner', 'new_name']);
        $settings = null;

        if ($creatingRepository) {
            $token = auth()->user()->git_token;

            if (! $token) {
                $this->addError('new_name', __('Connect your :provider account in Settings → Connections to create repositories.', ['provider' => $git->label()]));

                return;
            }

            try {
                $repo = $git->createRepository($token, $this->new_owner ?: null, $this->new_name, $this->new_private);
            } catch (RuntimeException $e) {
                $this->addError('new_name', $e->getMessage());

                return;
            }

            $attributes['repo_url'] = $repo['clone_url'];
            $settings = ['bootstrap' => true, 'repository' => $repo['html_url']];
        }

        if ($this->editingId) {
            $project = Project::query()->findOrFail($this->editingId);
            $project->update($attributes);
        } else {
            $project = Project::query()->create([
                ...$attributes,
                'deploy_user_id' => auth()->id(),
                'site_status' => SiteStatus::Provisioning,
                'webhook_secret' => Str::random(40),
                'settings' => $settings,
            ]);

            ProvisionProjectSite::dispatch($project);
        }

        $project->members()->sync($this->members);

        Flux::modal('project-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId ? __('Project updated.') : ($creatingRepository ? __('Repository created. Laravel, Boost and Larapilot are being installed and pushed; the site follows.') : __('Project created. The site is being set up.')));

        $this->resetForm();
    }

    public function deploy(int $projectId): void
    {
        $project = Project::query()->findOrFail($projectId);
        $this->authorize('update', $project);

        if ($project->site_status->isTransitional()) {
            Flux::toast(variant: 'warning', text: __('The server is already working on :name.', ['name' => $project->name]));

            return;
        }

        if (! $project->deploy_user_id) {
            $project->forceFill(['deploy_user_id' => auth()->id()])->save();
        }

        $creating = $project->deployed_at === null;
        DeployProjectSite::dispatch($project);

        Flux::toast(text: $creating
            ? __('Setting up the site for :name…', ['name' => $project->name])
            : __('Deploy queued for :name.', ['name' => $project->name]));
    }

    /**
     * The row stays, marked "deleting", until the queued job has taken the site and
     * every workspace off the server: the job needs it, and deleting it here first
     * would leave all of that behind.
     */
    public function delete(int $projectId): void
    {
        $project = Project::query()->findOrFail($projectId);
        $this->authorize('delete', $project);

        if ($project->site_status === SiteStatus::Deleting) {
            return;
        }

        $project->forceFill(['site_status' => SiteStatus::Deleting, 'last_error' => null])->save();
        DestroyProjectSite::dispatch($project);

        Flux::toast(text: __('Deleting :name: its site and workspaces are being removed from the server.', ['name' => $project->name]));
    }

    public function settings(int $projectId): void
    {
        $project = Project::query()->findOrFail($projectId);
        $this->authorize('update', $project);
        $this->resetValidation();

        $features = $project->features();
        $access = $project->access();

        $this->settingsId = $project->id;
        $this->scheduler = $features['scheduler'];
        $this->queues = implode(', ', $features['queues']);
        $this->reverb = $features['reverb'];
        $this->horizon = $features['horizon'];
        $this->pulse = $features['pulse'];
        $this->site_ips = implode("\n", $access['site']['ips']);
        $this->previews_ips = implode("\n", $access['previews']['ips']);
        $this->branch_rules = array_map(fn (array $rule) => ['branch' => $rule['branch'], 'ips' => implode(', ', $rule['ips'])], $access['branches']);

        $this->settingsTab = 'server';
        $this->cancelMcp();

        Flux::modal('project-settings')->show();
    }

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
        $project = Project::query()->findOrFail($this->settingsId);
        $this->authorize('update', $project);

        $this->validate([
            'queues' => ['required', 'string', 'regex:/^\s*[a-z0-9][a-z0-9_-]*(\s*,\s*[a-z0-9][a-z0-9_-]*)*\s*$/'],
            'site_ips' => ['nullable', 'string', 'max:4000'],
            'previews_ips' => ['nullable', 'string', 'max:4000'],
            'branch_rules' => ['array', 'max:50'],
            'branch_rules.*.branch' => ['required', 'string', 'max:200', 'regex:#^[A-Za-z0-9][A-Za-z0-9._/-]*$#'],
            'branch_rules.*.ips' => ['required', 'string', 'max:4000'],
        ], [
            'queues.regex' => __('Queue names: lowercase letters, digits, dashes and underscores, separated by commas.'),
            'branch_rules.*.branch.required' => __('Name the branch or remove the rule.'),
            'branch_rules.*.ips.required' => __('List the addresses for this branch, or remove the rule.'),
        ]);

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

        $project->update(['settings' => array_merge($project->settings ?? [], [
            'features' => ['scheduler' => $this->scheduler, 'queues' => $this->queueList(), 'reverb' => $this->reverb, 'horizon' => $this->horizon, 'pulse' => $this->pulse],
            'access' => [
                'site' => ['ips' => $this->ipList($this->site_ips)],
                'previews' => ['ips' => $this->ipList($this->previews_ips)],
                'branches' => array_map(fn (array $rule) => ['branch' => trim($rule['branch']), 'ips' => $this->ipList($rule['ips'])], $this->branch_rules),
            ],
        ])]);

        if ($project->site_status !== SiteStatus::New || $project->workspaces()->exists()) {
            ConfigureProjectSite::dispatch($project);
        }

        Flux::modal('project-settings')->close();
        Flux::toast(variant: 'success', text: __('Settings saved. They are being applied to the site and the workspaces.'));
    }

    /** Add a server to the project, or save the one being edited; applies to every chat's next message. */
    public function saveMcp(): void
    {
        $project = Project::query()->findOrFail($this->settingsId);
        $this->authorize('update', $project);
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

    public function editMcp(int $serverId): void
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

    public function toggleMcp(int $serverId): void
    {
        $server = $this->mcpServer($serverId);
        $server->update(['enabled' => ! $server->enabled]);
    }

    public function deleteMcp(int $serverId): void
    {
        $this->mcpServer($serverId)->delete();

        if ($this->mcp_id === $serverId) {
            $this->cancelMcp();
        }
    }

    /** One initialize call to the server, with its header, as Claude Code would make it. */
    public function testMcp(int $serverId, McpProbe $probe): void
    {
        $server = $this->mcpServer($serverId);
        $server->update(['status' => $probe->check($server->url, $server->headers ?? []), 'checked_at' => now()]);
    }

    private function mcpServer(int $serverId): ProjectMcpServer
    {
        $project = Project::query()->findOrFail($this->settingsId);
        $this->authorize('update', $project);

        return $project->mcpServers()->findOrFail($serverId);
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

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'slug', 'repo_url', 'default_branch', 'db_engine', 'members', 'source', 'new_owner', 'new_name', 'new_private']);
        $this->php_version = self::defaultPhp();
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.admin.projects', [
            'projects' => Project::query()->with('deployUser')->withCount(['members', 'workspaces'])->orderBy('name')->get(),
            'mcpServers' => $this->settingsId ? ProjectMcpServer::query()->where('project_id', $this->settingsId)->orderBy('name')->get() : collect(),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email', 'role']),
            'domain' => config('studio.domain'),
            'phpVersions' => (array) config('studio.php_versions'),
            'deployerHasGit' => auth()->user()->hasGit(),
            'git' => GitProvider::current(),
        ]);
    }
}
