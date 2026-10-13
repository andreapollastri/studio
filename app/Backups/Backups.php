<?php

namespace App\Backups;

use App\Jobs\RunBackup;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\BackupSchedule;
use App\Models\BackupTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Starting backups: from the schedules (every minute, studio:backups) or a click, and putting
 * a snapshot back. Each run is a row first (queued), then a job on the backups queue, which a
 * worker of its own runs (studio-backups), so a long copy never holds up the rest of Studio.
 */
final class Backups
{
    /** A running copy writes its progress every minute: this long without a word, its worker is gone. */
    public const SILENT_AFTER_MINUTES = 20;

    /** A queued run waits for the one before it on the queue: at most a run's timeout, and some. */
    public const WAITING_AFTER_MINUTES = 380;

    public function start(BackupJob $job, string $trigger = 'manual', ?User $user = null, ?BackupSchedule $schedule = null): BackupRun
    {
        $run = $job->runs()->create([
            'trigger' => $trigger,
            'status' => 'queued',
            'user_id' => $user?->id,
            'backup_schedule_id' => $schedule?->id,
        ]);

        RunBackup::dispatch($run->id);

        return $run;
    }

    public function restore(BackupRun $snapshot, BackupTarget $target, string $path, User $user): BackupRun
    {
        if (! $snapshot->hasSnapshot()) {
            throw new RuntimeException(__('That snapshot is not at the destination any more.'));
        }

        $run = $snapshot->job->runs()->create([
            'trigger' => 'restore',
            'status' => 'queued',
            'user_id' => $user->id,
            'restore_of_id' => $snapshot->id,
            'restore_to' => ['target' => $target->id, 'path' => trim($path)],
        ]);

        RunBackup::dispatch($run->id);

        return $run;
    }

    /**
     * The schedules due this minute: one run each, once, for the jobs that are on.
     *
     * @return list<BackupRun>
     */
    public function dispatchDue(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->startOfMinute();
        $started = [];

        $schedules = BackupSchedule::query()->where('enabled', true)
            ->whereHas('job', fn ($q) => $q->where('enabled', true))
            ->with('job')
            ->get();

        foreach ($schedules as $schedule) {
            if (! $schedule->isDue($now) || ($schedule->last_dispatched_at !== null && $schedule->last_dispatched_at->greaterThanOrEqualTo($now))) {
                continue;
            }

            $schedule->forceFill(['last_dispatched_at' => $now])->save();
            $started[] = $this->start($schedule->job, 'schedule', null, $schedule);
        }

        return $started;
    }

    /** Runs a dead worker left saying "running" or "queued" for hours: failed, so the job can run again. */
    public function failStale(): int
    {
        return BackupRun::query()
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('status', 'running')->where('updated_at', '<', now()->subMinutes(self::SILENT_AFTER_MINUTES)))
                ->orWhere(fn ($q) => $q->where('status', 'queued')->where('updated_at', '<', now()->subMinutes(self::WAITING_AFTER_MINUTES))))
            ->update(['status' => 'failed', 'error' => __('The worker stopped answering: the run was abandoned.'), 'finished_at' => now()]);
    }
}
