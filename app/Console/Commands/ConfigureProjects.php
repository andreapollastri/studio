<?php

namespace App\Console\Commands;

use App\Enums\SiteStatus;
use App\Jobs\ConfigureProjectSite;
use App\Models\Project;
use Illuminate\Console\Command;

/**
 * The settings of every project applied again on the server, queued: its site and every
 * workspace. An upgrade runs it when what the settings mean changed (2.4.9: previews back
 * under Studio's domain and never public, the site public only at its public address).
 */
class ConfigureProjects extends Command
{
    protected $signature = 'studio:configure-projects';

    protected $description = 'Apply the settings of every project to its site and workspaces again';

    public function handle(): int
    {
        $count = 0;

        foreach (Project::query()->orderBy('slug')->get() as $project) {
            if ($project->site_status === SiteStatus::New && ! $project->workspaces()->exists()) {
                continue;
            }

            ConfigureProjectSite::dispatch($project);
            $this->line($project->slug);
            $count++;
        }

        $this->info(trans_choice(':count project queued.|:count projects queued.', $count, ['count' => $count]));

        return self::SUCCESS;
    }
}
