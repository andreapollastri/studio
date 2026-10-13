<?php

use App\Livewire\Studio\SidePane;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function fakeGitBridge(): void
{
    $commits = [
        ['sha' => 'm000000', 'short' => 'm000000', 'parents' => ['b000000', 'c000000'], 'author' => 'Maria Rossi', 'email' => 'maria@agency.test', 'date' => now()->subMinutes(5)->toIso8601String(), 'subject' => 'Merge feature/US-014 into develop', 'refs' => ['develop', 'origin/develop'], 'head' => true],
        ['sha' => 'c000000', 'short' => 'c000000', 'parents' => ['a000000'], 'author' => 'Claude', 'email' => 'claude@agency.test', 'date' => now()->subHours(2)->toIso8601String(), 'subject' => 'Add the date range filter to orders', 'refs' => ['feature/US-014'], 'head' => false],
        ['sha' => 'b000000', 'short' => 'b000000', 'parents' => ['a000000'], 'author' => 'Luca Bianchi', 'email' => 'luca@agency.test', 'date' => now()->subDay()->toIso8601String(), 'subject' => 'Fix the returns badge', 'refs' => [], 'head' => false],
        ['sha' => 'a000000', 'short' => 'a000000', 'parents' => [], 'author' => 'Andrea', 'email' => 'andrea@agency.test', 'date' => now()->subDays(3)->toIso8601String(), 'subject' => 'Release 1.3', 'refs' => ['tag: v1.3.0'], 'head' => false],
    ];

    // like the real bridge, the status after a checkout reports the branch it switched to
    $current = 'develop';

    Http::fake(function (Request $request) use ($commits, &$current) {
        $url = $request->url();

        if ($url === 'http://bridge.test:4455/git/checkout') {
            $current = $request['branch'];
        }

        return match (true) {
            str_starts_with($url, 'http://bridge.test:4455/git/log') => Http::response(['branch' => 'develop', 'head' => 'm000000', 'commits' => $commits]),
            $url === 'http://bridge.test:4455/git/branches' && $request->method() === 'GET' => Http::response(['current' => 'develop', 'local' => [
                ['name' => 'develop', 'current' => true, 'upstream' => 'origin/develop', 'track' => '', 'sha' => 'm000000', 'date' => now()->toIso8601String(), 'subject' => 'Merge'],
                ['name' => 'feature/US-014', 'current' => false, 'upstream' => null, 'track' => '', 'sha' => 'c000000', 'date' => now()->toIso8601String(), 'subject' => 'Add the date range filter'],
            ], 'remote' => [['name' => 'origin/main', 'sha' => 'a000000', 'date' => now()->toIso8601String(), 'subject' => 'Release 1.3']]]),
            $url === 'http://bridge.test:4455/git/commit/c000000' => Http::response([...$commits[1], 'stat' => " app/Models/Order.php | 12 ++++\n 1 file changed", 'patch' => "diff --git a/app/Models/Order.php\n+    public function scopeBetween()"]),
            $url === 'http://bridge.test:4455/git/checkout' => Http::response(['branch' => $request['branch'], 'status' => [], 'stat' => '', 'diff' => '']),
            $url === 'http://bridge.test:4455/git/fetch' => Http::response(['current' => 'develop', 'local' => [], 'remote' => [['name' => 'origin/hotfix', 'sha' => 'f000000', 'date' => now()->toIso8601String(), 'subject' => 'Hotfix']]]),
            $url === 'http://bridge.test:4455/git' => Http::response(['branch' => $current, 'status' => [' M app/Models/Order.php'], 'stat' => '1 file changed', 'diff' => '+between', 'upstream' => 'origin/develop', 'ahead' => 2, 'behind' => 1, 'merging' => false, 'conflicts' => [], 'stashes' => 0]),
            $url === 'http://bridge.test:4455/git/merge' && $request['branch'] === 'feature/conflict' => Http::response(['error' => 'merging feature/conflict conflicts in app/Models/Order.php: nothing was merged. Ask Claude to merge it and resolve the conflicts, or align the two branches first.'], 409),
            $url === 'http://bridge.test:4455/git/merge' && $request['branch'] === 'feature/deps' => Http::response(['branch' => 'develop', 'status' => [], 'stat' => '', 'diff' => '', 'after' => ['composer install', 'php artisan migrate']]),
            in_array($url, ['http://bridge.test:4455/git/commit', 'http://bridge.test:4455/git/discard', 'http://bridge.test:4455/git/pull', 'http://bridge.test:4455/git/push', 'http://bridge.test:4455/git/merge', 'http://bridge.test:4455/git/merge/abort', 'http://bridge.test:4455/git/branches', 'http://bridge.test:4455/git/revert', 'http://bridge.test:4455/git/stash'], true) => Http::response(['branch' => 'develop', 'status' => [], 'stat' => '', 'diff' => '']),
            str_starts_with($url, 'http://bridge.test:4455/git/branches?') => Http::response(['current' => 'develop', 'local' => [], 'remote' => []]),
            default => Http::response(['error' => 'unexpected '.$url], 500),
        };
    });
}

test('the history view draws every branch with its refs and opens a commit', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);
    fakeGitBridge();

    $component = Livewire::actingAs($user)
        ->test(SidePane::class, ['workspace' => $workspace])
        ->call('setTab', 'changes')
        ->assertSee('app/Models/Order.php')
        ->call('setChangesView', 'history')
        ->assertSet('gitError', null)
        ->assertSee('Merge feature/US-014 into develop')
        ->assertSee('Add the date range filter to orders')
        ->assertSee('feature/US-014')
        ->assertSee('tag: v1.3.0')
        ->assertSeeHtml('<circle')
        ->call('showCommit', 'c000000')
        ->assertSee('scopeBetween')
        ->call('closeCommit')
        ->assertDontSee('scopeBetween');

    expect($component->get('log')['commits'])->toHaveCount(4)
        ->and($workspace->fresh()->branch)->toBe('develop');
});

test('the branches view lists local and remote branches, switches, and fetches', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);
    fakeGitBridge();

    Livewire::actingAs($user)
        ->test(SidePane::class, ['workspace' => $workspace])
        ->call('setTab', 'changes')
        ->call('setChangesView', 'branches')
        ->assertSee('develop')
        ->assertSee('feature/US-014')
        ->assertSee('origin/main')
        ->assertSee('Switch')
        ->call('checkout', 'feature/US-014')
        ->assertSet('gitError', null)
        ->call('fetch')
        ->assertSet('gitNotice', 'Fetched from the remote.');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/git/checkout' && $request['branch'] === 'feature/US-014');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/git/fetch');
    expect($workspace->fresh()->branch)->toBe('feature/US-014');
});

test('the working tree commits, discards, stashes, pulls and pushes', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);
    fakeGitBridge();

    Livewire::actingAs($user)
        ->test(SidePane::class, ['workspace' => $workspace])
        ->call('setTab', 'changes')
        ->assertSee('↓1')
        ->assertSee('↑2')
        ->assertSee('Commit all')
        ->call('commitAll')
        ->assertSet('gitError', 'Write a commit message first.')
        ->set('commitMessage', 'Add the date range filter')
        ->call('commitAll')
        ->assertSet('gitError', null)
        ->assertSet('commitMessage', '')
        ->assertSet('gitNotice', 'Changes committed.')
        ->call('discard', 'app/Models/Order.php')
        ->call('discard')
        ->call('stash')
        ->call('stashPop')
        ->call('pullRemote')
        ->call('pushRemote')
        ->assertSet('gitNotice', 'Pushed to the remote.');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/git/commit' && $request['message'] === 'Add the date range filter');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/git/discard' && $request['path'] === 'app/Models/Order.php');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/git/discard' && $request['path'] === null);
    Http::assertSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/git/stash' && $request['pop'] === true);
    Http::assertSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/git/pull');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/git/push');
});

test('branches are created, merged, deleted, and commits reverted', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);
    fakeGitBridge();

    Livewire::actingAs($user)
        ->test(SidePane::class, ['workspace' => $workspace])
        ->call('setTab', 'changes')
        ->call('setChangesView', 'branches')
        ->assertSee('Merge into develop')
        ->set('newBranch', 'feature/US-015')
        ->call('createBranch')
        ->assertSet('gitError', null)
        ->assertSet('newBranch', '')
        ->call('merge', 'feature/US-014')
        ->assertSet('gitNotice', 'feature/US-014 merged into develop.')
        ->call('merge', 'feature/deps')
        ->assertSet('gitNotice', 'feature/deps merged into develop. Run in the Terminal: composer install, php artisan migrate.')
        ->call('merge', 'feature/conflict')
        ->assertSet('gitNotice', null)
        ->assertSet('gitError', 'Merging feature/conflict conflicts in app/Models/Order.php: nothing was merged. Ask Claude to merge it and resolve the conflicts, or align the two branches first.')
        ->call('abortMerge')
        ->call('deleteBranch', 'feature/US-014')
        ->call('setChangesView', 'history')
        ->call('showCommit', 'c000000')
        ->assertSee('New branch from here')
        ->set('newBranch', 'hotfix')
        ->call('createBranch', 'c000000')
        ->assertSet('commit', null)
        ->call('revert', 'b000000')
        ->assertSet('gitNotice', 'Commit reverted.');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/git/branches' && $request->method() === 'POST' && $request['branch'] === 'feature/US-015' && $request['from'] === null);
    Http::assertSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/git/branches' && $request->method() === 'POST' && $request['branch'] === 'hotfix' && $request['from'] === 'c000000');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/git/merge' && $request['branch'] === 'feature/US-014');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/git/merge/abort');
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), 'git/branches?branch=feature%2FUS-014'));
    Http::assertSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/git/revert' && $request['sha'] === 'b000000');
});

test('a client has no Changes tab and cannot switch or fetch branches', function () {
    $client = User::factory()->client()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $client->id]);
    fakeGitBridge();

    Livewire::actingAs($client)
        ->test(SidePane::class, ['workspace' => $workspace])
        ->assertDontSee('Changes')
        ->call('setTab', 'changes')
        ->assertSet('tab', 'preview')
        ->call('setTab', 'terminal')
        ->assertSet('tab', 'preview');

    Livewire::actingAs($client)->test(SidePane::class, ['workspace' => $workspace])->call('fetch')->assertForbidden();

    Livewire::actingAs($client)->test(SidePane::class, ['workspace' => $workspace])->call('checkout', 'feature/US-014')->assertForbidden();

    foreach (['pullRemote', 'pushRemote', 'commitAll', 'discard', 'stash', 'abortMerge'] as $action) {
        Livewire::actingAs($client)->test(SidePane::class, ['workspace' => $workspace])->set('commitMessage', 'x')->call($action)->assertForbidden();
    }

    Livewire::actingAs($client)->test(SidePane::class, ['workspace' => $workspace])->call('merge', 'feature/US-014')->assertForbidden();
    Livewire::actingAs($client)->test(SidePane::class, ['workspace' => $workspace])->call('revert', 'b000000')->assertForbidden();
});
