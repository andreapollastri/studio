<?php

namespace App\Livewire\Studio;

use App\Bridge\BridgeClient;
use App\Files\FileStore;
use App\Livewire\Concerns\ManagesFiles;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The project file manager of the side pane: the workspace read and written
 * through the bridge (the file manager itself is ManagesFiles, shared with
 * the SSH page). Zips are made and extracted by the bridge next to the item.
 */
class Files extends Component
{
    use ManagesFiles;
    use WithFileUploads;

    public Workspace $workspace;

    /** A sandbox's worktree instead of the workspace (the open conversation's). */
    #[Locked]
    public ?string $sandbox = null;

    public function mount(Workspace $workspace, ?string $sandbox = null): void
    {
        $this->workspace = $workspace;
        $this->sandbox = $sandbox;

        abort_unless(self::allows(auth()->user(), $workspace), 403);

        $this->load('');
    }

    /** Who may use this file manager: a project member who chats, on their own workspace (an administrator on any). */
    public static function allows(?User $user, Workspace $workspace): bool
    {
        return $user !== null && $user->can('chat', $workspace->project) && ($workspace->user_id === $user->id || $user->isAdmin());
    }

    public function downloadUrl(string $path): string
    {
        return route('studio.files.download', ['workspace' => $this->workspace->uuid, 'path' => $path, 'sandbox' => $this->sandbox]);
    }

    public function rootLabel(): string
    {
        return (string) __('workspace');
    }

    protected function files(): FileStore
    {
        return new BridgeClient($this->workspace, $this->sandbox);
    }

    protected function unavailable(): ?string
    {
        $this->workspace->refresh();

        return $this->workspace->isRunning() ? null : (string) __('The workspace is not running.');
    }

    protected function reservedTop(): ?string
    {
        return '.git';
    }

    public function render(): View
    {
        return view('livewire.files.manager');
    }
}
