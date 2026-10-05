<?php

namespace App\Git;

/**
 * Lane assignment for a commit graph, the way editors draw it: one column per
 * line of history, a node per commit, lines to its parents in the rows below.
 * Commits come newest first in date order, each with its parent ids.
 */
final class Graph
{
    /** @var list<string> */
    public const COLORS = ['#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#db2777'];

    /**
     * @param  list<array{sha: string, parents: list<string>}>  $commits
     * @return array{lanes: int, rows: list<array{lane: int, edges: array<int, array{from: int, to: int}>}>}
     */
    public static function lanes(array $commits): array
    {
        $active = [];
        $rows = [];
        $width = 1;

        foreach ($commits as $commit) {
            $sha = $commit['sha'];
            $parents = $commit['parents'];
            $lane = array_search($sha, $active, true);

            if ($lane === false) {
                $lane = count($active);
                $active[] = $sha;
            }

            $next = [];
            $position = [];
            $edges = [];

            foreach ($active as $index => $current) {
                $target = $index === $lane ? ($parents[0] ?? null) : $current;

                if ($target === null) {
                    continue;
                }

                if (! isset($position[$target])) {
                    $position[$target] = count($next);
                    $next[] = $target;
                }

                $edges[] = ['from' => $index, 'to' => $position[$target]];
            }

            foreach (array_slice($parents, 1) as $parent) {
                if (! isset($position[$parent])) {
                    $position[$parent] = count($next);
                    $next[] = $parent;
                }

                $edges[] = ['from' => $lane, 'to' => $position[$parent]];
            }

            $width = max($width, count($active), count($next));
            $rows[] = ['lane' => $lane, 'edges' => $edges];
            $active = $next;
        }

        return ['lanes' => $width, 'rows' => $rows];
    }

    public static function color(int $lane): string
    {
        return self::COLORS[$lane % count(self::COLORS)];
    }
}
