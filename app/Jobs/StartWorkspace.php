<?php

namespace App\Jobs;

use App\Models\Workspace;
use App\Workspaces\WorkspaceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class StartWorkspace implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // a workspace without a clone yet is created here, like ProvisionWorkspace
    public int $timeout = 1800;

    public function __construct(public Workspace $workspace) {}

    public function handle(WorkspaceManager $manager): void
    {
        try {
            $manager->driverFor($this->workspace)->start($this->workspace);
        } catch (Throwable $e) {
            $this->workspace->markFailed($e->getMessage());
        }
    }

    /** The worker stopped the job (its timeout, a restart for an update): the status must not stay "creating". */
    public function failed(?Throwable $e): void
    {
        $this->workspace->markFailed(__('The workspace was left halfway: the queue worker stopped or timed out. Press Start to try again.'));
    }
}
