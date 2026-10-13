<?php

namespace App\Jobs;

use App\Accounts\Accounts;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\NativeDriver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * After a person changes their git accounts or what a project or group works
 * with, their native workspaces get the account that is theirs now. Forced
 * (a token changed) every workspace is rewritten; otherwise only those whose
 * account moved, since each rewrite restarts that workspace's bridge.
 */
class SyncWorkspaceCredentials implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  list<int>|null  $projectIds  only the workspaces of these projects */
    public function __construct(public User $user, public bool $force = true, public ?array $projectIds = null) {}

    public function handle(NativeDriver $driver): void
    {
        $this->user->workspaces()
            ->with('project')
            ->where('driver', 'native')
            ->whereNotNull('path')
            ->when($this->projectIds !== null, fn ($query) => $query->whereIn('project_id', $this->projectIds))
            ->each(function (Workspace $workspace) use ($driver) {
                if ($this->force || Accounts::git($this->user, $workspace->project)?->account->id !== $workspace->git_account_id) {
                    $driver->syncCredentials($workspace);
                }
            });
    }
}
