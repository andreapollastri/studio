<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One of the times a backup runs: every hour at a minute, every day, on some days of the week
 * or a day of the month at a time, or any cron expression. A job has as many as wanted; the
 * times are the job's timezone's.
 *
 * @property int $id
 * @property string $uuid
 * @property int $backup_job_id
 * @property string $frequency hourly, daily, weekly, monthly or cron
 * @property int $minute
 * @property int $hour
 * @property list<int>|null $weekdays 0 = Sunday
 * @property int $monthday
 * @property string|null $cron
 * @property bool $enabled
 * @property Carbon|null $last_dispatched_at
 * @property-read BackupJob $job
 */
#[Fillable(['backup_job_id', 'frequency', 'minute', 'hour', 'weekdays', 'monthday', 'cron', 'enabled', 'last_dispatched_at'])]
class BackupSchedule extends Model
{
    use HasPublicUuid;

    public const FREQUENCIES = ['hourly', 'daily', 'weekly', 'monthly', 'cron'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'minute' => 'integer',
            'hour' => 'integer',
            'monthday' => 'integer',
            'weekdays' => 'array',
            'enabled' => 'boolean',
            'last_dispatched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BackupJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(BackupJob::class, 'backup_job_id');
    }

    /** @return list<int> */
    public function days(): array
    {
        $days = array_values(array_unique(array_map('intval', (array) ($this->weekdays ?? []))));
        sort($days);

        return array_values(array_filter($days, fn (int $d) => $d >= 0 && $d <= 6)) ?: [1];
    }

    public function expression(): string
    {
        $m = max(0, min(59, $this->minute));
        $h = max(0, min(23, $this->hour));

        return match ($this->frequency) {
            'hourly' => "{$m} * * * *",
            'daily' => "{$m} {$h} * * *",
            'weekly' => "{$m} {$h} * * ".implode(',', $this->days()),
            'monthly' => "{$m} {$h} ".max(1, min(28, $this->monthday)).' * *',
            default => trim((string) $this->cron),
        };
    }

    public static function validExpression(string $expression): bool
    {
        return trim($expression) !== '' && CronExpression::isValidExpression(trim($expression));
    }

    /** "Every day at 03:00", "Mon, Thu at 22:30", "Every hour at :15" */
    public function label(): string
    {
        $time = sprintf('%02d:%02d', $this->hour, $this->minute);

        return match ($this->frequency) {
            'hourly' => __('Every hour at :minute', ['minute' => sprintf(':%02d', $this->minute)]),
            'daily' => __('Every day at :time', ['time' => $time]),
            'weekly' => __(':days at :time', ['days' => implode(', ', array_map(fn (int $d) => self::dayName($d), $this->days())), 'time' => $time]),
            'monthly' => __('Day :day of every month at :time', ['day' => max(1, min(28, $this->monthday)), 'time' => $time]),
            default => __('Cron :expression', ['expression' => $this->cron]),
        };
    }

    public static function dayName(int $day): string
    {
        return [__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')][$day] ?? (string) $day;
    }

    private function timezone(): string
    {
        $timezone = $this->job->timezone ?? 'UTC';

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    public function isDue(Carbon $now): bool
    {
        try {
            return (new CronExpression($this->expression()))->isDue($now->copy()->setTimezone($this->timezone()));
        } catch (Throwable) {
            return false;
        }
    }

    /** The next time it fires, in the application's timezone. */
    public function nextRunAt(?Carbon $from = null): ?Carbon
    {
        try {
            $next = (new CronExpression($this->expression()))->getNextRunDate(($from ?? now())->copy()->setTimezone($this->timezone()));
        } catch (Throwable) {
            return null;
        }

        return Carbon::instance($next)->setTimezone(config('app.timezone'));
    }

    /**
     * The next times it fires after $from, up to $until, at most $limit.
     *
     * @return list<Carbon>
     */
    public function runsBetween(Carbon $from, Carbon $until, int $limit = 200): array
    {
        $runs = [];

        try {
            $cron = new CronExpression($this->expression());
            $cursor = $from->copy()->setTimezone($this->timezone());

            while (count($runs) < $limit) {
                $next = Carbon::instance($cron->getNextRunDate($cursor));

                if ($next->greaterThan($until)) {
                    break;
                }

                $runs[] = $next->copy()->setTimezone(config('app.timezone'));
                $cursor = $next;
            }
        } catch (Throwable) {
            return $runs;
        }

        return $runs;
    }
}
