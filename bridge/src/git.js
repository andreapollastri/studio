import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const run = promisify(execFile);
const DIFF_LIMIT = 200 * 1024;
const LOG_LIMIT = 400;
const COMMIT = '%H%x1f%h%x1f%P%x1f%an%x1f%ae%x1f%aI%x1f%s%x1f%D%x1e';
const BRANCH = /^[A-Za-z0-9][A-Za-z0-9._/-]*$/;

/** A git problem the caller can act on: an unknown branch, a dirty tree, a bad name. */
export class GitError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

async function git(workspaceDir, args, { maxBuffer = 8 * 1024 * 1024 } = {}) {
  try {
    const { stdout } = await run('git', args, { cwd: workspaceDir, maxBuffer });
    return stdout;
  } catch (error) {
    if (error.code === 'ENOENT') throw new GitError(500, 'git is not installed in the workspace');
    // a merge conflict or "nothing to commit" is told on stdout, the rest on stderr
    const told = [error.stderr, error.stdout].map((out) => (out || '').toString().trim()).filter((out) => out !== '').join('\n');
    throw new GitError(422, told || error.message || 'git failed');
  }
}

/** Whether a git call succeeds, for the questions git answers with its exit code. */
async function succeeds(workspaceDir, args) {
  try {
    await git(workspaceDir, args);
    return true;
  } catch {
    return false;
  }
}

/** Branch, porcelain status, diffstat and a bounded diff of the working tree, with where it stands against its upstream. */
export async function gitState(workspaceDir) {
  const branch = (await git(workspaceDir, ['rev-parse', '--abbrev-ref', 'HEAD'])).trim();
  const status = (await git(workspaceDir, ['status', '--porcelain=v1'])).split('\n').filter((l) => l !== '');
  const stat = (await git(workspaceDir, ['diff', '--stat'])).trimEnd();
  let diff = await git(workspaceDir, ['diff']);
  if (diff.length > DIFF_LIMIT) diff = diff.slice(0, DIFF_LIMIT) + '\n… (truncated)';

  let upstream = null;
  let ahead = 0;
  let behind = 0;
  try {
    upstream = (await git(workspaceDir, ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}'])).trim() || null;
    const [left, right] = (await git(workspaceDir, ['rev-list', '--left-right', '--count', '@{u}...HEAD'])).trim().split(/\s+/);
    behind = Number(left) || 0;
    ahead = Number(right) || 0;
  } catch {
    // no upstream yet: the first push sets it
  }

  const merging = await succeeds(workspaceDir, ['rev-parse', '-q', '--verify', 'MERGE_HEAD']);
  const conflicts = status.filter((line) => /^(DD|AU|UD|UA|DU|AA|UU) /.test(line)).map((line) => line.slice(3));
  const stashes = (await git(workspaceDir, ['stash', 'list'])).split('\n').filter((l) => l !== '').length;

  return { branch, status, stat, diff, upstream, ahead, behind, merging, conflicts, stashes };
}

function parseCommits(raw) {
  return raw
    .split('\x1e')
    .map((record) => record.trim())
    .filter((record) => record !== '')
    .map((record) => {
      const [sha, short, parents, author, email, date, subject, refs] = record.split('\x1f');
      const names = (refs || '')
        .split(', ')
        .map((ref) => ref.replace(/^HEAD -> /, '').trim())
        .filter((ref) => ref !== '' && ref !== 'HEAD');
      return {
        sha,
        short,
        parents: parents ? parents.split(' ') : [],
        author,
        email,
        date,
        subject,
        refs: names,
        head: /(^|, )HEAD( ->|$)/.test(refs || ''),
      };
    });
}

/** The newest commits of every branch, in date order, with parents so a graph can be drawn. */
export async function gitLog(workspaceDir, { limit = 80, all = true } = {}) {
  const max = Math.max(1, Math.min(LOG_LIMIT, Number(limit) || 80));
  const raw = await git(workspaceDir, ['log', `--max-count=${max}`, '--date-order', `--format=${COMMIT}`, ...(all ? ['--all'] : []), '--']);
  const branch = (await git(workspaceDir, ['rev-parse', '--abbrev-ref', 'HEAD'])).trim();
  const head = (await git(workspaceDir, ['rev-parse', 'HEAD'])).trim();

  return { branch, head, commits: parseCommits(raw) };
}

/** Local branches with their upstream and remote branches, newest commit first. */
export async function gitBranches(workspaceDir) {
  const raw = await git(workspaceDir, [
    'for-each-ref',
    '--sort=-committerdate',
    '--format=%(refname)%1f%(refname:short)%1f%(HEAD)%1f%(upstream:short)%1f%(upstream:track)%1f%(objectname:short)%1f%(committerdate:iso-strict)%1f%(subject)',
    'refs/heads',
    'refs/remotes',
  ]);
  const local = [];
  const remote = [];

  for (const line of raw.split('\n').filter((l) => l !== '')) {
    const [ref, name, head, upstream, track, short, date, subject] = line.split('\x1f');
    if (ref.startsWith('refs/heads/')) {
      local.push({ name, current: head === '*', upstream: upstream || null, track: track || '', sha: short, date, subject });
    } else if (ref.startsWith('refs/remotes/') && !ref.endsWith('/HEAD')) {
      remote.push({ name, sha: short, date, subject });
    }
  }

  const current = local.find((b) => b.current)?.name ?? (await git(workspaceDir, ['rev-parse', '--abbrev-ref', 'HEAD'])).trim();

  return { current, local, remote };
}

/** One commit with its stat and a bounded patch. */
export async function gitCommit(workspaceDir, sha) {
  commitId(sha);
  const [commit] = parseCommits(await git(workspaceDir, ['show', '-s', `--format=${COMMIT}`, sha, '--']));
  if (!commit) throw new GitError(404, 'unknown commit');
  const stat = (await git(workspaceDir, ['show', '--stat=120', '--format=', sha, '--'])).trimEnd();
  let patch = await git(workspaceDir, ['show', '--format=', '-p', sha, '--']);
  if (patch.length > DIFF_LIMIT) patch = patch.slice(0, DIFF_LIMIT) + '\n… (truncated)';

  return { ...commit, stat, patch };
}

function branchName(branch) {
  const name = String(branch ?? '').trim();
  if (!BRANCH.test(name) || name.includes('..') || name.endsWith('.lock') || name.endsWith('/')) throw new GitError(400, 'invalid branch name');
  return name;
}

function commitId(sha) {
  const id = String(sha ?? '').trim();
  if (!/^[0-9a-f]{4,40}$/i.test(id)) throw new GitError(400, 'invalid commit id');
  return id;
}

/** A path of the working tree as git status prints it: relative, inside the workspace. */
function treePath(path) {
  const name = String(path ?? '').trim().replace(/^"(.*)"$/, '$1');
  if (name === '' || name.startsWith('/') || name.startsWith('-') || name.split('/').includes('..')) throw new GitError(400, 'invalid path');
  return name;
}

/** Switch the working tree to a branch; a remote branch gets a local tracking branch. */
export async function gitCheckout(workspaceDir, branch) {
  const name = branchName(branch);
  try {
    await git(workspaceDir, ['checkout', '-q', name, '--']);
  } catch (error) {
    if (!name.startsWith('origin/')) throw error;
    await git(workspaceDir, ['checkout', '-q', '--track', name]);
  }

  return gitState(workspaceDir);
}

/** `git fetch --prune` with the workspace's own credentials, then the branches as they are now. */
export async function gitFetch(workspaceDir) {
  await git(workspaceDir, ['fetch', '--prune', '--quiet']);

  return gitBranches(workspaceDir);
}

/** Stage everything and commit it; while a merge is in progress this concludes the merge. */
export async function gitCommitAll(workspaceDir, message) {
  const text = String(message ?? '').trim();
  if (text === '') throw new GitError(400, 'a commit message is required');
  await git(workspaceDir, ['add', '-A']);
  await git(workspaceDir, ['commit', '-q', '-m', text]);

  return gitState(workspaceDir);
}

/** Throw away the changes of one path, or of the whole working tree; untracked files are removed too. */
export async function gitDiscard(workspaceDir, path = null) {
  if (path === null || path === undefined || path === '') {
    await git(workspaceDir, ['reset', '-q', '--hard']);
    await git(workspaceDir, ['clean', '-q', '-fd']);

    return gitState(workspaceDir);
  }

  // a rename is listed as "old -> new": both sides go back
  if (String(path).includes(' -> ')) {
    for (const side of String(path).split(' -> ')) await gitDiscard(workspaceDir, side);

    return gitState(workspaceDir);
  }

  const name = treePath(path);
  const tracked = await succeeds(workspaceDir, ['cat-file', '-e', `HEAD:${name}`]);
  if (tracked) {
    await git(workspaceDir, ['checkout', '-q', 'HEAD', '--', name]);
  } else {
    await git(workspaceDir, ['rm', '-q', '--cached', '--ignore-unmatch', '-r', '--', name]);
    await git(workspaceDir, ['clean', '-q', '-fd', '--', name]);
  }

  return gitState(workspaceDir);
}

/** `git pull` with a merge (never a rebase), with the workspace's own credentials. */
export async function gitPull(workspaceDir) {
  await git(workspaceDir, ['pull', '-q', '--no-rebase', '--no-edit']);

  return gitState(workspaceDir);
}

/** Push the current branch; the first push of a new branch sets its upstream on origin. */
export async function gitPush(workspaceDir) {
  const tracked = await succeeds(workspaceDir, ['rev-parse', '--abbrev-ref', '@{u}']);
  await git(workspaceDir, tracked ? ['push', '-q'] : ['push', '-q', '-u', 'origin', 'HEAD']);

  return gitState(workspaceDir);
}

/** Merge a branch into the current one; on a conflict the merge stays open to be resolved or aborted. */
export async function gitMerge(workspaceDir, branch) {
  await git(workspaceDir, ['merge', '-q', '--no-edit', branchName(branch)]);

  return gitState(workspaceDir);
}

export async function gitMergeAbort(workspaceDir) {
  await git(workspaceDir, ['merge', '--abort']);

  return gitState(workspaceDir);
}

/** Create a branch from the current commit, or from the one given, and switch to it. */
export async function gitCreateBranch(workspaceDir, branch, from = null) {
  const name = branchName(branch);
  if (name.startsWith('origin/')) throw new GitError(400, 'invalid branch name');
  await git(workspaceDir, ['checkout', '-q', '-b', name, ...(from ? [commitId(from)] : []), '--']);

  return gitState(workspaceDir);
}

/** Delete a local branch; git refuses when it is checked out or not merged anywhere. */
export async function gitDeleteBranch(workspaceDir, branch) {
  await git(workspaceDir, ['branch', '-d', branchName(branch)]);

  return gitBranches(workspaceDir);
}

/** A new commit that undoes one; a merge commit is undone against its first parent. */
export async function gitRevert(workspaceDir, sha) {
  const id = commitId(sha);
  const parents = (await git(workspaceDir, ['rev-list', '--parents', '-n', '1', id])).trim().split(' ').length - 1;
  await git(workspaceDir, ['revert', '--no-edit', ...(parents > 1 ? ['-m', '1'] : []), id]);

  return gitState(workspaceDir);
}

/** Put the changes aside (untracked files too), or bring the newest ones back. */
export async function gitStash(workspaceDir, pop = false) {
  await git(workspaceDir, pop ? ['stash', 'pop', '-q'] : ['stash', 'push', '-q', '-u']);

  return gitState(workspaceDir);
}
