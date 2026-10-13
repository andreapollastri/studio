<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A backup: what to copy (a folder over SSH, a bucket or a prefix of it), where to (a bucket,
 * or a folder over SSH), how, on which schedules, and which snapshots to keep.
 *
 * Modes:
 *  - archive: a .tar.gz of the source folder, made on the source host and streamed to the
 *    destination; the source must be SSH. One file per run.
 *  - snapshot: a copy of every file into a folder of its own per run (any source).
 *  - mirror: the destination made equal to the source, nothing kept per run.
 *
 * Retention (archive and snapshot): the newest `last` runs, plus the newest run of each of the
 * last `daily` days, `weekly` weeks, `monthly` months and `yearly` years that have one. Every
 * other successful run is deleted at the destination after each new one.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $project_id
 * @property string $name
 * @property int $source_id
 * @property string $source_path
 * @property int $destination_id
 * @property string $destination_path
 * @property string $mode
 * @property array{pre_command?: string, excludes?: list<string>}|null $options
 * @property array{last?: int, daily?: int, weekly?: int, monthly?: int, yearly?: int}|null $retention
 * @property string $timezone
 * @property bool $enabled
 * @property Carbon|null $last_run_at
 * @property string|null $last_status
 * @property int|null $user_id
 * @property-read Project|null $project
 * @property-read BackupTarget $source
 * @property-read BackupTarget $destination
 */
#[Fillable(['project_id', 'name', 'source_id', 'source_path', 'destination_id', 'destination_path', 'mode', 'options', 'retention', 'timezone', 'enabled', 'last_run_at', 'last_status', 'user_id'])]
class BackupJob extends Model
{
    use HasPublicUuid;

    public const MODES = ['archive', 'snapshot', 'mirror'];

    /** @var array{last: int, daily: int, weekly: int, monthly: int, yearly: int} */
    public const RETENTION_DEFAULTS = ['last' => 3, 'daily' => 7, 'weekly' => 4, 'monthly' => 6, 'yearly' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'retention' => 'array',
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<BackupTarget, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(BackupTarget::class, 'source_id');
    }

    /** @return BelongsTo<BackupTarget, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupTarget::class, 'destination_id');
    }

    /** @return HasMany<BackupSchedule, $this> */
    public function schedules(): HasMany
    {
        return $this->hasMany(BackupSchedule::class);
    }

    /** @return HasMany<BackupRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(BackupRun::class);
    }

    /** @return HasOne<BackupRun, $this> */
    public function latestRun(): HasOne
    {
        return $this->hasOne(BackupRun::class)->where('trigger', '!=', 'restore')->latestOfMany();
    }

    public static function modeLabel(string $mode): string
    {
        return match ($mode) {
            'archive' => __('Archive (.tar.gz)'),
            'snapshot' => __('Snapshot (dated copy)'),
            'mirror' => __('Mirror (sync)'),
            default => $mode,
        };
    }

    /** Whether runs leave something of their own behind, which the retention then thins out. */
    public function keepsVersions(): bool
    {
        return $this->mode !== 'mirror';
    }

    /** @return array{last: int, daily: int, weekly: int, monthly: int, yearly: int} */
    public function retentionPolicy(): array
    {
        $stored = (array) ($this->retention ?? []);
        $policy = [];

        foreach (self::RETENTION_DEFAULTS as $key => $default) {
            $policy[$key] = max(0, min(1000, (int) ($stored[$key] ?? $default)));
        }

        // the newest one always stays
        $policy['last'] = max(1, $policy['last']);

        return $policy;
    }

    /** "3 last · 7 daily · 4 weekly · 6 monthly" */
    public function retentionLabel(): string
    {
        if (! $this->keepsVersions()) {
            return __('one copy, kept in sync');
        }

        $labels = ['last' => __('last'), 'daily' => __('daily'), 'weekly' => __('weekly'), 'monthly' => __('monthly'), 'yearly' => __('yearly')];

        return collect($this->retentionPolicy())->filter()->map(fn (int $count, string $key) => $count.' '.$labels[$key])->implode(' · ');
    }

    public function preCommand(): string
    {
        return trim((string) (($this->options ?? [])['pre_command'] ?? ''));
    }

    /** @return list<string> */
    public function excludes(): array
    {
        return array_values(array_filter(array_map('trim', (array) (($this->options ?? [])['excludes'] ?? [])), fn (string $e) => $e !== ''));
    }

    /** The next time any of its schedules fires, or null. */
    public function nextRunAt(?Carbon $from = null): ?Carbon
    {
        if (! $this->enabled) {
            return null;
        }

        return $this->schedules->where('enabled', true)
            ->map(fn (BackupSchedule $schedule) => $schedule->nextRunAt($from))
            ->filter()
            ->sort()
            ->first();
    }

    public function isRunning(): bool
    {
        return $this->runs()->whereIn('status', ['queued', 'running'])->exists();
    }

    public function sourceLabel(): string
    {
        return $this->source->name.($this->source_path !== '' ? ':'.$this->source_path : '');
    }

    public function destinationLabel(): string
    {
        return $this->destination->name.($this->destination_path !== '' ? ':'.$this->destination_path : '');
    }
}
