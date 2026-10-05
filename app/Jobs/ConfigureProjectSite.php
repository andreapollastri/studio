<?php

namespace App\Jobs;

use App\Models\Project;
use App\Server\ProjectSites;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Apply the project settings (scheduler, queues, Reverb, access rules) to
 * the site and to every workspace of the project on the server.
 */
class ConfigureProjectSite implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public Project $project) {}

    public function handle(ProjectSites $sites): void
    {
        $sites->configure($this->project);
    }
}
