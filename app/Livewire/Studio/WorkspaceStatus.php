<?php

namespace App\Livewire\Studio;

use App\Enums\WorkspaceStatus as Status;
use App\Jobs\ProvisionWorkspace;
use App\Jobs\StartWorkspace;
use App\Jobs\StopWorkspace;
use App\Models\Workspace;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The workspace badge and its Start / Stop in the header of a project page.
 * It lives in the layout's header, outside the page component, so it is a
 * component of its own: it follows the status through Reverb and, while the
 * workspace is on its way somewhere, by polling; a change tells the rest of
 * the page (chat, preview) with a `workspace-updated` event.
 */
class WorkspaceStatus extends Component
{
    use AuthorizesRequests;

    public Workspace $workspace;

    /** The status the page was last told about. */
    public string $seen = '';

    public function mount(Workspace $workspace): void
    {
        $this->workspace = $workspace;
        $this->seen = $workspace->status->value;
    }

    public function provision(): void
    {
        $this->authorize('chat', $this->workspace->project);

        if ($this->workspace->claimForProvisioning()) {
            ProvisionWorkspace::dispatch($this->workspace);
        }
        $this->workspace->refresh();
        $this->announce();
    }

    public function start(): void
    {
        $this->authorize('chat', $this->workspace->project);

        // Transitional at once: the badge says so and polling starts before the queue picks the job up.
        $this->workspace->forceFill(['status' => $this->workspace->path ? Status::Starting : Status::Creating, 'last_error' => null])->save();
        StartWorkspace::dispatch($this->workspace);
        $this->announce();
        Flux::toast(text: __('Starting the workspace…'));
    }

    public function stop(): void
    {
        $this->authorize('chat', $this->workspace->project);

        $this->workspace->forceFill(['status' => Status::Stopping])->save();
        StopWorkspace::dispatch($this->workspace);
        $this->announce();
    }

    #[On('echo-private:workspaces.{workspace.uuid},.WorkspaceUpdated')]
    public function refreshStatus(): void
    {
        $this->workspace->refresh();

        if ($this->workspace->status->value !== $this->seen) {
            $this->announce();

            match ($this->workspace->status) {
                Status::Running => Flux::toast(variant: 'success', text: __('The workspace is running.')),
                Status::Failed => Flux::toast(variant: 'danger', text: __('The workspace failed: :error', ['error' => (string) $this->workspace->last_error])),
                default => null,
            };
        }
    }

    public function render(): View
    {
        return view('livewire.studio.workspace-status');
    }

    private function announce(): void
    {
        $this->seen = $this->workspace->status->value;
        $this->dispatch('workspace-updated', status: $this->seen);
    }
}
