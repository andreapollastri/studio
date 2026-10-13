<?php

namespace App\Livewire\Studio;

use App\Models\Conversation;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The conversations on the left of the shell, each with its dot. Its own
 * component so it can follow them: the open one tells it when it moves on, the
 * others are polled while any of them works.
 */
class ConversationList extends Component
{
    public Project $project;

    public Workspace $workspace;

    public ?string $current = null;

    /** The open conversation moved on (see Chat), or a poll: render() reads the dots again. */
    #[On('conversation-signal')]
    public function refreshList(): void {}

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
        ]);
    }
}
