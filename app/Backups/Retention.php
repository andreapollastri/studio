<?php

namespace App\Backups;

use Illuminate\Support\Carbon;

/**
 * Which snapshots to keep, grandfather-father-son style: the newest `last` ones, and the
 * newest one of each of the last `daily` days, `weekly` ISO weeks, `monthly` months and
 * `yearly` years that have a snapshot (a day without a backup does not use up a slot).
 * The rest goes. Times are read in the job's timezone, so "a day" is the team's day.
 */
final class Retention
{
    private const BUCKETS = ['daily' => 'Y-m-d', 'weekly' => 'o-W', 'monthly' => 'Y-m', 'yearly' => 'Y'];

    /**
     * @param  array<int|string, Carbon>  $snapshots  id => when it was taken
     * @param  array{last: int, daily: int, weekly: int, monthly: int, yearly: int}  $policy
     * @return array<int|string, list<string>> id => the rules that keep it; ids left out go
     */
    public static function keep(array $snapshots, array $policy, string $timezone = 'UTC'): array
    {
        uasort($snapshots, fn (Carbon $a, Carbon $b) => $b->getTimestamp() <=> $a->getTimestamp());
        $kept = [];
        $position = 0;

        foreach ($snapshots as $id => $at) {
            if ($position++ < max(1, $policy['last'] ?? 1)) {
                $kept[$id][] = 'last';
            }
        }

        foreach (self::BUCKETS as $rule => $format) {
            $wanted = (int) ($policy[$rule] ?? 0);
            $seen = [];

            foreach ($snapshots as $id => $at) {
                if (count($seen) >= $wanted) {
                    break;
                }

                $bucket = $at->copy()->setTimezone($timezone)->format($format);

                if (! isset($seen[$bucket])) {
                    $seen[$bucket] = true;
                    $kept[$id][] = $rule;
                }
            }
        }

        return $kept;
    }
}
