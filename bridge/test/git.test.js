import test from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { gitBranches, gitCheckout, gitCommit, gitCommitAll, gitCreateBranch, gitDeleteBranch, gitDiscard, gitLog, gitMerge, gitMergeAbort, gitPush, gitRevert, gitStash, gitState, GitError } from '../src/git.js';
import { createServer } from '../src/server.js';
import { testConfig, listen } from './helpers.js';

const run = promisify(execFile);
const env = { ...process.env, GIT_AUTHOR_NAME: 'Maria', GIT_AUTHOR_EMAIL: 'maria@agency.test', GIT_COMMITTER_NAME: 'Maria', GIT_COMMITTER_EMAIL: 'maria@agency.test' };
const git = (dir, ...args) => run('git', args, { cwd: dir, env });

/** main: A — B; feature (from A): C; main merges feature: M. */
async function repo() {
  const dir = await mkdtemp(join(tmpdir(), 'studio-git-'));
  await git(dir, 'init', '-q', '-b', 'main');
  await writeFile(join(dir, 'a.txt'), 'a\n');
  await git(dir, 'add', '-A');
  await git(dir, 'commit', '-q', '-m', 'A: first');
  await git(dir, 'checkout', '-q', '-b', 'feature/US-014');
  await writeFile(join(dir, 'c.txt'), 'c\n');
  await git(dir, 'add', '-A');
  await git(dir, 'commit', '-q', '-m', 'C: on the feature');
  await git(dir, 'checkout', '-q', 'main');
  await writeFile(join(dir, 'b.txt'), 'b\n');
  await git(dir, 'add', '-A');
  await git(dir, 'commit', '-q', '-m', 'B: on main');
  await git(dir, 'merge', '-q', '--no-ff', '-m', 'M: merge feature', 'feature/US-014');
  return dir;
}

test('the log lists every branch with parents and refs, newest first', async () => {
  const dir = await repo();
  const log = await gitLog(dir, { limit: 10 });
  assert.equal(log.branch, 'main');
  assert.equal(log.commits.length, 4);
  assert.equal(log.commits[0].subject, 'M: merge feature');
  assert.equal(log.commits[0].parents.length, 2);
  assert.ok(log.commits[0].refs.includes('main'));
  assert.equal(log.commits[0].head, true);
  const feature = log.commits.find((c) => c.subject.startsWith('C:'));
  assert.ok(feature.refs.includes('feature/US-014'));
  assert.equal(log.commits.at(-1).parents.length, 0);
  assert.equal(log.commits[0].author, 'Maria');
});

test('branches, a commit with its patch, and switching branches', async () => {
  const dir = await repo();
  const branches = await gitBranches(dir);
  assert.equal(branches.current, 'main');
  assert.deepEqual(branches.local.map((b) => b.name).sort(), ['feature/US-014', 'main']);
  assert.equal(branches.remote.length, 0);

  const log = await gitLog(dir);
  const c = log.commits.find((x) => x.subject.startsWith('C:'));
  const detail = await gitCommit(dir, c.sha);
  assert.match(detail.stat, /c\.txt/);
  assert.match(detail.patch, /\+c/);
  await assert.rejects(gitCommit(dir, 'not a sha'), (e) => e instanceof GitError && e.status === 400);

  const after = await gitCheckout(dir, 'feature/US-014');
  assert.equal(after.branch, 'feature/US-014');
  assert.equal((await gitState(dir)).branch, 'feature/US-014');
  await assert.rejects(gitCheckout(dir, '--orphan'), (e) => e.status === 400);
  await assert.rejects(gitCheckout(dir, 'nope'), (e) => e.status === 422);
});

test('the git routes are served behind the bearer token', async () => {
  const dir = await repo();
  const server = createServer(testConfig({ workspaceDir: dir }));
  const base = await listen(server);
  const headers = { authorization: 'Bearer bridge-secret', 'content-type': 'application/json' };

  const log = await (await fetch(`${base}/git/log?limit=2`, { headers })).json();
  assert.equal(log.commits.length, 2);
  const branches = await (await fetch(`${base}/git/branches`, { headers })).json();
  assert.equal(branches.current, 'main');
  const commit = await (await fetch(`${base}/git/commit/${log.commits[0].sha}`, { headers })).json();
  assert.equal(commit.subject, 'M: merge feature');
  const checkout = await fetch(`${base}/git/checkout`, { method: 'POST', headers, body: JSON.stringify({ branch: 'feature/US-014' }) });
  assert.equal(checkout.status, 200);
  assert.equal((await checkout.json()).branch, 'feature/US-014');
  const bad = await fetch(`${base}/git/checkout`, { method: 'POST', headers, body: JSON.stringify({ branch: 'nope' }) });
  assert.equal(bad.status, 422);

  await server.shutdown();
});

test('commit, discard, stash, merge with a conflict, revert and branches from the working tree', async () => {
  const dir = await repo();
  const origin = await mkdtemp(join(tmpdir(), 'studio-origin-'));
  await git(origin, 'init', '-q', '--bare');
  await git(dir, 'remote', 'add', 'origin', origin);
  await git(dir, 'config', 'user.name', 'Maria');
  await git(dir, 'config', 'user.email', 'maria@agency.test');

  // discard one file, a new file, then everything
  await writeFile(join(dir, 'a.txt'), 'changed\n');
  await writeFile(join(dir, 'new.txt'), 'new\n');
  let state = await gitState(dir);
  assert.equal(state.status.length, 2);
  state = await gitDiscard(dir, 'a.txt');
  assert.deepEqual(state.status, ['?? new.txt']);
  state = await gitDiscard(dir, 'new.txt');
  assert.deepEqual(state.status, []);
  await writeFile(join(dir, 'a.txt'), 'changed\n');
  await writeFile(join(dir, 'new.txt'), 'new\n');
  assert.deepEqual((await gitDiscard(dir)).status, []);
  await assert.rejects(gitDiscard(dir, '../etc/passwd'), (e) => e.status === 400);

  // stash and pop
  await writeFile(join(dir, 'a.txt'), 'stashed\n');
  state = await gitStash(dir);
  assert.equal(state.stashes, 1);
  assert.deepEqual(state.status, []);
  state = await gitStash(dir, true);
  assert.equal(state.stashes, 0);
  assert.deepEqual(state.status, [' M a.txt']);

  // commit everything, then push a branch without upstream
  await assert.rejects(gitCommitAll(dir, '  '), (e) => e.status === 400);
  state = await gitCommitAll(dir, 'D: change a');
  assert.deepEqual(state.status, []);
  assert.equal(state.upstream, null);
  state = await gitPush(dir);
  assert.equal(state.upstream, 'origin/main');
  assert.equal(state.ahead, 0);
  await assert.rejects(gitCommitAll(dir, 'nothing'), (e) => e.status === 422 && /nothing to commit/.test(e.message));

  // a new branch from the current commit, a conflicting change on each side, a merge that stops
  state = await gitCreateBranch(dir, 'feature/conflict');
  assert.equal(state.branch, 'feature/conflict');
  await writeFile(join(dir, 'a.txt'), 'feature\n');
  await gitCommitAll(dir, 'E: feature side');
  await gitCheckout(dir, 'main');
  await writeFile(join(dir, 'a.txt'), 'main\n');
  state = await gitCommitAll(dir, 'F: main side');
  assert.equal(state.ahead, 1);
  await assert.rejects(gitMerge(dir, 'feature/conflict'), (e) => e.status === 409 && /conflicts in a\.txt: nothing was merged/.test(e.message));
  state = await gitState(dir);
  assert.equal(state.merging, false);
  assert.deepEqual(state.status, []);

  // uncommitted changes, or a merge left open by someone else, keep a merge from starting
  await writeFile(join(dir, 'a.txt'), 'dirty\n');
  await assert.rejects(gitMerge(dir, 'feature/conflict'), (e) => e.status === 409 && /uncommitted changes/.test(e.message));
  await gitDiscard(dir, 'a.txt');
  await assert.rejects(git(dir, 'merge', '-q', '--no-edit', 'feature/conflict'));
  await assert.rejects(gitMerge(dir, 'feature/conflict'), (e) => e.status === 409 && /a merge is in progress/.test(e.message));
  state = await gitMergeAbort(dir);
  assert.equal(state.merging, false);
  assert.deepEqual(state.status, []);

  // a merge open in the tree is concluded by committing
  await assert.rejects(git(dir, 'merge', '-q', '--no-edit', 'feature/conflict'));
  assert.deepEqual((await gitState(dir)).conflicts, ['a.txt']);
  await writeFile(join(dir, 'a.txt'), 'both\n');
  state = await gitCommitAll(dir, 'G: merge feature/conflict');
  assert.equal(state.merging, false);

  // a clean merge says what the merged changes ask of the app
  await gitCreateBranch(dir, 'feature/deps');
  await writeFile(join(dir, 'composer.lock'), '{}\n');
  await gitCommitAll(dir, 'H: dependencies');
  await gitCheckout(dir, 'main');
  state = await gitMerge(dir, 'feature/deps');
  assert.deepEqual(state.after, ['composer install']);
  assert.deepEqual(state.status, []);

  // revert a commit and a merge commit
  const log = await gitLog(dir, { limit: 1 });
  state = await gitRevert(dir, log.commits[0].sha);
  assert.deepEqual(state.status, []);
  assert.match((await gitLog(dir, { limit: 1 })).commits[0].subject, /^Revert/);
  await assert.rejects(gitRevert(dir, 'HEAD'), (e) => e.status === 400);

  // a branch from an older commit, then delete merged and unmerged branches
  const first = (await gitLog(dir, { limit: 50 })).commits.at(-1);
  state = await gitCreateBranch(dir, 'from-first', first.short);
  assert.equal(state.branch, 'from-first');
  await gitCheckout(dir, 'main');
  const branches = await gitDeleteBranch(dir, 'from-first');
  assert.ok(!branches.local.some((b) => b.name === 'from-first'));
  await assert.rejects(gitDeleteBranch(dir, 'main'), (e) => e.status === 422);
  await assert.rejects(gitCreateBranch(dir, 'bad..name'), (e) => e.status === 400);
});
