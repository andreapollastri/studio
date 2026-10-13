<?php

namespace App\Jobs;

use App\Models\Workspace;
use App\Workspaces\Sandboxes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Remove a conversation's sandbox from the workspace and the server. It carries
 * the workspace and the conversation's id, so it also runs after the
 * conversation itself was deleted.
 */
class RemoveSandbox implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public Workspace $workspace, public int $id, public bool $deleteBranch = false) {}

    public function handle(Sandboxes $sandboxes): void
    {
        $sandboxes->remove($this->workspace, $this->id, $this->deleteBranch);
    }
}
