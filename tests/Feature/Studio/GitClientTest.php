<?php

use App\Livewire\Studio\SidePane;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const BRIDGE = 'http://bridge.test:4455';

/** A working tree with one staged file, one modified and one new, the last commit already pushed. */
function gitClientState(array $overrides = []): array
{
    return [
        'branch' => 'develop', 'detached' => false,
        'status' => ['M  app/Models/Order.php', ' M routes/web.php', '?? app/Exports/Invoice.php'],
        'files' => [
            ['path' => 'app/Models/Order.php', 'from' => null, 'index' => 'M', 'worktree' => ' ', 'staged' => true, 'unstaged' => false, 'untracked' => false, 'conflicted' => false],
            ['path' => 'routes/web.php', 'from' => null, 'index' => ' ', 'worktree' => 'M', 'staged' => false, 'unstaged' => true, 'untracked' => false, 'conflicted' => false],
            ['path' => 'app/Exports/Invoice.php', 'from' => null, 'index' => '?', 'worktree' => '?', 'staged' => false, 'unstaged' => true, 'untracked' => true, 'conflicted' => false],
        ],
        'stat' => '', 'diff' => '', 'upstream' => 'origin/develop', 'ahead' => 0, 'behind' => 0,
        'merging' => false, 'operation' => null, 'conflicts' => [], 'stashes' => 1,
        'last' => ['sha' => 'm000000aaaa', 'short' => 'm000000', 'subject' => 'Add the export', 'message' => "Add the export\n\nWith a CSV.", 'pushed' => true],
        ...$overrides,
    ];
}

/** @param  array<string, mixed>  $state */
function fakeGitClient(array $state = []): void
{
    $state = gitClientState($state);

    Http::fake(function (Request $request) use ($state) {
        $path = str_replace(BRIDGE, '', explode('?', $request->url())[0]);
        $method = $request->method();

        return match (true) {
            $path === '/git' => Http::response($state),
            $path === '/git/diff' => Http::response(['path' => $request['path'] ?? 'app/Models/Order.php', 'staged' => "diff --git a/app/Models/Order.php\n@@ -1 +1 @@\n-old\n+new", 'unstaged' => '', 'untracked' => false]),
            $path === '/git/log' => Http::response(['branch' => 'develop', 'head' => 'm000000', 'commits' => [
                ['sha' => 'm000000aaaa', 'short' => 'm000000', 'parents' => ['a000000'], 'author' => 'Maria', 'date' => now()->toIso8601String(), 'subject' => 'Add the export', 'refs' => ['develop'], 'head' => true],
                ['sha' => 'a000000aaaa', 'short' => 'a000000', 'parents' => [], 'author' => 'Luca', 'date' => now()->subDay()->toIso8601String(), 'subject' => 'Release 1.3', 'refs' => ['tag: v1.3.0'], 'head' => false],
            ]]),
            str_starts_with($path, '/git/commit/') => Http::response(['sha' => 'a000000aaaa', 'short' => 'a000000', 'parents' => [], 'author' => 'Luca', 'date' => now()->toIso8601String(), 'subject' => 'Release 1.3', 'body' => 'The notes.', 'refs' => [], 'stat' => '', 'patch' => '', 'onCurrent' => $request->url() === BRIDGE.'/git/commit/a000000aaaa']),
            $path === '/git/branches' && $method === 'GET' => Http::response(['current' => 'develop', 'local' => [
                ['name' => 'develop', 'current' => true, 'upstream' => 'origin/develop', 'track' => '', 'gone' => false, 'merged' => true, 'sha' => 'm000000'],
                ['name' => 'feature/export', 'current' => false, 'upstream' => 'origin/feature/export', 'track' => '[ahead 1]', 'gone' => false, 'merged' => false, 'sha' => 'e000000'],
                ['name' => 'old/done', 'current' => false, 'upstream' => 'origin/old/done', 'track' => '[gone]', 'gone' => true, 'merged' => true, 'sha' => 'd000000'],
            ], 'remote' => [['name' => 'origin/feature/export', 'merged' => false, 'sha' => 'e000000']]]),
            $path === '/git/compare' => Http::response($request['branch'] === 'feature/clash'
                ? ['branch' => 'feature/clash', 'commits' => [['short' => 'c1', 'subject' => 'Clash']], 'ahead' => 1, 'behind' => 1, 'stat' => '', 'files' => ['a.php'], 'fastForward' => false, 'conflicts' => ['a.php']]
                : ['branch' => $request['branch'], 'commits' => [['short' => 'e000000', 'subject' => 'Export orders', 'author' => 'Maria'], ['short' => 'e000001', 'subject' => 'Export tests', 'author' => 'Maria']], 'ahead' => 2, 'behind' => 0, 'stat' => '', 'files' => ['app/Exports/Orders.php'], 'fastForward' => true, 'conflicts' => null]),
            $path === '/git/tags' && $method === 'GET' => Http::response(['tags' => [['name' => 'v1.3.0', 'annotated' => true, 'sha' => 'a000000', 'message' => 'Release', 'pushed' => true], ['name' => 'v1.4.0-rc', 'annotated' => false, 'sha' => 'm000000', 'message' => '', 'pushed' => false]]]),
            in_array($path, ['/git/tags', '/git/tags/push'], true) => Http::response(['tags' => []], $method === 'POST' && $path === '/git/tags' ? 201 : 200),
            $path === '/git/stashes' => Http::response(['stashes' => [['index' => 0, 'ref' => 'stash@{0}', 'branch' => 'develop', 'message' => 'half done login', 'date' => now()->toIso8601String(), 'files' => ['app/Http/Login.php']]]]),
            $path === '/git/stash/show' => Http::response(['index' => 0, 'patch' => "diff --git a/app/Http/Login.php\n+remember me"]),
            in_array($path, ['/git/branches/rename', '/git/remote-branches'], true) || ($path === '/git/branches' && $method === 'DELETE') => Http::response(['current' => 'develop', 'local' => [], 'remote' => []]),
            $path === '/git/merge' => Http::response([...$state, 'files' => [], 'status' => [], ...($request['mode'] === 'squash' ? ['squashed' => $request['branch']] : ['after' => ['npm run build']])]),
            $path === '/git/reset' => Http::response([...$state, 'behind' => 2]),
            str_starts_with($path, '/git/') => Http::response($state),
            default => Http::response(['error' => 'unexpected '.$path], 500),
        };
    });
}

function gitPane(): Testable
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);

    return Livewire::actingAs($user)->test(SidePane::class, ['workspace' => $workspace])->call('setTab', 'changes');
}

test('the working tree lists staged files apart, opens a file\'s diff, stages and unstages', function () {
    fakeGitClient();

    gitPane()
        ->assertSee('Staged')
        ->assertSee('Order.php')
        ->assertSee('Invoice.php')
        ->assertSee('Commit 1 staged file')
        ->call('openFile', 'app/Models/Order.php')
        ->assertSet('fileDiff.path', 'app/Models/Order.php')
        ->assertSeeHtml('+new')
        ->call('stage', 'routes/web.php')
        ->call('unstage', 'app/Models/Order.php')
        ->call('stage')
        ->call('unstage')
        ->call('closeFile')
        ->assertSet('fileDiff', null);

    Http::assertSent(fn (Request $r) => $r->url() === BRIDGE.'/git/stage' && $r['paths'] === ['routes/web.php']);
    Http::assertSent(fn (Request $r) => $r->url() === BRIDGE.'/git/unstage' && $r['paths'] === ['app/Models/Order.php']);
    Http::assertSent(fn (Request $r) => $r->url() === BRIDGE.'/git/stage' && $r['paths'] === null);
});

test('commits take what is staged, amend brings back the last message and warns when it was pushed, commit & push pushes', function () {
    fakeGitClient();

    gitPane()
        ->set('commitMessage', 'Only the model')
        ->call('commitChanges', 'staged')
        ->assertSet('gitNotice', 'Changes committed.')
        ->assertSet('commitMessage', '')
        ->set('amend', true)
        ->assertSet('commitMessage', "Add the export\n\nWith a CSV.")
        ->call('commitChanges', 'staged')
        ->assertSet('gitNotice', 'Last commit amended. It was already pushed: force push to replace it on the remote.')
        ->assertSet('amend', false)
        ->set('commitMessage', 'Everything')
        ->call('commitChanges', 'all', true)
        ->assertSet('gitNotice', 'Committed and pushed.')
        ->call('forcePush')
        ->assertSet('gitNotice', 'Force pushed to the remote.');

    Http::assertSent(fn (Request $r) => $r->url() === BRIDGE.'/git/commit' && $r['message'] === 'Only the model' && $r['all'] === false && $r['amend'] === false);
    Http::assertSent(fn (Request $r) => $r->url() === BRIDGE.'/git/commit' && $r['amend'] === true);
    Http::assertSent(fn (Request $r) => $r->url() === BRIDGE.'/git/commit' && $r['message'] === 'Everything' && $r['all'] === true);
    Http::assertSent(fn (Request $r) => $r->url() === BRIDGE.'/git/push' && $r['force'] === true);
});

test('a conflict left open is resolved file by file and the operation aborted', function () {
    fakeGitClient([
        'merging' => true, 'operation' => 'merge', 'conflicts' => ['app/Models/Order.php'],
        'status' => ['UU app/Models/Order.php'],
        'files' => [['path' => 'app/Models/Order.php', 'from' => null, 'index' => 'U', 'worktree' => 'U', 'staged' => false, 'unstaged' => false, 'untracked' => false, 'conflicted' => true]],
    ]);

    gitPane()
        ->assertSee('Merge in progress')
        ->assertSee('Keep mine')
        ->assertSee('Take theirs')
        ->call('resolve', 'app/Models/Order.php', 'theirs')
        ->assertSet('gitNotice', 'app/Models/Order.php: took the incoming version.')
        ->call('abortMerge')
        ->assertSet('gitNotice', 'The merge was aborted.');

    Http::assertSent(fn (Request $r) => $r->url() === BRIDGE.'/git/resolve' && $r['side'] === 'theirs');
});

test('an older bridge without the file list still shows the files', function () {
    Http::fake([BRIDGE.'/git' => Http::response(['branch' => 'develop', 'status' => ['M  a.php', ' M b.php', 'UU c.php'], 'stat' => '', 'diff' => '', 'merging' => true, 'conflicts' => ['c.php'], 'stashes' => 0])]);

    gitPane()
        ->assertSet('git.files.0.staged', true)
        ->assertSet('git.files.1.unstaged', true)
        ->assertSet('git.files.2.conflicted', true)
        ->assertSet('git.operation', 'merge')
        ->assertSee('b.php');
});

test('the merge dialog previews the branch and merges as chosen, squash included', function () {
    fakeGitClient(['files' => [], 'status' => []]);

    gitPane()
        ->call('setChangesView', 'branches')
        ->assertSee('merged')
        ->assertSee('gone on remote')
        ->call('askMerge', 'feature/export')
        ->assertSet('mergePreview.ahead', 2)
        ->assertSee('Export orders')
        ->assertSee('Fast-forward')
        ->set('mergeMode', 'no-ff')
        ->call('merge')
        ->assertSet('mergeBranch', null)
        ->assertSet('gitNotice', 'feature/export merged into develop. Run in the Terminal: npm run build.')
        ->call('askMerge', 'feature/clash')
        ->assertSee('These files would conflict')
        ->call('askMerge', 'feature/export')
        ->set('mergeMode', 'squash')
        ->call('merge')
        ->assertSet('changesView', 'tree')
        ->assertSet('gitNotice', 'feature/export squashed: its changes are staged, commit them as one.')
        ->assertSet('commitMessage', "Squashed feature/export\n\n- Export orders\n- Export tests");

    Http::assertSent(fn (Request $r) => $r->url() === BRIDGE.'/git/compare?branch=feature%2Fexport');
    Http::assertSent(fn (Request $r) => $r->url() === BRIDGE.'/git/merge' && $r['mode'] === 'no-ff');
    Http::assertSent(fn (Request $r) => $r->url() === BRIDGE.'/git/merge' && $r['mode'] === 'squash');
});

test('the delete dialog asks for force on an unmerged branch and can delete it on the remote', function () {
    fakeGitClient();

    gitPane()
        ->call('setChangesView', 'branches')
        ->call('askDeleteBranch', 'feature/export')
        ->assertSet('deleting.name', 'feature/export')
        ->assertSee('Delete it anyway')
        ->assertSee('Also delete origin/feature/export on the remote')
        ->set('deleteForce', true)
        ->set('deleteRemote', true)
        ->call('deleteBranch')
        ->assertSet('deleting', null)
        ->assertSet('gitNotice', 'Branch feature/export deleted here and on the remote.')
        // a branch whose remote is gone is never deleted there
        ->call('askDeleteBranch', 'old/done')
        ->assertDontSee('Delete it anyway')
        ->set('deleteRemote', true)
        ->call('deleteBranch')
        ->assertSet('gitNotice', 'Branch old/done deleted.')
        ->call('askRenameBranch', 'feature/export')
        ->assertSet('renameTo', 'feature/export')
        ->set('renameTo', 'feature/orders-export')
        ->call('renameBranch')
        ->assertSet('gitNotice', 'feature/export renamed to feature/orders-export.')
        ->call('deleteRemoteBranch', 'origin/feature/export')
        ->assertSet('gitNotice', 'origin/feature/export deleted on the remote.');

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === BRIDGE.'/git/branches?branch=feature%2Fexport&force=1&remote=1');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === BRIDGE.'/git/branches?branch=old%2Fdone');
    Http::assertSent(fn (Request $r) => $r->url() === BRIDGE.'/git/branches/rename' && $r['to'] === 'feature/orders-export');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === BRIDGE.'/git/remote-branches?branch=origin%2Ffeature%2Fexport');
});

test('the history searches, loads more, cherry-picks, resets and tags', function () {
    fakeGitClient();

    gitPane()
        ->call('setChangesView', 'history')
        ->set('logSearch', 'release')
        ->assertSee('Release 1.3')
        ->call('loadMoreLog')
        ->assertSet('logLimit', 160)
        ->call('showCommit', 'a000000aaaa')
        ->assertSee('The notes.')
        ->assertSee('Reset develop to here')
        ->call('askReset')
        ->assertSet('resetTo.short', 'a000000')
        ->set('resetMode', 'hard')
        ->assertSee('This cannot be undone from here')
        ->call('resetBranch')
        ->assertSet('gitNotice', 'develop is back at a000000. The remote still has the later commits: force push to drop them there too.')
        ->call('showCommit', 'f000000aaaa')
        ->assertSee('Cherry-pick onto develop')
        ->call('cherryPick', 'f000000aaaa')
        ->assertSet('gitNotice', 'Commit copied onto develop.')
        ->call('askTag', 'a000000aaaa')
        ->call('createTag')
        ->assertHasErrors('tagName')
        ->set('tagName', 'v1.3.1')
        ->set('tagMessage', 'Patch release')
        ->call('createTag')
        ->assertSet('gitNotice', 'Tag v1.3.1 created. Push it to share it.');

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), BRIDGE.'/git/log') && str_contains($r->url(), 'search=release'));
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), BRIDGE.'/git/log') && str_contains($r->url(), 'limit=160'));
    Http::assertSent(fn (Request $r) => $r->url() === BRIDGE.'/git/reset' && $r['mode'] === 'hard' && $r['sha'] === 'a000000aaaa');
    Http::assertSent(fn (Request $r) => $r->url() === BRIDGE.'/git/cherry-pick' && $r['sha'] === 'f000000aaaa');
    Http::assertSent(fn (Request $r) => $r->url() === BRIDGE.'/git/tags' && $r->method() === 'POST' && $r['tag'] === 'v1.3.1' && $r['at'] === 'a000000aaaa' && $r['message'] === 'Patch release');
});

test('tags are listed, pushed and deleted, here or on the remote too', function () {
    fakeGitClient();

    gitPane()
        ->call('setChangesView', 'tags')
        ->assertSee('v1.3.0')
        ->assertSee('v1.4.0-rc')
        ->assertSee('Push all tags')
        ->call('pushTags', 'v1.4.0-rc')
        ->assertSet('gitNotice', 'Tag v1.4.0-rc pushed to the remote.')
        ->call('askDeleteTag', 'v1.3.0')
        ->set('tags', [['name' => 'v1.3.0', 'pushed' => true]])
        ->assertSee('Also delete it on the remote')
        ->set('deleteTagRemote', true)
        ->call('deleteTag')
        ->assertSet('gitNotice', 'Tag v1.3.0 deleted here and on the remote.')
        ->call('openCommit', 'a000000aaaa')
        ->assertSet('changesView', 'history')
        ->assertSet('commit.short', 'a000000');

    Http::assertSent(fn (Request $r) => $r->url() === BRIDGE.'/git/tags/push' && $r['tag'] === 'v1.4.0-rc');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === BRIDGE.'/git/tags?tag=v1.3.0&remote=1');
});

test('stashes are made with a message, shown, applied and kept, popped and deleted', function () {
    fakeGitClient();

    gitPane()
        ->call('askStash')
        ->set('stashMessage', 'half done login')
        ->set('stashUntracked', false)
        ->call('stash')
        ->assertSet('gitNotice', 'Changes put aside in a stash.')
        ->call('setChangesView', 'stashes')
        ->assertSee('half done login')
        ->call('showStash', 0)
        ->assertSeeHtml('+remember me')
        ->call('showStash', 0)
        ->assertSet('stashPatch', null)
        ->call('stashApply', 0, false)
        ->assertSet('gitNotice', 'Stashed changes applied; the stash is kept.')
        ->call('stashApply', 0)
        ->assertSet('gitNotice', 'Stashed changes brought back.')
        ->call('stashDrop', 0)
        ->assertSet('gitNotice', 'Stash deleted.');

    Http::assertSent(fn (Request $r) => $r->url() === BRIDGE.'/git/stash' && $r['message'] === 'half done login' && $r['untracked'] === false);
    Http::assertSent(fn (Request $r) => $r->url() === BRIDGE.'/git/stash/apply' && $r['pop'] === false);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === BRIDGE.'/git/stash?index=0');
});

test('a client can open none of the git dialogs nor change the repository', function () {
    $client = User::factory()->client()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $client->id]);
    fakeGitClient();

    foreach ([['askMerge', 'x'], ['askDeleteBranch', 'x'], ['askRenameBranch', 'x'], ['askTag'], ['askDeleteTag', 'v1'], ['askStash'], ['stage'], ['unstage'], ['commitChanges'], ['resolve', 'a', 'ours'], ['cherryPick', 'abcd'], ['forcePush'], ['pushTags'], ['stashDrop', 0], ['deleteRemoteBranch', 'origin/x']] as $call) {
        Livewire::actingAs($client)->test(SidePane::class, ['workspace' => $workspace])->set('commitMessage', 'x')->call(...$call)->assertForbidden();
    }
});
