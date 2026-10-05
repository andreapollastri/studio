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
    throw new GitError(422, (error.stderr || error.message || 'git failed').toString().trim());
  }
}

/** Branch, porcelain status, diffstat and a bounded diff of the working tree. */
export async function gitState(workspaceDir) {
  const branch = (await git(workspaceDir, ['rev-parse', '--abbrev-ref', 'HEAD'])).trim();
  const status = (await git(workspaceDir, ['status', '--porcelain=v1'])).split('\n').filter((l) => l !== '');
  const stat = (await git(workspaceDir, ['diff', '--stat'])).trimEnd();
  let diff = await git(workspaceDir, ['diff']);
  if (diff.length > DIFF_LIMIT) diff = diff.slice(0, DIFF_LIMIT) + '\n… (truncated)';

  return { branch, status, stat, diff };
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
  if (!/^[0-9a-f]{4,40}$/i.test(String(sha ?? ''))) throw new GitError(400, 'invalid commit id');
  const [commit] = parseCommits(await git(workspaceDir, ['show', '-s', `--format=${COMMIT}`, sha, '--']));
  if (!commit) throw new GitError(404, 'unknown commit');
  const stat = (await git(workspaceDir, ['show', '--stat=120', '--format=', sha, '--'])).trimEnd();
  let patch = await git(workspaceDir, ['show', '--format=', '-p', sha, '--']);
  if (patch.length > DIFF_LIMIT) patch = patch.slice(0, DIFF_LIMIT) + '\n… (truncated)';

  return { ...commit, stat, patch };
}

/** Switch the working tree to a branch; a remote branch gets a local tracking branch. */
export async function gitCheckout(workspaceDir, branch) {
  const name = String(branch ?? '').trim();
  if (!BRANCH.test(name) || name.includes('..') || name.endsWith('.lock')) throw new GitError(400, 'invalid branch name');
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
