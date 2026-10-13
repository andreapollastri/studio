import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { access } from 'node:fs/promises';
import { isAbsolute, join } from 'node:path';

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

/** A git call whose exit code is part of the answer (1 for "there are differences"): the output and the code, never a throw for those. */
async function gitWithCode(workspaceDir, args, codes = [0, 1]) {
  try {
    const { stdout } = await run('git', args, { cwd: workspaceDir, maxBuffer: 8 * 1024 * 1024 });
    return { code: 0, stdout };
  } catch (error) {
    if (codes.includes(error.code)) return { code: error.code, stdout: (error.stdout || '').toString() };
    if (error.code === 'ENOENT') throw new GitError(500, 'git is not installed in the workspace');
    const told = [error.stderr, error.stdout].map((out) => (out || '').toString().trim()).filter((out) => out !== '').join('\n');
    throw new GitError(422, told || error.message || 'git failed');
  }
}

function bounded(text) {
  return text.length > DIFF_LIMIT ? text.slice(0, DIFF_LIMIT) + '\n… (truncated)' : text;
}

async function hasHead(workspaceDir) {
  return succeeds(workspaceDir, ['rev-parse', '-q', '--verify', 'HEAD']);
}

/**
 * The working tree file by file, from `git status -z` so names with spaces survive: what is staged (X),
 * what is not (Y), untracked and conflicted files, and where a rename comes from.
 */
async function statusFiles(workspaceDir) {
  const parts = (await git(workspaceDir, ['status', '--porcelain=v1', '-z', '--untracked-files=all'])).split('\0');
  const files = [];
  for (let i = 0; i < parts.length; i++) {
    const entry = parts[i];
    if (entry.length < 4) continue;
    const x = entry[0];
    const y = entry[1];
    const path = entry.slice(3);
    const from = x === 'R' || x === 'C' ? parts[++i] ?? null : null;
    const conflicted = /^(DD|AU|UD|UA|DU|AA|UU)$/.test(x + y);
    files.push({
      path,
      from,
      index: x,
      worktree: y,
      staged: !conflicted && x !== ' ' && x !== '?',
      unstaged: !conflicted && (y !== ' ' || x === '?'),
      untracked: x === '?',
      conflicted,
    });
  }
  return files;
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

/**
 * Branch, the files of the working tree (staged, unstaged, untracked, conflicted), diffstat and a bounded diff
 * against the last commit, where the branch stands against its upstream, the last commit, and what git is
 * in the middle of, if anything.
 */
export async function gitState(workspaceDir) {
  const branch = (await git(workspaceDir, ['rev-parse', '--abbrev-ref', 'HEAD']).catch(() => 'HEAD')).trim();
  const files = await statusFiles(workspaceDir);
  const status = files.map((f) => `${f.index}${f.worktree} ${f.from ? `${f.from} -> ${f.path}` : f.path}`);
  const head = await hasHead(workspaceDir);
  const stat = (await git(workspaceDir, ['diff', ...(head ? ['HEAD'] : ['--cached']), '--stat=120'])).trimEnd();
  const diff = bounded(await git(workspaceDir, ['diff', ...(head ? ['HEAD'] : ['--cached'])]));

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

  const operation = await inProgress(workspaceDir);
  const merging = operation === 'merge';
  const conflicts = files.filter((f) => f.conflicted).map((f) => f.path);
  const stashes = (await git(workspaceDir, ['stash', 'list'])).split('\n').filter((l) => l !== '').length;

  let last = null;
  if (head) {
    const [sha, short, subject, message] = (await git(workspaceDir, ['log', '-1', '--format=%H%x1f%h%x1f%s%x1f%B'])).split('\x1f');
    const pushed = (await git(workspaceDir, ['branch', '-r', '--contains', sha])).trim() !== '';
    last = { sha, short, subject, message: (message || '').trim(), pushed };
  }

  return { branch, detached: branch === 'HEAD', status, files, stat, diff, upstream, ahead, behind, merging, operation, conflicts, stashes, last };
}

/** One file's diff: what is staged, what is not, or the whole content of a new file. */
export async function gitDiffFile(workspaceDir, path) {
  const name = treePath(path);
  const file = (await statusFiles(workspaceDir)).find((f) => f.path === name || f.from === name) ?? null;
  if (file?.untracked) {
    const { stdout } = await gitWithCode(workspaceDir, ['diff', '--no-index', '--', '/dev/null', name]);
    return { path: name, staged: '', unstaged: bounded(stdout), untracked: true };
  }
  const staged = await git(workspaceDir, ['diff', '--cached', '--', ...(file?.from ? [file.from] : []), name]);
  const unstaged = await git(workspaceDir, ['diff', '--', name]);

  return { path: name, staged: bounded(staged), unstaged: bounded(unstaged), untracked: false };
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

/** The newest commits of every branch, in date order, with parents so a graph can be drawn; `search` keeps those whose message or author says it. */
export async function gitLog(workspaceDir, { limit = 80, all = true, search = '' } = {}) {
  const max = Math.max(1, Math.min(LOG_LIMIT, Number(limit) || 80));
  const words = String(search ?? '').trim().slice(0, 200);
  const filter = words === '' ? [] : ['--regexp-ignore-case', '--fixed-strings', `--grep=${words}`];
  const raw = await git(workspaceDir, ['log', `--max-count=${max}`, '--date-order', `--format=${COMMIT}`, ...filter, ...(all ? ['--all'] : []), '--']);
  if (words !== '') {
    // an author or a commit id matches too: git ORs neither with --grep, so add them by hand
    const byAuthor = await git(workspaceDir, ['log', `--max-count=${max}`, '--date-order', `--format=${COMMIT}`, '--regexp-ignore-case', '--fixed-strings', `--author=${words}`, ...(all ? ['--all'] : []), '--']);
    const byId = /^[0-9a-f]{4,40}$/i.test(words) ? await git(workspaceDir, ['log', '-1', `--format=${COMMIT}`, words, '--']).catch(() => '') : '';
    const seen = new Map();
    for (const commit of [...parseCommits(byId), ...parseCommits(raw), ...parseCommits(byAuthor)]) seen.set(commit.sha, commit);
    const commits = [...seen.values()].sort((a, b) => (a.date < b.date ? 1 : -1)).slice(0, max);
    const branch = (await git(workspaceDir, ['rev-parse', '--abbrev-ref', 'HEAD'])).trim();
    const head = (await git(workspaceDir, ['rev-parse', 'HEAD'])).trim();

    return { branch, head, commits, search: words };
  }
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
  const merged = (await hasHead(workspaceDir))
    ? new Set((await git(workspaceDir, ['for-each-ref', '--merged', 'HEAD', '--format=%(refname)', 'refs/heads', 'refs/remotes'])).split('\n').filter((l) => l !== ''))
    : new Set();

  for (const line of raw.split('\n').filter((l) => l !== '')) {
    const [ref, name, head, upstream, track, short, date, subject] = line.split('\x1f');
    if (ref.startsWith('refs/heads/')) {
      local.push({ name, current: head === '*', upstream: upstream || null, track: track || '', gone: /\bgone\b/.test(track || ''), merged: merged.has(ref), sha: short, date, subject });
    } else if (ref.startsWith('refs/remotes/') && !ref.endsWith('/HEAD')) {
      remote.push({ name, merged: merged.has(ref), sha: short, date, subject });
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
  const patch = bounded(await git(workspaceDir, ['show', '--format=', '-p', sha, '--']));
  const body = (await git(workspaceDir, ['show', '-s', '--format=%b', sha, '--'])).trim();
  // reset and cherry-pick make sense on different sides of HEAD
  const onCurrent = await succeeds(workspaceDir, ['merge-base', '--is-ancestor', commit.sha, 'HEAD']);

  return { ...commit, body, stat, patch, onCurrent };
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

/** What git has left half done in this working tree, if anything. */
async function inProgress(workspaceDir) {
  for (const [ref, what] of [['MERGE_HEAD', 'merge'], ['REVERT_HEAD', 'revert'], ['CHERRY_PICK_HEAD', 'cherry-pick']]) {
    if (await succeeds(workspaceDir, ['rev-parse', '-q', '--verify', ref])) return what;
  }
  for (const dir of ['rebase-merge', 'rebase-apply']) {
    const path = (await git(workspaceDir, ['rev-parse', '--git-path', dir])).trim();
    if (await exists(workspaceDir, path)) return 'rebase';
  }
  return null;
}

async function exists(workspaceDir, path) {
  try {
    await access(isAbsolute(path) ? path : join(workspaceDir, path));
    return true;
  } catch {
    return false;
  }
}

/**
 * Refuse to start an operation that brings in other commits while git is in the middle of something
 * or tracked files have uncommitted changes: undoing it could not give those changes back.
 */
async function readyFor(workspaceDir, doing) {
  const busy = await inProgress(workspaceDir);
  if (busy) throw new GitError(409, `a ${busy} is in progress: conclude or abort it before ${doing}.`);
  const changed = (await git(workspaceDir, ['status', '--porcelain=v1', '--untracked-files=no'])).trim();
  if (changed !== '') throw new GitError(409, `there are uncommitted changes: commit or stash them before ${doing}.`);
}

/** Files git left with conflict markers. */
async function conflicted(workspaceDir) {
  return (await git(workspaceDir, ['diff', '--name-only', '--diff-filter=U'])).split('\n').filter((l) => l !== '');
}

/**
 * Run a git command that may stop on conflicts; when it fails, put the tree back with `undo` so nothing
 * is left half done, and tell the conflicting files when there were any.
 */
async function allOrNothing(workspaceDir, args, undo, explain) {
  try {
    await git(workspaceDir, args);
  } catch (error) {
    const files = await conflicted(workspaceDir).catch(() => []);
    if (files.length > 0 || (await inProgress(workspaceDir))) {
      try {
        await git(workspaceDir, undo);
      } catch {
        await git(workspaceDir, ['reset', '-q', '--merge']).catch(() => {});
      }
    }
    if (files.length > 0) throw new GitError(409, explain(files));
    throw error;
  }
}

/** What the commits brought in since `before` ask of the app: dependencies to install, assets to build, migrations to run. */
async function afterChanges(workspaceDir, before) {
  const head = (await git(workspaceDir, ['rev-parse', 'HEAD'])).trim();
  if (head === before) return [];
  const files = (await git(workspaceDir, ['diff', '--name-only', before, head])).split('\n').filter((l) => l !== '');
  const touched = (pattern) => files.some((file) => pattern.test(file));
  const steps = [];
  if (touched(/^composer\.(json|lock)$/)) steps.push('composer install');
  if (touched(/^(package\.json|package-lock\.json)$/)) steps.push('npm install');
  if (touched(/^(package\.json|package-lock\.json|vite\.config\.[cm]?[jt]s)$/) || touched(/^resources\/(js|css)\//)) steps.push('npm run build');
  if (touched(/^database\/migrations\//)) steps.push('php artisan migrate');
  return steps;
}

/** A path of the working tree as git status prints it: relative, inside the workspace. */
function treePath(path) {
  const name = String(path ?? '').trim().replace(/^"(.*)"$/, '$1');
  if (name === '' || name.startsWith('/') || name.startsWith('-') || name.split('/').includes('..')) throw new GitError(400, 'invalid path');
  return name;
}

/** A tag name follows the rules of a branch name. */
function tagName(tag) {
  try {
    return branchName(tag);
  } catch {
    throw new GitError(400, 'invalid tag name');
  }
}

/** A stash as `git stash list` numbers it. */
function stashRef(index) {
  const n = Number(index ?? 0);
  if (!Number.isInteger(n) || n < 0 || n > 999) throw new GitError(400, 'invalid stash');
  return `stash@{${n}}`;
}

/** Paths as the working tree lists them, or none for "every file". */
function treePaths(paths) {
  if (paths === null || paths === undefined) return [];
  const list = Array.isArray(paths) ? paths : [paths];
  if (list.length === 0 || list.length > 500) throw new GitError(400, 'invalid paths');
  return list.map(treePath);
}

/** The remote a remote-tracking name like `origin/feature/x` lives on, and the branch there. */
async function remoteBranch(workspaceDir, name) {
  const remotes = (await git(workspaceDir, ['remote'])).split('\n').filter((l) => l !== '');
  const remote = remotes.find((r) => name.startsWith(`${r}/`));
  if (!remote) throw new GitError(400, `${name} is not on a remote`);
  return { remote, branch: name.slice(remote.length + 1) };
}

/** Switch the working tree to a branch; a remote branch gets a local tracking branch. */
export async function gitCheckout(workspaceDir, branch) {
  const name = branchName(branch);
  const busy = await inProgress(workspaceDir);
  if (busy) throw new GitError(409, `a ${busy} is in progress: conclude or abort it before switching branch.`);
  try {
    await git(workspaceDir, ['checkout', '-q', name, '--']);
  } catch (error) {
    if (!(await succeeds(workspaceDir, ['rev-parse', '-q', '--verify', `refs/remotes/${name}`]))) throw error;
    const { branch: local } = await remoteBranch(workspaceDir, name);
    // the local branch may already exist: then it is simply switched to
    if (await succeeds(workspaceDir, ['rev-parse', '-q', '--verify', `refs/heads/${local}`])) await git(workspaceDir, ['checkout', '-q', local, '--']);
    else await git(workspaceDir, ['checkout', '-q', '--track', name]);
  }

  return gitState(workspaceDir);
}

/** `git fetch --prune` with the workspace's own credentials, tags included, then the branches as they are now. */
export async function gitFetch(workspaceDir) {
  await git(workspaceDir, ['fetch', '--prune', '--tags', '--quiet']);

  return gitBranches(workspaceDir);
}

/** Put files in the index, or every change when none are named. */
export async function gitStage(workspaceDir, paths = null) {
  const names = treePaths(paths);
  await git(workspaceDir, ['add', '-A', '--', ...(names.length ? names : ['.'])]);

  return gitState(workspaceDir);
}

/** Take files out of the index, keeping their changes in the working tree. */
export async function gitUnstage(workspaceDir, paths = null) {
  const names = treePaths(paths);
  if (await hasHead(workspaceDir)) await git(workspaceDir, ['reset', '-q', 'HEAD', '--', ...(names.length ? names : ['.'])]);
  else await git(workspaceDir, ['rm', '-q', '-r', '--cached', '--ignore-unmatch', '--', ...(names.length ? names : ['.'])]);

  return gitState(workspaceDir);
}

/**
 * Commit what is staged, or every change with `all`; `amend` rewrites the last commit instead, keeping its
 * message when none is given. While a merge is in progress this concludes the merge.
 */
export async function gitCommitAll(workspaceDir, message, { all = true, amend = false } = {}) {
  const text = String(message ?? '').trim();
  if (text === '' && !amend) throw new GitError(400, 'a commit message is required');
  if (all) await git(workspaceDir, ['add', '-A']);
  const conflicts = (await statusFiles(workspaceDir)).filter((f) => f.conflicted).map((f) => f.path);
  if (conflicts.length > 0) throw new GitError(409, `resolve the conflicts first: ${conflicts.join(', ')}.`);
  if (!all && !amend && (await succeeds(workspaceDir, ['diff', '--cached', '--quiet'])) && !(await inProgress(workspaceDir))) {
    throw new GitError(409, 'nothing is staged: stage the files to commit, or commit all.');
  }
  await git(workspaceDir, ['commit', '-q', ...(amend ? ['--amend'] : []), ...(text === '' ? ['--no-edit'] : ['-m', text])]);

  return gitState(workspaceDir);
}

/** Settle one conflicted file of an open merge: keep this branch's side, take the other's, or mark it resolved as edited. */
export async function gitResolve(workspaceDir, path, side) {
  const name = treePath(path);
  if (!['ours', 'theirs', 'resolved'].includes(side)) throw new GitError(400, 'invalid side');
  if (side !== 'resolved') {
    try {
      await git(workspaceDir, ['checkout', `--${side}`, '--', name]);
    } catch {
      // that side deleted the file: taking it means deleting it
      await git(workspaceDir, ['rm', '-q', '--', name]);

      return gitState(workspaceDir);
    }
  } else {
    const content = await git(workspaceDir, ['diff', '--', name]).catch(() => '');
    if (/^\+(<{7}|>{7})( |$)/m.test(content)) throw new GitError(409, `${name} still has conflict markers.`);
  }
  await git(workspaceDir, ['add', '--', name]);

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
  await readyFor(workspaceDir, 'pulling');
  const before = (await git(workspaceDir, ['rev-parse', 'HEAD'])).trim();
  await allOrNothing(workspaceDir, ['pull', '-q', '--no-rebase', '--no-edit'], ['merge', '--abort'],
    (files) => `the remote conflicts with this branch in ${files.join(', ')}: nothing was pulled. Ask Claude to pull and resolve the conflicts.`);

  return { ...(await gitState(workspaceDir)), after: await afterChanges(workspaceDir, before) };
}

/**
 * Push the current branch; the first push of a new branch sets its upstream on origin. `force` overwrites
 * the remote branch after an amend or a reset, but only if nobody pushed to it meanwhile (--force-with-lease).
 */
export async function gitPush(workspaceDir, { force = false } = {}) {
  const tracked = await succeeds(workspaceDir, ['rev-parse', '--abbrev-ref', '@{u}']);
  await git(workspaceDir, [...(tracked ? ['push', '-q'] : ['push', '-q', '-u', 'origin', 'HEAD']), ...(force ? ['--force-with-lease'] : [])]);

  return gitState(workspaceDir);
}

/**
 * What merging a branch would bring: its commits missing here, the files it changes, whether it is a
 * fast-forward, and the files that would conflict (from `git merge-tree`, which merges without touching
 * the working tree; null when this git is too old to say).
 */
export async function gitCompare(workspaceDir, branch) {
  const name = branchName(branch);
  if (!(await succeeds(workspaceDir, ['rev-parse', '-q', '--verify', `${name}^{commit}`]))) throw new GitError(404, `unknown branch ${name}`);
  const commits = parseCommits(await git(workspaceDir, ['log', '--max-count=50', `--format=${COMMIT}`, `HEAD..${name}`, '--']));
  const [behind, ahead] = (await git(workspaceDir, ['rev-list', '--left-right', '--count', `HEAD...${name}`])).trim().split(/\s+/).map(Number);
  const stat = (await git(workspaceDir, ['diff', '--stat=120', `HEAD...${name}`])).trimEnd();
  const files = (await git(workspaceDir, ['diff', '--name-only', `HEAD...${name}`])).split('\n').filter((l) => l !== '');
  const fastForward = await succeeds(workspaceDir, ['merge-base', '--is-ancestor', 'HEAD', name]);

  let conflicts = null;
  if (ahead > 0 && !fastForward) {
    try {
      const { code, stdout } = await gitWithCode(workspaceDir, ['merge-tree', '--write-tree', '--name-only', '--no-messages', 'HEAD', name]);
      conflicts = code === 1 ? stdout.split('\n').slice(1).filter((l) => l !== '') : [];
    } catch {
      conflicts = null;
    }
  }

  return { branch: name, commits, ahead: ahead || 0, behind: behind || 0, stat, files, fastForward, conflicts: conflicts === null ? null : [...new Set(conflicts)] };
}

/**
 * Merge a branch into the current one, all or nothing: the app runs from this working tree, so a merge
 * that stops on conflicts is undone and the conflicting files are told instead of left with their markers.
 * `mode` is "merge" (fast-forward when possible), "no-ff" (always a merge commit) or "squash" (the changes
 * staged, to commit as one). Afterwards it says what the merged changes ask for, such as new dependencies or migrations.
 */
export async function gitMerge(workspaceDir, branch, { mode = 'merge' } = {}) {
  const name = branchName(branch);
  if (!['merge', 'no-ff', 'squash'].includes(mode)) throw new GitError(400, 'invalid merge mode');
  await readyFor(workspaceDir, 'merging');
  const before = (await git(workspaceDir, ['rev-parse', 'HEAD'])).trim();
  const args = mode === 'squash' ? ['merge', '-q', '--squash', name] : ['merge', '-q', '--no-edit', ...(mode === 'no-ff' ? ['--no-ff'] : []), name];
  await allOrNothing(workspaceDir, args, mode === 'squash' ? ['reset', '-q', '--merge'] : ['merge', '--abort'],
    (files) => `merging ${name} conflicts in ${files.join(', ')}: nothing was merged. Ask Claude to merge it and resolve the conflicts, or align the two branches first.`);
  if (mode === 'squash') {
    // nothing is committed yet: prepare the message the commit form shows
    return { ...(await gitState(workspaceDir)), after: [], squashed: name };
  }

  return { ...(await gitState(workspaceDir)), after: await afterChanges(workspaceDir, before) };
}

export async function gitMergeAbort(workspaceDir) {
  const busy = await inProgress(workspaceDir);
  if (busy === 'revert') await git(workspaceDir, ['revert', '--abort']);
  else if (busy === 'cherry-pick') await git(workspaceDir, ['cherry-pick', '--abort']);
  else if (busy === 'rebase') await git(workspaceDir, ['rebase', '--abort']);
  else await git(workspaceDir, ['merge', '--abort']);

  return gitState(workspaceDir);
}

/** Create a branch from the current commit, or from the one given, and switch to it. */
export async function gitCreateBranch(workspaceDir, branch, from = null) {
  const name = branchName(branch);
  if (name.startsWith('origin/')) throw new GitError(400, 'invalid branch name');
  await git(workspaceDir, ['checkout', '-q', '-b', name, ...(from ? [commitId(from)] : []), '--']);

  return gitState(workspaceDir);
}

export async function gitRenameBranch(workspaceDir, from, to) {
  const name = branchName(to);
  if (name.startsWith('origin/')) throw new GitError(400, 'invalid branch name');
  await git(workspaceDir, ['branch', '-m', branchName(from), name]);

  return gitBranches(workspaceDir);
}

/**
 * Delete a local branch; git refuses when it is checked out, or not merged unless `force`. With `remote`
 * its branch on the remote goes too.
 */
export async function gitDeleteBranch(workspaceDir, branch, { force = false, remote = false } = {}) {
  const name = branchName(branch);
  const upstream = (await git(workspaceDir, ['for-each-ref', '--format=%(upstream:remotename)%1f%(upstream:lstrip=3)', `refs/heads/${name}`])).trim();
  await git(workspaceDir, ['branch', force ? '-D' : '-d', name]);
  if (remote) {
    const [remoteName, remoteBranchName] = upstream.split('\x1f');
    await git(workspaceDir, ['push', '-q', remoteName || 'origin', '--delete', remoteBranchName || name]);
  }

  return gitBranches(workspaceDir);
}

/** Delete a branch on its remote, named as listed: `origin/feature/x`. */
export async function gitDeleteRemoteBranch(workspaceDir, branch) {
  const { remote, branch: name } = await remoteBranch(workspaceDir, branchName(branch));
  await git(workspaceDir, ['push', '-q', remote, '--delete', name]);

  return gitBranches(workspaceDir);
}

/** A new commit that undoes one; a merge commit is undone against its first parent. */
export async function gitRevert(workspaceDir, sha) {
  const id = commitId(sha);
  await readyFor(workspaceDir, 'reverting');
  const parents = (await git(workspaceDir, ['rev-list', '--parents', '-n', '1', id])).trim().split(' ').length - 1;
  await allOrNothing(workspaceDir, ['revert', '--no-edit', ...(parents > 1 ? ['-m', '1'] : []), id], ['revert', '--abort'],
    (files) => `undoing this commit conflicts with later changes in ${files.join(', ')}: nothing was reverted.`);

  return gitState(workspaceDir);
}

/** Copy one commit of another branch onto the current one, all or nothing. */
export async function gitCherryPick(workspaceDir, sha) {
  const id = commitId(sha);
  await readyFor(workspaceDir, 'cherry-picking');
  const parents = (await git(workspaceDir, ['rev-list', '--parents', '-n', '1', id])).trim().split(' ').length - 1;
  await allOrNothing(workspaceDir, ['cherry-pick', ...(parents > 1 ? ['-m', '1'] : []), id], ['cherry-pick', '--abort'],
    (files) => `this commit conflicts with the current branch in ${files.join(', ')}: nothing was copied.`);

  return gitState(workspaceDir);
}

/**
 * Move the current branch to a commit: `soft` keeps its later changes staged, `mixed` keeps them in the
 * working tree, `hard` throws them away together with every uncommitted change.
 */
export async function gitReset(workspaceDir, sha, mode = 'mixed') {
  const id = commitId(sha);
  if (!['soft', 'mixed', 'hard'].includes(mode)) throw new GitError(400, 'invalid reset mode');
  const busy = await inProgress(workspaceDir);
  if (busy) throw new GitError(409, `a ${busy} is in progress: conclude or abort it before resetting.`);
  await git(workspaceDir, ['reset', '-q', `--${mode}`, id]);

  return gitState(workspaceDir);
}

/** Tags, newest first, with the commit each points to and the message of annotated ones. */
export async function gitTags(workspaceDir) {
  const raw = await git(workspaceDir, [
    'for-each-ref',
    '--sort=-creatordate',
    '--format=%(refname:short)%1f%(objecttype)%1f%(objectname:short)%1f%(*objectname:short)%1f%(creatordate:iso-strict)%1f%(contents:subject)',
    'refs/tags',
  ]);
  const remote = new Set();
  try {
    // which tags the remote has, so the list can say what is still to push
    const { stdout } = await gitWithCode(workspaceDir, ['ls-remote', '--tags', '--refs', 'origin'], [0, 2]);
    for (const line of stdout.split('\n')) if (line.includes('refs/tags/')) remote.add(line.split('refs/tags/')[1]);
  } catch {
    // no remote, or not reachable right now
  }

  const tags = raw.split('\n').filter((l) => l !== '').map((line) => {
    const [name, type, sha, target, date, subject] = line.split('\x1f');
    const annotated = type === 'tag';
    return { name, annotated, sha: annotated ? target : sha, date, message: annotated ? subject : '', pushed: remote.has(name) };
  });

  return { tags };
}

/** A tag on the current commit or the one given; with a message it is annotated. */
export async function gitCreateTag(workspaceDir, tag, { at = null, message = '' } = {}) {
  const name = tagName(tag);
  const text = String(message ?? '').trim();
  await git(workspaceDir, ['tag', ...(text ? ['-a', '-m', text] : []), name, ...(at ? [commitId(at)] : [])]);

  return gitTags(workspaceDir);
}

export async function gitDeleteTag(workspaceDir, tag, { remote = false } = {}) {
  const name = tagName(tag);
  await git(workspaceDir, ['tag', '-d', name]);
  if (remote) await git(workspaceDir, ['push', '-q', 'origin', '--delete', `refs/tags/${name}`]);

  return gitTags(workspaceDir);
}

/** Push one tag, or every tag when none is named. */
export async function gitPushTags(workspaceDir, tag = null) {
  await git(workspaceDir, ['push', '-q', 'origin', ...(tag ? [`refs/tags/${tagName(tag)}`] : ['--tags'])]);

  return gitTags(workspaceDir);
}

/** The stashes, newest first, with their message and the files each holds. */
export async function gitStashes(workspaceDir) {
  const raw = await git(workspaceDir, ['stash', 'list', '--format=%gd%x1f%gs%x1f%cI%x1e']);
  const stashes = [];
  for (const record of raw.split('\x1e').map((r) => r.trim()).filter((r) => r !== '')) {
    const [ref, subject, date] = record.split('\x1f');
    const index = Number(/\{(\d+)\}/.exec(ref)?.[1] ?? stashes.length);
    const files = (await gitWithCode(workspaceDir, ['stash', 'show', '--name-only', '--include-untracked', ref], [0, 1, 128]).catch(() => ({ stdout: '' }))).stdout
      .split('\n').filter((l) => l !== '');
    // "On main: message" or "WIP on main: abc123 subject"
    const [, branch = '', message = subject] = /^(?:WIP )?[Oo]n ([^:]+): (.*)$/.exec(subject) ?? [];
    stashes.push({ index, ref, branch, message, date, files });
  }

  return { stashes };
}

/** One stash's patch, new files included. */
export async function gitStashShow(workspaceDir, index = 0) {
  const ref = stashRef(index);
  const { stdout } = await gitWithCode(workspaceDir, ['stash', 'show', '-p', '--include-untracked', ref], [0, 1]);

  return { index: Number(index), patch: bounded(stdout) };
}

/** Put the changes aside, untracked files too unless told otherwise, with an optional message. */
export async function gitStashPush(workspaceDir, { message = '', untracked = true } = {}) {
  const text = String(message ?? '').trim();
  await git(workspaceDir, ['stash', 'push', '-q', ...(untracked ? ['-u'] : []), ...(text ? ['-m', text] : [])]);

  return gitState(workspaceDir);
}

/** Bring a stash back, keeping it in the list (`apply`) or dropping it (`pop`); one that no longer applies cleanly changes nothing. */
export async function gitStashApply(workspaceDir, index = 0, { pop = true } = {}) {
  const ref = stashRef(index);
  await readyFor(workspaceDir, 'restoring the stash');
  await allOrNothing(workspaceDir, ['stash', pop ? 'pop' : 'apply', '-q', ref], ['reset', '-q', '--merge'],
    (files) => `the stash conflicts with the current branch in ${files.join(', ')}: it was kept and nothing changed.`);

  return gitState(workspaceDir);
}

export async function gitStashDrop(workspaceDir, index = 0) {
  await git(workspaceDir, ['stash', 'drop', '-q', stashRef(index)]);

  return gitState(workspaceDir);
}

/** Put the changes aside (untracked files too), or bring the newest ones back. */
export async function gitStash(workspaceDir, pop = false) {
  return pop ? gitStashApply(workspaceDir, 0, { pop: true }) : gitStashPush(workspaceDir);
}
