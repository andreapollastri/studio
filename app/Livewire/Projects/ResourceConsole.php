<?php

namespace App\Livewire\Projects;

use App\Infrastructure\Action;
use App\Infrastructure\Section;
use App\Livewire\Infrastructure\Console;
use App\Models\ConnectorAccount;
use App\Models\ProjectResource;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * One resource attached to a project, in the project's Infrastructure tab: its
 * row of the connector's list, read with the administrators' account, the child
 * lists it opens, and only the actions handed to the person's role. No tabs, no
 * account picker, no other row of the provider.
 */
class ResourceConsole extends Console
{
    #[Locked]
    public string $resource = '';

    public function mount(string $connector = '', ?string $resource = null): void
    {
        $this->resource = (string) $resource;
        $item = $this->pinned() ?? abort(404);

        abort_unless($item->isVisibleTo(auth()->user()) && $item->connector() !== null, 403);

        $this->connector = $item->account->connector;
        $this->account = $item->account->uuid;
        $this->tab = $item->section;
        $this->scope = [];
        $this->filters = [];
    }

    protected function pinned(): ?ProjectResource
    {
        return once(fn () => ProjectResource::query()->with(['account', 'project'])->where('uuid', $this->resource)->first());
    }

    protected function allowed(): bool
    {
        $item = $this->pinned();

        return $item !== null && $item->isVisibleTo(auth()->user());
    }

    protected function managesAccounts(): bool
    {
        return false;
    }

    protected function pageTitle(): string
    {
        return (string) $this->pinned()?->label;
    }

    /** @return Collection<int, ConnectorAccount> */
    #[Computed]
    public function accounts(): Collection
    {
        return new Collection(array_filter([$this->pinned()?->account]));
    }

    /** The resource's own list, or one of the child lists it opens. */
    public function section(): Section
    {
        $item = $this->pinned();
        $own = $item?->definition() ?? abort(404);

        if ($this->tab !== null && $this->tab !== $own->key) {
            foreach (ProjectResource::children($item->connector(), $own) as $child) {
                if ($child->key === $this->tab) {
                    return $child;
                }
            }
        }

        return $own;
    }

    /** What the list was read with when it was attached (a region…), then the child's scope. */
    protected function context(): array
    {
        return [...($this->pinned()->scope ?? []), ...$this->filters, ...$this->scope];
    }

    /** On the resource's own list, its row alone. */
    protected function visibleRows(Section $section): array
    {
        $rows = parent::visibleRows($section);
        $item = $this->pinned();

        if ($item === null || $section->key !== $item->section) {
            return $rows;
        }

        return array_values(array_filter($rows, fn (array $row) => $section->rowId($row) === $item->resource_id));
    }

    protected function row(string $id): ?array
    {
        $item = $this->pinned();

        // nothing but the resource itself on its own list, even when asked for another row
        if ($item !== null && $this->section()->key === $item->section && $id !== $item->resource_id) {
            return null;
        }

        return parent::row($id);
    }

    /**
     * On the resource's own list, its row actions handed to the person's role, never a list action
     * (creating another Droplet is the account's, not the project's); in a child list it opens, the
     * row and list actions handed over. Opening a child list is seeing the resource.
     */
    protected function permits(Section $section, Action $action, bool $onList): bool
    {
        $item = $this->pinned();

        if ($item === null || ($own = $item->definition()) === null || ($connector = $item->connector()) === null) {
            return false;
        }

        if ($action->opens !== null) {
            return ! $onList && in_array($action->opens, array_map(fn (Section $child) => $child->key, ProjectResource::children($connector, $own)), true);
        }

        if ($section->key === $own->key) {
            return ! $onList && $item->allows(auth()->user(), $action->key);
        }

        return $item->allows(auth()->user(), $section->key.'.'.($onList ? '@' : '').$action->key);
    }

    protected function tabSections(): array
    {
        return [];
    }

    protected function compactHeader(): ?array
    {
        $item = $this->pinned();

        return $item === null ? null : [
            'title' => $item->label,
            'subtitle' => $this->definition()->label().' · '.$item->definition()?->label.' · '.$item->resource_id,
        ];
    }

    public function select(string $tab): void {}

    public function selectGroup(string $group): void {}

    public function useAccount(string $uuid): void {}

    /** From a child list back to the resource. */
    public function back(): void
    {
        $this->tab = $this->pinned()?->section;
        $this->scope = [];
        $this->search = '';
        unset($this->listing);
    }
}
