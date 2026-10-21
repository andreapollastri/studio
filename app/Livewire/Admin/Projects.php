<?php

namespace App\Livewire\Admin;

use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Git\GitAccount;
use App\Jobs\DeployProjectSite;
use App\Jobs\DestroyProjectSite;
use App\Jobs\ProvisionProjectSite;
use App\Livewire\Projects\Settings;
use App\Models\ConnectorAccount;
use App\Models\Project;
use App\Models\User;
use App\Server\PhpVersions;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Every project of the installation, for the administrators: create one (from a
 * repository on any git host, or a new repository with Laravel, Boost and
 * Larapilot in it), deploy its site, delete it. Its settings, members and roles
 * are on the project's own Settings page.
 */
#[Layout('layouts::app')]
#[Title('Projects · Administration')]
class Projects extends Component
{
    use AuthorizesRequests;

    public string $name = '';

    public string $repo_url = '';

    public string $default_branch = 'develop';

    public string $php_version = '8.5';

    public string $db_engine = 'mariadb';

    /** @var array<string, string> user uuid => '' (not on it) | pm | dev | client */
    public array $roles = [];

    /**
     * uuid of the git account the repository is created or imported with, on any git
     * host: a shared service account, or one of the administrator's own. It registers
     * the push webhook and deploys the site.
     */
    public string $git_account = '';

    /** "existing" points at a repository; "new" creates one on the git host with Laravel, Boost and Larapilot in it. */
    public string $source = 'existing';

    public string $new_owner = '';

    public string $new_name = '';

    public bool $new_private = true;

    /**
     * Where the chosen account may create repositories: organizations, groups, workspaces or projects.
     *
     * @var list<array{value: string, label: string}>
     */
    public array $owners = [];

    public string $search = '';

    public function mount(): void
    {
        $this->authorize('create', Project::class);
        $this->php_version = self::defaultPhp();
    }

    /** The release's own PHP (8.5) where the server has it, otherwise the newest PHP it has. */
    public static function defaultPhp(): string
    {
        return app(PhpVersions::class)->default();
    }

    public function create(): void
    {
        $this->resetForm();

        // a service account first, else the administrator's oldest
        $this->git_account = (string) $this->gitAccounts()->first()?->uuid;
        $this->roles = User::query()->where('role', '!=', UserRole::Admin->value)->pluck('uuid')->mapWithKeys(fn ($uuid) => [(string) $uuid => ''])->all();
        $this->loadOwners();

        Flux::modal('project-form')->show();
    }

    public function updatedGitAccount(): void
    {
        $this->new_owner = '';
        $this->loadOwners();
    }

    /** An address typed in picks an account on its host, when the one chosen is elsewhere. */
    public function updatedRepoUrl(): void
    {
        if ($this->repo_url === '' || $this->selectedAccount()?->account->matchesRemote($this->repo_url)) {
            return;
        }

        if ($match = $this->gitAccounts()->first(fn (ConnectorAccount $a) => $a->matchesRemote($this->repo_url))) {
            $this->git_account = $match->uuid;
            $this->loadOwners();
        }
    }

    /** Where the chosen account may create repositories: organizations, groups, workspaces or projects. */
    private function loadOwners(): void
    {
        $owners = [];

        if ($account = $this->selectedAccount()) {
            try {
                $owners = $account->provider()->owners($account->token());
            } catch (RuntimeException) {
                $owners = [];
            }
        }

        $this->owners = array_map(fn ($value, $label) => ['value' => (string) $value, 'label' => $label], array_keys($owners), array_values($owners));
    }

    /**
     * The git accounts a project may be created or imported with: the service accounts, then the administrator's own.
     *
     * @return Collection<int, ConnectorAccount>
     */
    private function gitAccounts(): Collection
    {
        return Settings::deployAccounts(auth()->user());
    }

    private function selectedAccount(): ?GitAccount
    {
        $account = $this->gitAccounts()->firstWhere('uuid', $this->git_account);

        return $account ? new GitAccount($account) : null;
    }

    public function save(): void
    {
        $creatingRepository = $this->source === 'new';
        $key = Project::newKey($this->name);

        if ($creatingRepository && $this->new_name === '') {
            $this->new_name = Str::slug($this->name) ?: $key;
        }

        $account = $this->selectedAccount();
        $git = $account?->provider();

        $validated = $this->validate([
            'git_account' => ['required', Rule::in($this->gitAccounts()->pluck('uuid')->all())],
            'name' => ['required', 'string', 'max:120'],
            'repo_url' => [Rule::requiredIf(! $creatingRepository), 'nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) use ($git, $account): void {
                if (is_string($value) && $value !== '' && ($account === null || $git?->repositoryPath($value) === null || ! $account->account->matchesRemote($value))) {
                    $fail(__('Use the HTTPS address of a repository on :host, the host of the account chosen, like :example', ['host' => $account?->host() ?? __('its host'), 'example' => $git?->repositoryExample() ?? 'https://github.com/acme/shop.git']));
                }
            }],
            'new_owner' => [Rule::requiredIf($creatingRepository && $git !== null && ! $git->hasPersonalNamespace()), 'nullable', 'string', 'max:200', Rule::in(['', ...array_column($this->owners, 'value')])],
            'new_name' => [Rule::requiredIf($creatingRepository), 'nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'default_branch' => ['required', 'string', 'max:200', 'regex:#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', 'not_regex:#(\.\.|\.lock$)#'],
            'php_version' => ['required', Rule::in((array) config('studio.php_versions'))],
            'db_engine' => ['required', Rule::in(['mariadb', 'pgsql'])],
            'roles' => ['array'],
            'roles.*' => ['nullable', Rule::in(['', ...array_map(fn (UserRole $r) => $r->value, UserRole::projectRoles())])],
        ], [
            'git_account.required' => __('Add a git account on the repository\'s host first: a service account in Administration → Connectors, or yours in Settings → Accounts.'),
            'git_account.in' => __('Add a git account on the repository\'s host first: a service account in Administration → Connectors, or yours in Settings → Accounts.'),
            'new_owner.required' => __('Choose where the repository goes: :what.', ['what' => Str::lower((string) $git?->ownerLabel())]),
            'default_branch.regex' => __('A branch name: letters, digits, dots, dashes, underscores and slashes.'),
            'default_branch.not_regex' => __('A branch name: letters, digits, dots, dashes, underscores and slashes.'),
        ]);

        $attributes = [
            ...Arr::only($validated, ['name', 'repo_url', 'default_branch', 'php_version', 'db_engine']),
            'slug' => $key,
            'git_account_id' => $account?->account->id,
        ];
        $settings = null;

        if ($creatingRepository && $account && $git) {
            try {
                $repo = $git->createRepository($account->token(), $this->new_owner ?: null, $this->new_name, $this->new_private);
            } catch (RuntimeException $e) {
                $this->addError('new_name', $e->getMessage());

                return;
            }

            $attributes['repo_url'] = $repo['clone_url'];
            $settings = ['bootstrap' => true, 'repository' => $repo['html_url']];
        }

        $project = Project::query()->create([
            ...$attributes,
            'deploy_user_id' => $account?->account->user_id ?? auth()->id(),
            'site_status' => SiteStatus::Provisioning,
            'webhook_secret' => Str::random(40),
            'settings' => $settings,
        ]);

        $ids = User::query()->whereIn('uuid', array_keys(array_filter($this->roles)))->pluck('id', 'uuid');
        $project->syncMembers(collect(array_filter($this->roles))->mapWithKeys(fn (string $role, string $uuid) => isset($ids[$uuid]) ? [$ids[$uuid] => $role] : [])->all());

        ProvisionProjectSite::dispatch($project);

        Flux::modal('project-form')->close();
        Flux::toast(variant: 'success', text: $creatingRepository
            ? __('Project :key created. Laravel, Boost and Larapilot are being installed and pushed; the site follows.', ['key' => $project->slug])
            : __('Project :key created. The site is being set up.', ['key' => $project->slug]));

        $this->resetForm();
    }

    public function deploy(string $project): void
    {
        $project = Project::query()->where('uuid', $project)->firstOrFail();
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
    public function delete(string $project): void
    {
        $project = Project::query()->where('uuid', $project)->firstOrFail();
        $this->authorize('delete', $project);

        if ($project->site_status === SiteStatus::Deleting) {
            return;
        }

        $project->forceFill(['site_status' => SiteStatus::Deleting, 'last_error' => null])->save();
        DestroyProjectSite::dispatch($project);

        Flux::toast(text: __('Deleting :name: its site and workspaces are being removed from the server.', ['name' => $project->name]));
    }

    private function resetForm(): void
    {
        $this->reset(['name', 'repo_url', 'default_branch', 'db_engine', 'roles', 'source', 'new_owner', 'new_name', 'new_private', 'git_account', 'owners']);
        $this->php_version = self::defaultPhp();
        $this->resetValidation();
    }

    public function render(): View
    {
        $git = $this->selectedAccount()?->provider();

        return view('livewire.admin.projects', [
            'projects' => Project::query()->with(['deployUser', 'repositoryAccount'])->withCount(['members', 'workspaces', 'resources'])
                ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%')->orWhere('slug', 'like', '%'.trim($this->search).'%')->orWhere('repo_url', 'like', '%'.trim($this->search).'%')))
                ->orderBy('name')->get(),
            'people' => User::query()->where('role', '!=', UserRole::Admin->value)->orderBy('name')->get(),
            'domain' => config('studio.domain'),
            'phpVersions' => (array) config('studio.php_versions'),
            'gitAccounts' => $this->gitAccounts(),
            'git' => $git,
            'providerLabel' => $git?->label() ?? __('the git host'),
        ]);
    }
}
