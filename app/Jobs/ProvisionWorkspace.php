<?php

namespace App\Jobs;

use App\Enums\WorkspaceStatus;
use App\Models\Workspace;
use App\Workspaces\WorkspaceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProvisionWorkspace implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // clone, composer, npm and the asset build; the helper itself gives up after 20 minutes
    public int $timeout = 1800;

    public function __construct(public Workspace $workspace) {}

    public function handle(WorkspaceManager $manager): void
    {
        // Every visit of the page while the workspace is "to create" queues this job: one creates it.
        if (! in_array($this->workspace->status, [WorkspaceStatus::New, WorkspaceStatus::Failed], true)) {
            return;
        }

        try {
            $manager->driverFor($this->workspace)->provision($this->workspace);
        } catch (Throwable $e) {
            $this->workspace->markFailed($e->getMessage());
        }
    }

    /** The worker stopped the job (its timeout, a restart for an update): without this the page waits on "creating" for ever. */
    public function failed(?Throwable $e): void
    {
        $this->workspace->markFailed(__('The workspace was left halfway: the queue worker stopped or timed out. Press Start to try again.'));
    }
}
