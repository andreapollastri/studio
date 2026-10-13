<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * One run of a backup, from a schedule or a click, or a restore of one. A successful run of an
 * archive or snapshot job leaves its snapshot at `path` under the destination until the
 * retention prunes it (pruned_at); kept_by says which rules keep it now.
 *
 * @property int $id
 * @property string $uuid
 * @property int $backup_job_id
 * @property int|null $backup_schedule_id
 * @property string $trigger schedule, manual or restore
 * @property string $status queued, running, success, failed or skipped
 * @property int|null $user_id
 * @property int|null $restore_of_id
 * @property array{target?: int, path?: string}|null $restore_to
 * @property int|null $destination_id
 * @property string|null $path
 * @property int|null $bytes
 * @property int|null $files
 * @property list<string>|null $kept_by
 * @property string|null $output
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $pruned_at
 * @property Carbon $created_at
 * @property-read BackupJob $job
 * @property-read BackupSchedule|null $schedule
 * @property-read User|null $user
 * @property-read BackupRun|null $restoreOf
 * @property-read BackupTarget|null $destination
 */
#[Fillable(['backup_job_id', 'backup_schedule_id', 'trigger', 'status', 'user_id', 'restore_of_id', 'restore_to', 'destination_id', 'path', 'bytes', 'files', 'kept_by', 'output', 'error', 'started_at', 'finished_at', 'pruned_at'])]
class BackupRun extends Model
{
    use HasPublicUuid;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'restore_to' => 'array',
            'kept_by' => 'array',
            'bytes' => 'integer',
            'files' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'pruned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BackupJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(BackupJob::class, 'backup_job_id');
    }

    /** @return BelongsTo<BackupSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BackupSchedule::class, 'backup_schedule_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<BackupRun, $this> */
    public function restoreOf(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class, 'restore_of_id');
    }

    /** @return BelongsTo<BackupTarget, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupTarget::class, 'destination_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['success', 'failed', 'skipped'], true);
    }

    /** A snapshot still at the destination: something to restore, download or delete. */
    public function hasSnapshot(): bool
    {
        return $this->status === 'success' && $this->trigger !== 'restore' && filled($this->path) && $this->destination_id !== null && $this->pruned_at === null;
    }

    public function isArchive(): bool
    {
        return str_ends_with((string) $this->path, '.tar.gz');
    }

    public function duration(): ?string
    {
        if ($this->started_at === null) {
            return null;
        }

        $seconds = (int) $this->started_at->diffInSeconds($this->finished_at ?? now());

        return $seconds < 60 ? $seconds.'s' : ($seconds < 3600 ? intdiv($seconds, 60).'m '.($seconds % 60).'s' : intdiv($seconds, 3600).'h '.intdiv($seconds % 3600, 60).'m');
    }

    public function size(): ?string
    {
        return $this->bytes === null ? null : Number::fileSize($this->bytes, 1);
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'success' => 'green',
            'failed' => 'red',
            'running' => 'sky',
            'queued' => 'zinc',
            default => 'amber',
        };
    }

    /** The output kept for the log: the end of it, which says what went wrong. */
    public function appendOutput(string $text): void
    {
        $this->output = mb_substr(($this->output ?? '').$text, -60000);
    }
}
