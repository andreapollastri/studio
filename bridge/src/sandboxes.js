import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { copyFile, mkdir, rm, stat } from 'node:fs/promises';
import { existsSync, readFileSync } from 'node:fs';
import { basename, dirname, join, resolve } from 'node:path';
import { GitError, gitState } from './git.js';

const run = promisify(execFile);
const NAME = /^c[0-9]{1,12}$/;
const BRANCH = /^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$/;

/**
 * Sandboxes: one git worktree per isolated conversation, next to the
 * workspace (by default `<parent>/.sandboxes/<workspace>/<name>`), each on its
 * own branch of the same repository. Claude Code runs in it, the Changes and
 * Files tabs can be pointed at it, and the workspace merges its branch like
 * any other: the commits are already in the one repository both share.
 *
 * The bridge creates and removes the worktree; on a server, the helper then
 * gives it its .env, database, build and preview host (studio-admin sandbox-*).
 */
export class Sandboxes {
  constructor(workspaceDir, root = null) {
    this.workspaceDir = resolve(workspaceDir);
    this.root = resolve(root || join(dirname(this.workspaceDir), '.sandboxes', basename(this.workspaceDir)));
  }

  /** The directory of a sandbox, checked: a name like c12, under the root, never anything else. */
  dir(name) {
    if (!NAME.test(String(name ?? ''))) throw new GitError(400, 'invalid sandbox name');
    return join(this.root, String(name));
  }

  /** The directory of a sandbox that exists, for the routes and the sessions that work in it. */
  resolve(name) {
    const dir = this.dir(name);
    if (!existsSync(join(dir, '.git'))) throw new GitError(404, `sandbox ${name} does not exist`);
    return dir;
  }

  /** The branch a sandbox is on, read from its git files (no git call: a session needs it at once). */
  branchOf(name) {
    try {
      const pointer = readFileSync(join(this.resolve(name), '.git'), 'utf8').match(/^gitdir:\s*(.+)$/m);
      const head = pointer ? readFileSync(join(resolve(this.resolve(name), pointer[1].trim()), 'HEAD'), 'utf8') : '';
      return head.match(/^ref:\s*refs\/heads\/(.+)$/m)?.[1]?.trim() ?? null;
    } catch {
      return null;
    }
  }

  /**
   * A worktree on `branch`: created from `base` (the workspace's HEAD by default) when the branch is new,
   * or on the existing branch when it is not. Asking again for a sandbox that exists answers its state.
   */
  async create(name, { branch, base = null } = {}) {
    const dir = this.dir(name);
    const ref = String(branch ?? '').trim();
    if (!BRANCH.test(ref) || ref.includes('..') || ref.endsWith('.lock') || ref.endsWith('/')) throw new GitError(400, 'invalid branch name');

    if (!existsSync(join(dir, '.git'))) {
      await mkdir(this.root, { recursive: true, mode: 0o751 });
      // what an interrupted attempt left: git worktree add refuses a directory that is not empty
      await rm(dir, { recursive: true, force: true });
      await this.#git(this.workspaceDir, ['worktree', 'prune']);

      const from = base ? String(base).trim() : 'HEAD';
      if (!/^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$/.test(from)) throw new GitError(400, 'invalid base');
      const exists = await this.#succeeds(this.workspaceDir, ['rev-parse', '-q', '--verify', `refs/heads/${ref}`]);
      await this.#git(this.workspaceDir, exists ? ['worktree', 'add', '-q', dir, ref] : ['worktree', 'add', '-q', '-b', ref, dir, from]);

      // the workspace's settings, so artisan and the tests run here too; on a server the helper rewrites them
      const env = join(this.workspaceDir, '.env');
      if (existsSync(env) && !existsSync(join(dir, '.env'))) await copyFile(env, join(dir, '.env'));
    }

    return this.state(name);
  }

  /** The sandbox's working tree, and how far its branch is from the workspace's. */
  async state(name) {
    const dir = this.resolve(name);
    const tree = await gitState(dir);
    const workspaceBranch = (await this.#git(this.workspaceDir, ['rev-parse', '--abbrev-ref', 'HEAD']).catch(() => 'HEAD')).trim();
    let ahead = 0;
    let behind = 0;
    try {
      const [left, right] = (await this.#git(this.workspaceDir, ['rev-list', '--left-right', '--count', `HEAD...${tree.branch}`])).trim().split(/\s+/);
      behind = Number(left) || 0;
      ahead = Number(right) || 0;
    } catch {
      // a detached or unborn head: nothing to compare
    }

    return { name, path: dir, branch: tree.branch, workspace_branch: workspaceBranch, ahead, behind, files: tree.status.length, status: tree.status, stat: tree.stat, upstream: tree.upstream };
  }

  /** Everything in the sandbox committed on its branch; nothing to commit is not an error. */
  async commit(name, message) {
    const dir = this.resolve(name);
    const text = String(message ?? '').trim();
    if (text === '') throw new GitError(400, 'a commit message is required');
    await this.#git(dir, ['add', '-A']);
    if (!(await this.#succeeds(dir, ['diff', '--cached', '--quiet']))) {
      await this.#git(dir, ['commit', '-q', '-m', text]);
    }
    return this.state(name);
  }

  /** The worktree goes; its branch too when asked (a discarded sandbox), else it stays to merge or push later. */
  async remove(name, { deleteBranch = false } = {}) {
    const dir = this.dir(name);
    let branch = null;
    if (existsSync(join(dir, '.git'))) {
      branch = (await this.#git(dir, ['rev-parse', '--abbrev-ref', 'HEAD']).catch(() => '')).trim() || null;
      await this.#git(this.workspaceDir, ['worktree', 'remove', '--force', dir]).catch(() => null);
    }
    await rm(dir, { recursive: true, force: true });
    await this.#git(this.workspaceDir, ['worktree', 'prune']).catch(() => null);
    if (deleteBranch && branch && branch !== 'HEAD') {
      await this.#git(this.workspaceDir, ['branch', '-D', branch]).catch(() => null);
    }
    return { name, removed: true, branch, branch_deleted: Boolean(deleteBranch && branch) };
  }

  async exists(name) {
    try {
      return (await stat(join(this.dir(name), '.git'))) !== null;
    } catch {
      return false;
    }
  }

  async #git(cwd, args) {
    try {
      const { stdout } = await run('git', args, { cwd, maxBuffer: 8 * 1024 * 1024 });
      return stdout;
    } catch (error) {
      if (error.code === 'ENOENT') throw new GitError(500, 'git is not installed in the workspace');
      const told = [error.stderr, error.stdout].map((out) => (out || '').toString().trim()).filter((out) => out !== '').join('\n');
      throw new GitError(422, told || error.message || 'git failed');
    }
  }

  async #succeeds(cwd, args) {
    try {
      await this.#git(cwd, args);
      return true;
    } catch {
      return false;
    }
  }
}
