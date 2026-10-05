<?php

namespace App\Workspaces;

use App\Models\Workspace;

/**
 * Where a workspace lives and how it is started: a Coder deployment, or one
 * bridge on this machine while developing Studio itself.
 */
interface WorkspaceDriver
{
    /** Create the backing environment and leave the record in a transitional or running state. */
    public function provision(Workspace $workspace): void;

    public function start(Workspace $workspace): void;

    public function stop(Workspace $workspace): void;

    public function destroy(Workspace $workspace): void;

    /** Pull the current status (and URLs) from the backend into the record. */
    public function refresh(Workspace $workspace): void;

    /** Re-apply the project's server settings (scheduler, queues, Reverb, access rules) to an existing workspace. */
    public function configure(Workspace $workspace): void;
}
