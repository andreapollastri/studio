<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Workspaces\Sandboxes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Prepare a conversation's sandbox: worktree, database, build and preview (see Sandboxes). */
class ProvisionSandbox implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // composer, npm and the asset build in the worktree, as for a workspace
    public int $timeout = 1800;

    public function __construct(public Conversation $conversation) {}

    public function handle(Sandboxes $sandboxes): void
    {
        if ($this->conversation->sandboxStatus() !== Conversation::SANDBOX_CREATING) {
            return;
        }

        $sandboxes->provision($this->conversation);
    }

    /** The worker stopped the job: without this the chat waits on "preparing" for ever. */
    public function failed(?Throwable $e): void
    {
        $this->conversation->updateSandbox([
            'status' => Conversation::SANDBOX_FAILED,
            'error' => __('The sandbox was left halfway: the queue worker stopped or timed out. Try again.'),
        ]);
    }
}
