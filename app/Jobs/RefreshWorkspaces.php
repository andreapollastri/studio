<?php

namespace App\Jobs;

use App\Enums\WorkspaceStatus;
use App\Models\Workspace;
use App\Workspaces\WorkspaceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Periodic sync of every native workspace that is not deleted, so the
 * status pill follows a bridge that died or a server that rebooted.
 */
class RefreshWorkspaces implements ShouldQueue
{
    use Queueable;

    /** Longer than any workspace job may run (the worker's timeout): a status older than this is left over. */
    public const STUCK_AFTER_MINUTES = 35;

    public function handle(WorkspaceManager $manager): void
    {
        // "creating", "starting" or "stopping" from a job that died without a word: the page would wait for ever.
        Workspace::query()
            ->where('driver', 'native')
            ->whereIn('status', [WorkspaceStatus::Creating->value, WorkspaceStatus::Starting->value, WorkspaceStatus::Stopping->value])
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->eachById(fn (Workspace $workspace) => $workspace->markFailed(__('The workspace was left halfway: the queue worker stopped or timed out. Press Start to try again.')));

        Workspace::query()
            ->where('driver', 'native')
            ->whereNot('status', WorkspaceStatus::Deleted->value)
            ->whereNotNull('path')
            ->each(fn (Workspace $workspace) => $manager->driverFor($workspace)->refresh($workspace));
    }
}
