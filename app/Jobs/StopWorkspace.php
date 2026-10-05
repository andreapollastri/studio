<?php

namespace App\Jobs;

use App\Models\Workspace;
use App\Workspaces\WorkspaceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class StopWorkspace implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Workspace $workspace) {}

    public function handle(WorkspaceManager $manager): void
    {
        try {
            $manager->driverFor($this->workspace)->stop($this->workspace);
        } catch (Throwable $e) {
            $this->workspace->markFailed($e->getMessage());
        }
    }
}
