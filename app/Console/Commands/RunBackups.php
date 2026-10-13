<?php

namespace App\Console\Commands;

use App\Backups\Backups;
use Illuminate\Console\Command;

/**
 * Every minute: the backup schedules due now each start a run on the backups queue, and runs a
 * dead worker left hanging are marked failed.
 */
class RunBackups extends Command
{
    protected $signature = 'studio:backups';

    protected $description = 'Start the backups whose schedules are due';

    public function handle(Backups $backups): int
    {
        $stale = $backups->failStale();

        foreach ($backups->dispatchDue() as $run) {
            $this->line("{$run->job->name}: queued");
        }

        if ($stale > 0) {
            $this->warn("{$stale} abandoned run(s) marked failed");
        }

        return self::SUCCESS;
    }
}
