import test from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
  gitBranches,
  gitCheckout,
  gitCherryPick,
  gitCommit,
  gitCommitAll,
  gitCompare,
  gitCreateBranch,
  gitCreateTag,
  gitDeleteBranch,
  gitDeleteRemoteBranch,
  gitDeleteTag,
  gitDiffFile,
  gitFetch,
  gitLog,
  gitMerge,
  gitMergeAbort,
  gitPush,
  gitPushTags,
  gitRenameBranch,
  gitReset,
  gitResolve,
  gitStage,
  gitStashApply,
  gitStashDrop,
  gitStashes,
  gitStashPush,
  gitStashShow,
  gitState,
  gitTags,
  gitUnstage,
} from '../src/git.js';
import { createServer } from '../src/server.js';
import { testConfig, listen } from './helpers.js';

const run = promisify(execFile);
const git = (dir, ...args) => run('git', args, { cwd: dir });

/** main with one commit, pushed to a bare origin. */
async function repo() {
  const dir = await mkdtemp(join(tmpdir(), 'studio-gitc-'));
  const origin = await mkdtemp(join(tmpdir(), 'studio-gitc-origin-'));
  await git(origin, 'init', '-q', '--bare', '-b', 'main');
  await git(dir, 'init', '-q', '-b', 'main');
  await git(dir, 'config', 'user.name', 'Maria');
  await git(dir, 'config', 'user.email', 'maria@agency.test');
  await git(dir, 'remote', 'add', 'origin', origin);
  await writeFile(join(dir, 'a.txt'), 'a\n');
  await git(dir, 'add', '-A');
  await git(dir, 'commit', '-q', '-m', 'A: first');
  await git(dir, 'push', '-q', '-u', 'origin', 'main');
  return dir;
}

async function commitFile(dir, file, content, message) {
  await writeFile(join(dir, file), content);
  await gitCommitAll(dir, message);
}

test('files are staged, unstaged and committed on their own, with a diff each', async () => {
  const dir = await repo();
  await writeFile(join(dir, 'a.txt'), 'changed\n');
  await writeFile(join(dir, 'new file.txt'), 'new\n');

  let state = await gitState(dir);
  assert.deepEqual(state.files.map((f) => [f.path, f.staged, f.unstaged, f.untracked]), [['a.txt', false, true, false], ['new file.txt', false, true, true]]);
  assert.equal(state.last.subject, 'A: first');
  assert.equal(state.last.pushed, true);

  const untracked = await gitDiffFile(dir, 'new file.txt');
  assert.equal(untracked.untracked, true);
  assert.match(untracked.unstaged, /\+new/);

  state = await gitStage(dir, ['a.txt']);
  assert.deepEqual(state.files.find((f) => f.path === 'a.txt').staged, true);
  const staged = await gitDiffFile(dir, 'a.txt');
  assert.match(staged.staged, /\+changed/);
  assert.equal(staged.unstaged, '');

  // only what is staged goes into the commit
  state = await gitCommitAll(dir, 'B: only a', { all: false });
  assert.deepEqual(state.status, ['?? new file.txt']);
  await assert.rejects(gitCommitAll(dir, 'nothing staged', { all: false }), (e) => e.status === 409 && /nothing is staged/.test(e.message));

  state = await gitStage(dir);
  assert.equal(state.files[0].staged, true);
  state = await gitUnstage(dir, ['new file.txt']);
  assert.equal(state.files[0].untracked, true);

  // amend keeps the message when none is given, and says the last commit is not pushed any more
  await gitStage(dir);
  state = await gitCommitAll(dir, '', { all: false, amend: true });
  assert.equal(state.last.subject, 'B: only a');
  assert.equal(state.last.pushed, false);
  assert.deepEqual(state.status, []);
  await assert.rejects(gitDiffFile(dir, '../x'), (e) => e.status === 400);
});

test('a merge is previewed, made as asked, and a conflict left open elsewhere is resolved file by file', async () => {
  const dir = await repo();
  await gitCreateBranch(dir, 'feature');
  await commitFile(dir, 'f.txt', 'f\n', 'F: feature file');
  await gitCheckout(dir, 'main');

  let preview = await gitCompare(dir, 'feature');
  assert.equal(preview.ahead, 1);
  assert.equal(preview.fastForward, true);
  assert.deepEqual(preview.files, ['f.txt']);
  assert.equal(preview.commits[0].subject, 'F: feature file');

  let state = await gitMerge(dir, 'feature', { mode: 'no-ff' });
  assert.equal((await gitLog(dir, { limit: 1, all: false })).commits[0].parents.length, 2);

  // conflicts are foreseen without touching the tree
  await gitCreateBranch(dir, 'other');
  await commitFile(dir, 'a.txt', 'other\n', 'O: other side');
  await gitCheckout(dir, 'main');
  await commitFile(dir, 'a.txt', 'main\n', 'M: main side');
  preview = await gitCompare(dir, 'other');
  assert.equal(preview.fastForward, false);
  assert.deepEqual(preview.conflicts, ['a.txt']);
  assert.deepEqual((await gitState(dir)).status, []);

  // squash: the changes staged, nothing committed
  await gitCreateBranch(dir, 'squashme');
  await commitFile(dir, 's1.txt', '1\n', 'S1');
  await commitFile(dir, 's2.txt', '2\n', 'S2');
  await gitCheckout(dir, 'main');
  state = await gitMerge(dir, 'squashme', { mode: 'squash' });
  assert.equal(state.squashed, 'squashme');
  assert.deepEqual(state.files.map((f) => [f.path, f.staged]), [['s1.txt', true], ['s2.txt', true]]);
  state = await gitCommitAll(dir, 'Squashed', { all: false });
  assert.equal(state.last.subject, 'Squashed');

  // a merge opened by someone else: keep ours, then conclude it
  await assert.rejects(git(dir, 'merge', '-q', '--no-edit', 'other'));
  state = await gitState(dir);
  assert.equal(state.operation, 'merge');
  assert.deepEqual(state.conflicts, ['a.txt']);
  await assert.rejects(gitCommitAll(dir, 'too early', { all: false }), (e) => e.status === 409 && /resolve the conflicts first/.test(e.message));
  await assert.rejects(gitResolve(dir, 'a.txt', 'resolved'), (e) => e.status === 409 && /conflict markers/.test(e.message));
  await assert.rejects(gitCheckout(dir, 'feature'), (e) => e.status === 409);
  state = await gitResolve(dir, 'a.txt', 'theirs');
  assert.deepEqual(state.conflicts, []);
  state = await gitCommitAll(dir, 'Merge other', { all: false });
  assert.equal(state.operation, null);

  // abort works for whatever is open
  await gitCreateBranch(dir, 'third');
  await commitFile(dir, 'a.txt', 'third\n', 'T');
  await gitCheckout(dir, 'main');
  await commitFile(dir, 'a.txt', 'main again\n', 'M2');
  const third = (await gitLog(dir, { limit: 5 })).commits.find((c) => c.subject === 'T');
  await assert.rejects(git(dir, 'cherry-pick', third.sha));
  assert.equal((await gitState(dir)).operation, 'cherry-pick');
  state = await gitMergeAbort(dir);
  assert.equal(state.operation, null);
  assert.deepEqual(state.status, []);
});

test('cherry-pick and reset move commits, all or nothing', async () => {
  const dir = await repo();
  await gitCreateBranch(dir, 'feature');
  await commitFile(dir, 'pick.txt', 'p\n', 'P: to pick');
  await commitFile(dir, 'a.txt', 'conflict\n', 'X: conflicting');
  await gitCheckout(dir, 'main');
  await commitFile(dir, 'a.txt', 'main\n', 'M: main');

  const log = await gitLog(dir, { limit: 10 });
  const pick = log.commits.find((c) => c.subject === 'P: to pick');
  const conflicting = log.commits.find((c) => c.subject === 'X: conflicting');
  let detail = await gitCommit(dir, pick.sha);
  assert.equal(detail.onCurrent, false);

  let state = await gitCherryPick(dir, pick.sha);
  assert.equal(state.last.subject, 'P: to pick');
  await gitPush(dir);
  await assert.rejects(gitCherryPick(dir, conflicting.sha), (e) => e.status === 409 && /a\.txt: nothing was copied/.test(e.message));
  assert.equal((await gitState(dir)).operation, null);

  // soft keeps the undone commit staged, hard throws it away
  const first = (await gitLog(dir, { limit: 10, all: false })).commits.at(-1);
  detail = await gitCommit(dir, first.sha);
  assert.equal(detail.onCurrent, true);
  state = await gitReset(dir, first.sha, 'soft');
  assert.ok(state.files.every((f) => f.staged));
  assert.equal(state.last.subject, 'A: first');
  state = await gitReset(dir, first.sha, 'hard');
  assert.deepEqual(state.status, []);
  await assert.rejects(gitReset(dir, first.sha, 'sideways'), (e) => e.status === 400);

  // after a reset the remote is ahead: a plain push is refused, a force push with lease goes
  await commitFile(dir, 'n.txt', 'n\n', 'N: new history');
  await assert.rejects(gitPush(dir));
  state = await gitPush(dir, { force: true });
  assert.equal(state.ahead, 0);
  assert.equal(state.behind, 0);
});

test('log search finds messages, authors and commit ids', async () => {
  const dir = await repo();
  await commitFile(dir, 'b.txt', 'b\n', 'Add the invoice export');
  const all = await gitLog(dir, { limit: 10 });
  assert.deepEqual((await gitLog(dir, { search: 'INVOICE' })).commits.map((c) => c.subject), ['Add the invoice export']);
  assert.equal((await gitLog(dir, { search: 'maria' })).commits.length, 2);
  assert.equal((await gitLog(dir, { search: all.commits[1].short })).commits[0].subject, 'A: first');
  assert.equal((await gitLog(dir, { search: 'nothing like this' })).commits.length, 0);
});

test('branches are renamed and deleted here and on the remote; tags are made, pushed and deleted', async () => {
  const dir = await repo();
  await gitCreateBranch(dir, 'feature/old');
  await commitFile(dir, 'f.txt', 'f\n', 'F');
  await gitPush(dir);
  await gitCheckout(dir, 'main');

  let branches = await gitBranches(dir);
  assert.equal(branches.local.find((b) => b.name === 'feature/old').merged, false);
  assert.equal(branches.local.find((b) => b.name === 'main').merged, true);

  branches = await gitRenameBranch(dir, 'feature/old', 'feature/new');
  assert.ok(branches.local.some((b) => b.name === 'feature/new'));
  branches = await gitDeleteBranch(dir, 'feature/new', { remote: true });
  assert.ok(!branches.local.some((b) => b.name === 'feature/new'));
  assert.ok(!branches.remote.some((b) => b.name === 'origin/feature/old'));

  // a branch merged nowhere needs force
  await gitCreateBranch(dir, 'feature/new');
  await commitFile(dir, 'g.txt', 'g\n', 'G');
  await gitCheckout(dir, 'main');
  await assert.rejects(gitDeleteBranch(dir, 'feature/new'), (e) => e.status === 422 && /not fully merged/.test(e.message));
  branches = await gitDeleteBranch(dir, 'feature/new', { force: true });
  assert.ok(!branches.local.some((b) => b.name === 'feature/new'));

  // a remote branch alone, then checking it out twice reuses the local one
  await gitCreateBranch(dir, 'remote-only');
  await gitPush(dir);
  await gitCheckout(dir, 'main');
  await gitDeleteBranch(dir, 'remote-only');
  await gitFetch(dir);
  assert.equal((await gitCheckout(dir, 'origin/remote-only')).branch, 'remote-only');
  await gitCheckout(dir, 'main');
  assert.equal((await gitCheckout(dir, 'origin/remote-only')).branch, 'remote-only');
  await gitCheckout(dir, 'main');
  branches = await gitDeleteRemoteBranch(dir, 'origin/remote-only');
  assert.ok(!branches.remote.some((b) => b.name === 'origin/remote-only'));

  let tags = await gitCreateTag(dir, 'v1.0.0', { message: 'First release' });
  assert.deepEqual(tags.tags.map((t) => [t.name, t.annotated, t.message, t.pushed]), [['v1.0.0', true, 'First release', false]]);
  const first = (await gitLog(dir, { limit: 10, all: false })).commits.at(-1);
  tags = await gitCreateTag(dir, 'v0.1', { at: first.short });
  assert.equal(tags.tags.find((t) => t.name === 'v0.1').sha, first.short);
  tags = await gitPushTags(dir, 'v1.0.0');
  assert.equal(tags.tags.find((t) => t.name === 'v1.0.0').pushed, true);
  tags = await gitDeleteTag(dir, 'v1.0.0', { remote: true });
  assert.deepEqual((await gitTags(dir)).tags.map((t) => t.name), ['v0.1']);
  await assert.rejects(gitCreateTag(dir, 'bad..tag'), (e) => e.status === 400);
});

test('stashes are listed with their files, shown, applied, kept or dropped', async () => {
  const dir = await repo();
  await writeFile(join(dir, 'a.txt'), 'one\n');
  await gitStashPush(dir, { message: 'half done login' });
  await writeFile(join(dir, 'b.txt'), 'two\n');
  let state = await gitStashPush(dir);
  assert.equal(state.stashes, 2);

  const { stashes } = await gitStashes(dir);
  assert.equal(stashes[0].index, 0);
  assert.deepEqual(stashes[0].files, ['b.txt']);
  assert.equal(stashes[1].message, 'half done login');
  assert.equal(stashes[1].branch, 'main');
  assert.match((await gitStashShow(dir, 1)).patch, /\+one/);

  // apply keeps it, pop drops it
  state = await gitStashApply(dir, 1, { pop: false });
  assert.equal(state.stashes, 2);
  assert.deepEqual(state.status, [' M a.txt']);
  await assert.rejects(gitStashApply(dir, 0), (e) => e.status === 409 && /uncommitted changes/.test(e.message));
  await git(dir, 'checkout', '--', 'a.txt');
  state = await gitStashApply(dir, 1);
  assert.equal(state.stashes, 1);
  state = await gitStashDrop(dir, 0);
  assert.equal(state.stashes, 0);
  await assert.rejects(gitStashDrop(dir, -1), (e) => e.status === 400);
});

test('the new git routes are served', async () => {
  const dir = await repo();
  const server = createServer(testConfig({ workspaceDir: dir }));
  const base = await listen(server);
  const headers = { authorization: 'Bearer bridge-secret', 'content-type': 'application/json' };
  const call = async (method, path, body) => {
    const response = await fetch(`${base}${path}`, { method, headers, ...(body ? { body: JSON.stringify(body) } : {}) });
    return { status: response.status, data: await response.json() };
  };

  await writeFile(join(dir, 'a.txt'), 'changed\n');
  assert.equal((await call('POST', '/git/stage', { paths: ['a.txt'] })).data.files[0].staged, true);
  assert.match((await call('GET', '/git/diff?path=a.txt')).data.staged, /\+changed/);
  assert.equal((await call('POST', '/git/commit', { message: 'B', all: false })).data.last.subject, 'B');
  assert.equal((await call('POST', '/git/tags', { tag: 'v1' })).status, 201);
  assert.equal((await call('GET', '/git/tags')).data.tags[0].name, 'v1');
  assert.equal((await call('DELETE', '/git/tags?tag=v1')).data.tags.length, 0);
  assert.equal((await call('GET', '/git/stashes')).data.stashes.length, 0);
  assert.equal((await call('POST', '/git/branches', { branch: 'x' })).status, 201);
  assert.equal((await call('POST', '/git/checkout', { branch: 'main' })).status, 200);
  assert.equal((await call('GET', '/git/compare?branch=x')).data.ahead, 0);
  assert.equal((await call('DELETE', '/git/branches?branch=x&force=1')).status, 200);
  assert.equal((await call('POST', '/git/reset', { sha: 'zz', mode: 'hard' })).status, 400);
  assert.equal((await call('GET', '/git/nothing-here')).status, 404);

  await server.shutdown();
});
