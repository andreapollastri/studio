<?php

namespace App\Livewire\Projects;

use App\Enums\UserRole;
use App\Infrastructure\Connectors;
use App\Models\Project;
use App\Models\ProjectResource;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The infrastructure of a project: the servers, zones, apps and services an
 * administrator attached to it from the connectors' pages, each shown to the
 * roles that see it, with the actions handed to the roles that act on it.
 * Administrators change who sees what, and take things off, from here too.
 */
#[Layout('layouts::studio')]
class Infrastructure extends Component
{
    public Project $project;

    /** uuid of the resource open */
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

    public function mount(Project $project): void
    {
        abort_unless(auth()->user()->canAccess($project), 403);

        $this->project = $project;
        $visible = $this->resources();

        if ($this->resource === null || $visible->doesntContain('uuid', $this->resource)) {
            $this->resource = $visible->first()?->uuid;
        }
    }

    /** @return Collection<int, ProjectResource> */
    private function resources(): Collection
    {
        return $this->project->resources()->visibleTo(auth()->user())->with('account')->orderBy('label')->get()
            ->filter(fn (ProjectResource $r) => $r->connector() !== null)->values();
    }

    public function open(string $uuid): void
    {
        $this->resource = $this->resources()->firstWhere('uuid', $uuid)->uuid ?? $this->resource;
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
            $this->resource = $this->resources()->first()?->uuid;
        }

        Flux::modal('resource-roles')->close();
        Flux::toast(text: __(':label is no longer in the project. Nothing changed at the provider.', ['label' => $item->label]));
    }

    public function render(): View
    {
        $resources = $this->resources();
        $editing = $this->editing ? $resources->firstWhere('uuid', $this->editing) ?? $this->project->resources()->where('uuid', $this->editing)->first() : null;

        return view('livewire.projects.infrastructure', [
            'title' => __(':project · Infrastructure', ['project' => $this->project->name]),
            'resources' => $resources,
            'current' => $resources->firstWhere('uuid', $this->resource),
            'connectors' => auth()->user()->isAdmin() ? Connectors::visibleTo(auth()->user()) : [],
            'editingActions' => $editing && ($c = $editing->connector()) && ($s = $editing->definition()) ? ProjectResource::actionsFor($c, $s) : [],
        ]);
    }
}
