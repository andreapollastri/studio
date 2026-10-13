<?php

namespace App\Livewire\Studio;

use App\Accounts\Accounts;
use App\Actions\Studio\StartConversation;
use App\Enums\WorkspaceStatus;
use App\Jobs\ProvisionWorkspace;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\Workspace;
use App\Workspaces\Sandboxes;
use App\Workspaces\WorkspaceManager;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The three-pane page of one project: conversations on the left, the chat in
 * the middle, preview / changes / terminal / files on the right.
 */
#[Layout('layouts::studio')]
class Shell extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public Workspace $workspace;

    public ?Conversation $conversation = null;

    public function mount(WorkspaceManager $manager, Project $project, ?Conversation $conversation = null): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
        $this->workspace = $manager->forUser($project, auth()->user());

        if ($conversation !== null) {
            $this->authorize('view', $conversation);
            abort_unless($conversation->workspace_id === $this->workspace->id, 404);
        } else {
            $conversation = $this->workspace->conversations()->where('user_id', auth()->id())->latest('updated_at')->first();
        }

        $this->conversation = $conversation;

        if ($conversation?->user_id === auth()->id()) {
            $conversation->markSeen();
        }

        if ($this->workspace->status === WorkspaceStatus::New && auth()->user()->canChat() && $this->missingConnections() === []) {
            $this->provision();
        }
    }

    /**
     * What the person still has to connect before a workspace on this server
     * can exist: nothing in local development; otherwise an account on the host of
     * the project's repository, and a Claude account.
     *
     * @return list<string>
     */
    public function missingConnections(): array
    {
        $user = auth()->user();

        if (! $user->canChat() || config('studio.driver') !== 'native') {
            return [];
        }

        return array_values(array_filter([
            Accounts::gitAccount($user, $this->project) !== null ? null : 'git',
            $user->hasClaude() ? null : 'claude',
        ]));
    }

    public function provision(): void
    {
        $this->authorize('chat', $this->project);

        if ($this->workspace->claimForProvisioning()) {
            ProvisionWorkspace::dispatch($this->workspace);
        }
        $this->workspace->refresh();
    }

    /** The header's WorkspaceStatus says the workspace moved on: draw the page again with it. */
    #[On('workspace-updated')]
    public function refreshWorkspace(): void
    {
        $this->workspace->refresh();
    }

    public function newConversation(StartConversation $start): void
    {
        $this->authorize('chat', $this->project);

        $conversation = $start->handle($this->workspace, auth()->user());

        $this->redirectRoute('studio.conversation', ['project' => $this->project, 'conversation' => $conversation], navigate: true);
    }

    /** A conversation in a sandbox of its own: its branch, database and preview; it opens once they are ready. */
    public function newSandbox(StartConversation $start, Sandboxes $sandboxes): void
    {
        $this->authorize('chat', $this->project);
        $this->workspace->refresh();

        try {
            $conversation = $sandboxes->open($this->workspace, auth()->user(), $start);
        } catch (InvalidArgumentException $e) {
            Flux::toast(variant: 'warning', text: $e->getMessage());

            return;
        }

        $this->redirectRoute('studio.conversation', ['project' => $this->project, 'conversation' => $conversation], navigate: true);
    }

    /** The open conversation's sandbox became ready or went away: the pane on the right follows it. */
    #[On('sandbox-updated')]
    public function refreshSandbox(): void
    {
        $this->conversation?->refresh();
    }

    public function render(): View
    {
        $sandboxed = $this->conversation?->sandboxReady() && $this->conversation->user_id === auth()->id();

        return view('livewire.studio.shell', [
            'title' => $this->project->name,
            'missing' => $this->missingConnections(),
            'sandboxes' => $this->project->agentSettings()['sandboxes'] && auth()->user()->can('chat', $this->project),
            // the pane works where the open conversation works: its sandbox, or the workspace
            'paneSandbox' => $sandboxed ? $this->conversation->sandboxName() : null,
            'paneSandboxUrl' => $sandboxed ? $this->conversation->previewUrl() : null,
        ]);
    }
}
