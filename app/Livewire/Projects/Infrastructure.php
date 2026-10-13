<?php

namespace App\Livewire\Projects;

use App\Enums\UserRole;
use App\Infrastructure\ConnectorException;
use App\Infrastructure\Connectors;
use App\Infrastructure\Section;
use App\Mcp\NativeServers;
use App\Models\BackupJob;
use App\Models\ConnectorAccount;
use App\Models\Project;
use App\Models\ProjectResource;
use App\Models\SshConnection;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * The infrastructure of a project: an overview of everything attached to it (each server, zone,
 * app, bucket and tracker at a glance, the backups, the MCP servers its chats get), then each
 * one on its own: a Cipi app as a hub (releases, logs, .env, database, agent), anything else as
 * its row of the connector's list. Shown to the roles that see it, with the actions handed to
 * the roles that act on it. Administrators attach things from here or from the connectors'
 * pages, change who sees what, and take things off.
 *
 * @property-read Collection<int, ConnectorAccount> $attachAccounts
 * @property-read list<Section> $attachSections
 * @property-read array{rows: array<string, array{0: string, 1: string}>, error: string|null} $attachRows
 * @property-read array<string, array{label: string, danger: bool, list: string}> $attachActionChoices
 */
#[Layout('layouts::studio')]
class Infrastructure extends Component
{
    public const OVERVIEW = 'overview';

    public Project $project;

    /** uuid of the resource open, or "overview" */
    #[Url]
    public ?string $resource = null;

    // ---- the resource whose roles are being changed

    public ?string $editing = null;

    /** @var list<string> */
    public array $view_roles = [];

    /** @var list<string> */
    public array $act_roles = [];

    /** @var list<string> */
    public array $actions = [];

    public string $label = '';

    // ---- attaching from here: an account, one of its lists, a row of it

    public string $attachAccount = '';

    public string $attachSection = '';

    public string $attachSearch = '';

    public ?string $attachRow = null;

    public string $attach_label = '';

    /** @var list<string> */
    public array $attach_view = ['pm', 'dev'];

    /** @var list<string> */
    public array $attach_act = ['dev'];

    /** @var list<string> */
    public array $attach_actions = [];

    public function mount(Project $project): void
    {
        abort_unless(auth()->user()->canAccess($project), 403);

        $this->project = $project;
        $visible = $this->resources();

        if ($this->resource !== self::OVERVIEW && ($this->resource === null || $visible->doesntContain('uuid', $this->resource))) {
            $this->resource = self::OVERVIEW;
        }
    }

    /** @return Collection<int, ProjectResource> */
    private function resources(): Collection
    {
        return $this->project->resources()->visibleTo(auth()->user())->with(['account', 'project'])->orderBy('label')->get()
            ->filter(fn (ProjectResource $r) => $r->connector() !== null)->values();
    }

    public function open(string $uuid): void
    {
        $this->resource = $uuid === self::OVERVIEW ? self::OVERVIEW : ($this->resources()->firstWhere('uuid', $uuid)->uuid ?? $this->resource);
    }

    public function edit(string $uuid): void
    {
        abort_unless(auth()->user()->can('manage', $this->project), 403);

        $item = $this->project->resources()->where('uuid', $uuid)->firstOrFail();

        $this->editing = $item->uuid;
        $this->label = $item->label;
        $this->view_roles = $item->view_roles;
        $this->act_roles = $item->act_roles;
        $this->actions = $item->actions;
        $this->resetValidation();

        Flux::modal('resource-roles')->show();
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('manage', $this->project), 403);

        $item = $this->project->resources()->where('uuid', (string) $this->editing)->firstOrFail();
        $roles = array_map(fn (UserRole $role) => $role->value, UserRole::projectRoles());
        $keys = ($connector = $item->connector()) && ($section = $item->definition()) ? array_keys(ProjectResource::actionsFor($connector, $section)) : [];

        $this->validate([
            'label' => ['required', 'string', 'max:160'],
            'view_roles' => ['array'],
            'view_roles.*' => [Rule::in($roles)],
            'act_roles' => ['array'],
            'act_roles.*' => [Rule::in($roles)],
            'actions' => ['array'],
            'actions.*' => [Rule::in($keys)],
        ]);

        $item->update(['label' => trim($this->label), 'view_roles' => $this->view_roles, 'act_roles' => $this->act_roles, 'actions' => $this->actions]);

        Flux::modal('resource-roles')->close();
        Flux::toast(variant: 'success', text: __('Saved: it applies from each person\'s next click.'));
        $this->editing = null;
    }

    public function detach(string $uuid): void
    {
        abort_unless(auth()->user()->can('manage', $this->project), 403);

        $item = $this->project->resources()->where('uuid', $uuid)->firstOrFail();
        $item->delete();

        Log::info('infrastructure', ['user' => auth()->id(), 'action' => 'detach', 'project' => $this->project->uuid, 'project_resource' => $item->uuid]);

        if ($this->resource === $uuid) {
            $this->resource = self::OVERVIEW;
        }

        Flux::modal('resource-roles')->close();
        Flux::toast(text: __(':label is no longer in the project. Nothing changed at the provider.', ['label' => $item->label]));
    }

    // ------------------------------------------------------------------ attach from here

    /**
     * The administrators' accounts of the connectors that are on.
     *
     * @return Collection<int, ConnectorAccount>
     */
    #[Computed]
    public function attachAccounts(): Collection
    {
        $keys = array_map(fn ($c) => $c->key(), Connectors::visibleTo(auth()->user()));

        return ConnectorAccount::query()->whereNull('user_id')->whereIn('connector', $keys)->orderBy('connector')->orderBy('name')->get();
    }

    /**
     * The lists of the chosen account a project can hold a row of: its own lists, not overviews or child lists.
     *
     * @return list<Section>
     */
    #[Computed]
    public function attachSections(): array
    {
        $account = $this->attachAccounts->firstWhere('uuid', $this->attachAccount);
        $connector = $account ? Connectors::find($account->connector) : null;

        return $connector === null ? [] : array_values(array_filter($connector->sections(), fn (Section $s) => $s->kind === 'table' && ! $s->isChild() && $s->list !== null));
    }

    private function attachSection(): ?Section
    {
        foreach ($this->attachSections as $section) {
            if ($section->key === $this->attachSection) {
                return $section;
            }
        }

        return null;
    }

    /** @return array<string, string> the list's filters at their defaults (a region…) */
    private function attachScope(): array
    {
        $scope = [];

        foreach ($this->attachSection()->filters ?? [] as $field) {
            $scope[$field->name] = (string) ($field->defaultFor([]) ?? '');
        }

        return array_filter($scope, fn ($v) => $v !== '');
    }

    /**
     * The rows of the chosen list, as id => [label, detail], or why they could not be read.
     *
     * @return array{rows: array<string, array{0: string, 1: string}>, error: string|null}
     */
    #[Computed]
    public function attachRows(): array
    {
        $account = $this->attachAccounts->firstWhere('uuid', $this->attachAccount);
        $section = $this->attachSection();

        if ($account === null || $section === null) {
            return ['rows' => [], 'error' => null];
        }

        try {
            $rows = decrypt(Cache::remember($account->cacheKey('rows', $section->key, (string) json_encode($this->attachScope())), 30, fn () => encrypt(($section->list)($account->client(), $this->attachScope()))));
        } catch (ConnectorException $e) {
            return ['rows' => [], 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return ['rows' => [], 'error' => __('Studio could not make sense of the answer: :reason', ['reason' => $e->getMessage()])];
        }

        $attached = $this->project->resources()->where('connector_account_id', $account->id)->where('section', $section->key)->pluck('resource_id')->all();
        $search = mb_strtolower(trim($this->attachSearch));
        $list = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ($id = $section->rowId($row)) === '' || in_array($id, $attached, true)) {
                continue;
            }

            $label = $section->columns !== [] ? $section->columns[0]->text($row) : $id;
            $detail = collect(array_slice($section->columns, 1, 3))->map(fn ($c) => $c->format === 'bool' ? '' : $c->text($row))->filter()->implode(' · ');

            if ($search === '' || str_contains(mb_strtolower($label.' '.$detail.' '.$id), $search)) {
                $list[$id] = [$label !== '' ? $label : $id, $detail];
            }
        }

        return ['rows' => array_slice($list, 0, 200, true), 'error' => null];
    }

    public function startAttach(): void
    {
        abort_unless(auth()->user()->can('manage', $this->project), 403);

        $this->reset('attachAccount', 'attachSection', 'attachSearch', 'attachRow', 'attach_label', 'attach_view', 'attach_act', 'attach_actions');
        $this->resetValidation();
        Flux::modal('resource-attach')->show();
    }

    public function updatedAttachAccount(): void
    {
        $this->attachSection = (string) ($this->attachSections[0]->key ?? '');
        $this->attachRow = null;
        $this->attach_actions = [];
    }

    public function updatedAttachSection(): void
    {
        $this->attachRow = null;
        $this->attach_actions = [];
    }

    public function pickAttachRow(string $id): void
    {
        $this->attachRow = $id;
        $this->attach_label = mb_substr($this->attachRows['rows'][$id][0] ?? $id, 0, 160);
    }

    /** @return array<string, array{label: string, danger: bool, list: string}> */
    #[Computed]
    public function attachActionChoices(): array
    {
        $account = $this->attachAccounts->firstWhere('uuid', $this->attachAccount);
        $section = $this->attachSection();

        return $account && $section ? ProjectResource::actionsFor(Connectors::make($account->connector), $section) : [];
    }

    /** The usual hand-over: whatever is not destructive. */
    public function safeActions(): void
    {
        $this->attach_actions = array_keys(array_filter($this->attachActionChoices, fn (array $a) => ! $a['danger']));
    }

    public function saveAttach(): void
    {
        abort_unless(auth()->user()->can('manage', $this->project), 403);

        $roles = array_map(fn (UserRole $role) => $role->value, UserRole::projectRoles());
        $account = $this->attachAccounts->firstWhere('uuid', $this->attachAccount);
        $section = $this->attachSection();

        $this->validate([
            'attachAccount' => ['required', Rule::in($this->attachAccounts->pluck('uuid')->all())],
            'attachSection' => ['required', Rule::in(array_map(fn (Section $s) => $s->key, $this->attachSections))],
            'attachRow' => ['required', Rule::in(array_keys($this->attachRows['rows']))],
            'attach_label' => ['required', 'string', 'max:160'],
            'attach_view.*' => [Rule::in($roles)],
            'attach_act.*' => [Rule::in($roles)],
            'attach_actions.*' => [Rule::in(array_keys($this->attachActionChoices))],
        ], ['attachRow.required' => __('Pick what to attach.')]);

        $item = ProjectResource::query()->updateOrCreate([
            'project_id' => $this->project->id,
            'connector_account_id' => $account->id,
            'section' => $section->key,
            'resource_id' => $this->attachRow,
        ], [
            'label' => trim($this->attach_label),
            'scope' => $this->attachScope() === [] ? null : $this->attachScope(),
            'view_roles' => $this->attach_view,
            'act_roles' => $this->attach_act,
            'actions' => $this->attach_actions,
            'added_by' => auth()->id(),
        ]);

        Log::info('infrastructure', ['user' => auth()->id(), 'connector' => $account->connector, 'account' => $account->uuid, 'section' => $section->key, 'action' => 'attach', 'row' => $this->attachRow, 'project' => $this->project->uuid]);

        Flux::modal('resource-attach')->close();
        Flux::toast(variant: 'success', text: __(':label is in the project for the roles chosen.', ['label' => $item->label]));
        $this->resource = $item->uuid;
    }

    // ------------------------------------------------------------------ the overview

    /** @return Collection<int, BackupJob> */
    private function backups(): Collection
    {
        return auth()->user()->isAdmin()
            ? BackupJob::query()->where('project_id', $this->project->id)->with(['latestRun', 'schedules'])->orderBy('name')->get()
            : new Collection;
    }

    public function render(): View
    {
        $resources = $this->resources();
        $editing = $this->editing ? $resources->firstWhere('uuid', $this->editing) ?? $this->project->resources()->where('uuid', $this->editing)->first() : null;
        $current = $this->resource === self::OVERVIEW ? null : $resources->firstWhere('uuid', $this->resource);
        $user = auth()->user();

        return view('livewire.projects.infrastructure', [
            'title' => __(':project · Infrastructure', ['project' => $this->project->name]),
            'resources' => $resources,
            'current' => $current,
            'overview' => $current === null,
            'connectors' => $user->isAdmin() ? Connectors::visibleTo($user) : [],
            'editingActions' => $editing && ($c = $editing->connector()) && ($s = $editing->definition()) ? ProjectResource::actionsFor($c, $s) : [],
            'backups' => $current === null ? $this->backups() : new Collection,
            'mcp' => $current === null ? array_keys(NativeServers::for($this->project, $user)) : [],
            'sshCount' => $current === null ? SshConnection::query()->visibleTo($user)->where('project_id', $this->project->id)->count() : 0,
        ]);
    }
}
