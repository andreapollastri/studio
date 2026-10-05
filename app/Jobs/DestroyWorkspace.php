<?php

namespace App\Jobs;

use App\Server\ServerException;
use App\Server\StudioAdmin;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Remove a native workspace from the server: its bridge, preview host, pool,
 * services, database, clone, and the Linux user when it owns nothing else.
 * Only the two names travel, so the job runs fine after the workspace row
 * (and the person, or the project) is gone from the database.
 */
class DestroyWorkspace implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public string $handle, public string $slug) {}

    public function handle(StudioAdmin $admin): void
    {
        try {
            $admin->run('workspace-delete', [$this->handle, $this->slug]);
        } catch (ServerException $e) {
            Log::error("The workspace {$this->handle}/{$this->slug} could not be removed from the server: {$e->getMessage()}");
        }
    }
}
