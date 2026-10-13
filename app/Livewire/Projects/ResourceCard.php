<?php

namespace App\Livewire\Projects;

use App\Infrastructure\Summary;
use App\Models\ProjectResource;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One attached resource on the project's overview: read after the page, each on its own, so a
 * slow provider never holds up the others.
 */
#[Lazy]
class ResourceCard extends Component
{
    #[Locked]
    public string $resource = '';

    public function mount(string $resource): void
    {
        $this->resource = $resource;
        abort_unless($this->item()->isVisibleTo(auth()->user()), 403);
    }

    private function item(): ProjectResource
    {
        return once(fn () => ProjectResource::query()->with(['account', 'project'])->where('uuid', $this->resource)->firstOrFail());
    }

    public function refresh(): void
    {
        Summary::forget($this->item());
    }

    public function placeholder(): View
    {
        return view('livewire.projects.resource-card-placeholder');
    }

    public function render(): View
    {
        return view('livewire.projects.resource-card', ['item' => $this->item(), 'card' => Summary::of($this->item())]);
    }
}
