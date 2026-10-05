import test from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { gitBranches, gitCheckout, gitCommit, gitLog, gitState, GitError } from '../src/git.js';
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
