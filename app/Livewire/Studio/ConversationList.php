<?php

namespace App\Livewire\Studio;

use App\Actions\Studio\DeleteConversation;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\Workspace;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The conversations on the left of the shell, each with its dot. Its own
 * component so it can follow them: the open one tells it when it moves on, the
 * others are polled while any of them works.
 */
class ConversationList extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public Workspace $workspace;

    public ?string $current = null;

    /** The conversation the confirmation asks about. */
    public ?string $deleting = null;

    /** The open conversation moved on (see Chat), or a poll: render() reads the dots again. */
    #[On('conversation-signal')]
    public function refreshList(): void {}

    public function confirmDelete(string $uuid): void
    {
        $this->authorize('delete', $this->find($uuid));

        $this->deleting = $uuid;
        Flux::modal('delete-conversation')->show();
    }

    public function delete(DeleteConversation $delete): void
    {
        $conversation = $this->find((string) $this->deleting);
        $this->authorize('delete', $conversation);

        $delete->handle($conversation);

        $this->deleting = null;
        Flux::modal('delete-conversation')->close();
        Flux::toast(text: __('Conversation deleted.'));

        // the one on screen is gone: back to the project
        if ($conversation->uuid === $this->current) {
            $this->redirectRoute('studio.show', ['project' => $this->project], navigate: true);
        }
    }

    private function find(string $uuid): Conversation
    {
        return $this->workspace->conversations()->where('uuid', $uuid)->firstOrFail();
    }

    public function render(): View
    {
        $conversations = $this->workspace->conversations()
            ->where('user_id', auth()->id())
            ->latest('updated_at')
            ->limit(50)
            ->get();

        return view('livewire.studio.conversation-list', [
            'conversations' => $conversations,
            'busy' => $conversations->contains(fn (Conversation $item) => $item->isBusy()),
            'doomed' => $this->deleting === null ? null : $this->workspace->conversations()->where('uuid', $this->deleting)->first(),
        ]);
    }
}
