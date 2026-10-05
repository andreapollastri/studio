<?php

namespace App\Jobs;

use App\Models\Project;
use App\Server\ProjectSites;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DestroyProjectSite implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public Project $project) {}

    public function handle(ProjectSites $sites): void
    {
        $sites->destroy($this->project);
    }
}
