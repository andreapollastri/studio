<?php

use App\Git\Graph;

test('a linear history stays in one lane', function () {
    $graph = Graph::lanes([
        ['sha' => 'c', 'parents' => ['b']],
        ['sha' => 'b', 'parents' => ['a']],
        ['sha' => 'a', 'parents' => []],
    ]);

    expect($graph['lanes'])->toBe(1)
        ->and(array_column($graph['rows'], 'lane'))->toBe([0, 0, 0])
        ->and($graph['rows'][0]['edges'])->toBe([['from' => 0, 'to' => 0]])
        ->and($graph['rows'][2]['edges'])->toBe([]);
});

test('a branch opens a second lane and its merge closes it', function () {
    // M merges B (main) and C (feature); both come from A.
    $graph = Graph::lanes([
        ['sha' => 'M', 'parents' => ['B', 'C']],
        ['sha' => 'C', 'parents' => ['A']],
        ['sha' => 'B', 'parents' => ['A']],
        ['sha' => 'A', 'parents' => []],
    ]);

    expect($graph['lanes'])->toBe(2)
        ->and(array_column($graph['rows'], 'lane'))->toBe([0, 1, 0, 0])
        ->and($graph['rows'][0]['edges'])->toBe([['from' => 0, 'to' => 0], ['from' => 0, 'to' => 1]])
        ->and($graph['rows'][1]['edges'])->toBe([['from' => 0, 'to' => 0], ['from' => 1, 'to' => 1]])
        ->and($graph['rows'][2]['edges'])->toBe([['from' => 0, 'to' => 0], ['from' => 1, 'to' => 0]]);
});

test('an unrelated root gets its own lane and colours cycle', function () {
    $graph = Graph::lanes([
        ['sha' => 'x', 'parents' => []],
        ['sha' => 'y', 'parents' => []],
    ]);

    expect(array_column($graph['rows'], 'lane'))->toBe([0, 0])
        ->and(Graph::color(0))->toBe(Graph::COLORS[0])
        ->and(Graph::color(count(Graph::COLORS)))->toBe(Graph::COLORS[0]);
});
