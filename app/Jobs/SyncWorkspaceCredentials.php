<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\NativeDriver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** After a person changes a token, every native workspace they own gets it. */
class SyncWorkspaceCredentials implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public User $user) {}

    public function handle(NativeDriver $driver): void
    {
        $this->user->workspaces()
            ->where('driver', 'native')
            ->whereNotNull('path')
            ->each(fn (Workspace $workspace) => $driver->syncCredentials($workspace));
    }
}
