<?php

namespace App\Workspaces;

use App\Enums\WorkspaceStatus;
use App\Models\Workspace;

/**
 * Development driver: every workspace points at the one bridge configured in
 * `studio.local`, which drives a Claude Code on this machine. Nothing to
 * create or start; "running" is the only honest state.
 */
final class LocalDriver implements WorkspaceDriver
{
    public function provision(Workspace $workspace): void
    {
        $workspace->forceFill([
            'driver' => 'local',
            'status' => WorkspaceStatus::Running,
            'bridge_url' => config('studio.local.bridge_url'),
            'bridge_token' => config('studio.local.bridge_token'),
            'app_url' => config('studio.local.app_url'),
            'last_error' => null,
        ])->save();

        if (! $workspace->callback_token) {
            $workspace->issueCallbackToken();
        }
    }

    public function start(Workspace $workspace): void
    {
        $this->provision($workspace);
    }

    public function stop(Workspace $workspace): void
    {
        $workspace->forceFill(['status' => WorkspaceStatus::Stopped])->save();
    }

    public function destroy(Workspace $workspace): void
    {
        $workspace->forceFill(['status' => WorkspaceStatus::Deleted])->save();
    }

    public function refresh(Workspace $workspace): void
    {
        // Nothing to ask: the local bridge is either reachable or not, and the
        // chat reports that when it tries to talk to it.
    }

    public function configure(Workspace $workspace): void
    {
        // Nothing to apply: the local driver has no server.
    }
}
