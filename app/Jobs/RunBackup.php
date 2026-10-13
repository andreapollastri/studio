<?php

namespace App\Jobs;

use App\Backups\Engine;
use App\Models\BackupRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One backup run, or one restore, on the backups queue (its own worker, studio-backups):
 * one at a time per job, the next one skipped while the previous is still copying.
 */
class RunBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 21600;

    public function __construct(public int $runId)
    {
        $this->onConnection('backups');
        $this->onQueue('backups');
    }

    public function handle(Engine $engine): void
    {
        $run = BackupRun::query()->with('job')->find($this->runId);

        if ($run === null || $run->status !== 'queued') {
            return;
        }

        // one at a time per job; a run whose worker died is marked failed by studio:backups after a while
        if ($run->job->runs()->where('status', 'running')->whereKeyNot($run->id)->exists()) {
            $run->forceFill(['status' => 'skipped', 'error' => __('The previous run of this backup was still going.'), 'finished_at' => now()])->save();

            return;
        }

        $run->forceFill(['status' => 'running', 'started_at' => now()])->save();

        try {
            if ($run->trigger === 'restore') {
                $engine->restore($run);
            } else {
                $engine->backup($run);
            }

            $run->forceFill(['status' => 'success', 'finished_at' => now()])->save();
        } catch (Throwable $e) {
            $run->forceFill(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()])->save();
            Log::warning('backup failed', ['run' => $run->uuid, 'job' => $run->job->name, 'error' => $e->getMessage()]);
        } finally {
            if ($run->trigger !== 'restore') {
                $run->job->forceFill(['last_run_at' => $run->started_at, 'last_status' => $run->status])->save();
            }
        }

        if ($run->status === 'success' && $run->trigger !== 'restore') {
            $engine->prune($run->job, $run);
        }
    }
}
