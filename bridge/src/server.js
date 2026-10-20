import http from 'node:http';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { timingSafeEqual } from 'node:crypto';
import { createRequire } from 'node:module';
import { pipeline } from 'node:stream/promises';
import { SessionManager } from './sessions.js';
import { BridgeError } from './session.js';
import { listSkills } from './skills.js';
import {
  GitError,
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
  gitDiscard,
  gitFetch,
  gitLog,
  gitMerge,
  gitMergeAbort,
  gitPull,
  gitPush,
  gitPushTags,
  gitRenameBranch,
  gitReset,
  gitResolve,
  gitRevert,
  gitStage,
  gitStash,
  gitStashApply,
  gitStashDrop,
  gitStashes,
  gitStashPush,
  gitStashShow,
  gitState,
  gitTags,
  gitUnstage,
} from './git.js';
import { CommandError, RunManager } from './commands.js';
import { FileError, Files } from './files.js';
import { Sandboxes } from './sandboxes.js';
import { ShellSession } from './shell.js';
import { suggestCommitMessage } from './commit-message.js';

export const VERSION = createRequire(import.meta.url)('../package.json').version;

/**
 * The HTTP face of the bridge. Studio is the only client: every route but
 * /health requires the bearer token from BRIDGE_TOKEN.
 */
export function createServer(config, options = {}) {
  const log = config.log ? (message) => console.log(`[bridge] ${message}`) : () => {};
  const sandboxes = new Sandboxes(config.workspaceDir, config.sandboxRoot);
  const sessions = new SessionManager(config, { ...options, log, sandboxes });
  const runs = new RunManager(config, { spawn: options.spawnCommand });
  const workspaceFiles = new Files(config.workspaceDir, { maxBytes: config.fileMaxBytes, unzipMaxBytes: config.unzipMaxBytes });
  const startedAt = Date.now();

  const server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, 'http://bridge');
      const path = url.pathname.replace(/\/+$/, '') || '/';

      // Open, so a probe needs no token; the workspace path and the sessions wait behind it (GET /conversations).
      if (req.method === 'GET' && path === '/health') {
        return json(res, 200, { ok: true, version: VERSION, uptime_s: Math.round((Date.now() - startedAt) / 1000) });
      }

      if (!authorized(req, config.token)) return json(res, 401, { error: 'invalid bridge token' });

      if (req.method === 'GET' && path === '/info') {
        return json(res, 200, { version: VERSION, workspace: config.workspaceDir, claude: config.claudeBin, browser: Boolean(config.browserCmd), sandboxes: sandboxes.root, sessions: sessions.list() });
      }

      let match;

      // The Changes, Terminal and Files tabs of a sandboxed conversation: the same routes, in its worktree.
      const sandbox = req.headers['x-studio-sandbox'];
      const dir = sandbox ? sandboxes.resolve(String(sandbox)) : config.workspaceDir;
      const files = sandbox ? new Files(dir, { maxBytes: config.fileMaxBytes, unzipMaxBytes: config.unzipMaxBytes }) : workspaceFiles;

      if (req.method === 'POST' && path === '/sandboxes') {
        const body = await readJson(req, config.maxBodyBytes);
        return json(res, 201, await sandboxes.create(body.name, { branch: body.branch, base: body.base ?? null }));
      }

      if ((match = /^\/sandboxes\/([^/]+)$/.exec(path)) && req.method === 'GET') {
        return json(res, 200, await sandboxes.state(decodeURIComponent(match[1])));
      }

      if ((match = /^\/sandboxes\/([^/]+)\/commit$/.exec(path)) && req.method === 'POST') {
        const body = await readJson(req, config.maxBodyBytes);
        return json(res, 200, await sandboxes.commit(decodeURIComponent(match[1]), body.message));
      }

      if ((match = /^\/sandboxes\/([^/]+)$/.exec(path)) && req.method === 'DELETE') {
        const name = decodeURIComponent(match[1]);
        // its conversation's Claude Code works in there: stop it first
        await sessions.stopIn(sandboxes.dir(name));
        return json(res, 200, await sandboxes.remove(name, { deleteBranch: ['1', 'true'].includes(url.searchParams.get('branch') ?? '') }));
      }

      if (req.method === 'GET' && path === '/skills') {
        // the person's own skills sit in the Claude folder the conversation signs in with (.claude, .claude-<slug>)
        const asked = url.searchParams.get('claude_dir') ?? '';
        const home = process.env.HOME || homedir();
        const claudeDir = /^\.claude(-[a-z0-9]{1,40})?$/.test(asked) ? join(home, asked) : (process.env.CLAUDE_CONFIG_DIR || join(home, '.claude'));
        return json(res, 200, { skills: await listSkills(dir, { claudeDir }) });
      }

      if (path === '/git' || path.startsWith('/git/')) {
        await gitRoute(req, res, path, url, config, dir);
        if (res.writableEnded) return;
      }

      if (req.method === 'GET' && path === '/files') {
        return json(res, 200, await files.list(url.searchParams.get('path') ?? ''));
      }

      if (req.method === 'GET' && path === '/files/read') {
        return json(res, 200, await files.read(url.searchParams.get('path') ?? ''));
      }

      if (req.method === 'PUT' && path === '/files') {
        const body = await readJson(req, config.maxBodyBytes);
        return json(res, 200, await files.write(body.path, body.content, body.hash ?? null));
      }

      if (req.method === 'PUT' && path === '/files/upload') {
        const overwrite = ['1', 'true'].includes(url.searchParams.get('overwrite') ?? '');
        return json(res, 201, await files.upload(url.searchParams.get('path') ?? '', req, { overwrite, maxBytes: config.uploadMaxBytes }));
      }

      if (req.method === 'POST' && path === '/files') {
        const body = await readJson(req, config.maxBodyBytes);
        return json(res, 201, await files.create(body.path, body.type ?? 'file'));
      }

      if (req.method === 'POST' && path === '/files/rename') {
        const body = await readJson(req, config.maxBodyBytes);
        return json(res, 200, await files.rename(body.from, body.to));
      }

      if (req.method === 'DELETE' && path === '/files') {
        return json(res, 200, await files.remove(url.searchParams.get('path') ?? ''));
      }

      if (req.method === 'GET' && path === '/files/download') {
        const file = await files.download(url.searchParams.get('path') ?? '');
        res.writeHead(200, { 'content-type': 'application/octet-stream', 'content-length': file.size });
        await pipeline(file.stream, res);
        return;
      }

      if (req.method === 'POST' && path === '/files/zip') {
        const body = await readJson(req, config.maxBodyBytes);
        return json(res, 201, await files.zip(body.paths ?? []));
      }

      if (req.method === 'POST' && path === '/files/unzip') {
        const body = await readJson(req, config.maxBodyBytes);
        return json(res, 201, await files.unzip(body.path));
      }

      if (req.method === 'POST' && path === '/run') {
        const body = await readJson(req, config.maxBodyBytes);
        return json(res, 202, runs.start(body.command, { cwd: dir }));
      }

      if (req.method === 'GET' && path === '/run') {
        return json(res, 200, { runs: runs.list() });
      }

      if ((match = /^\/run\/([^/]+)$/.exec(path)) && req.method === 'GET') {
        const run = runs.get(decodeURIComponent(match[1]));
        return run ? json(res, 200, run) : json(res, 404, { error: 'unknown run' });
      }

      if ((match = /^\/run\/([^/]+)\/kill$/.exec(path)) && req.method === 'POST') {
        const run = runs.kill(decodeURIComponent(match[1]));
        return run ? json(res, 200, run) : json(res, 404, { error: 'unknown run' });
      }

      if (req.method === 'GET' && path === '/conversations') {
        return json(res, 200, { conversations: sessions.list() });
      }

      if ((match = /^\/conversations\/([^/]+)$/.exec(path)) && req.method === 'GET') {
        const session = sessions.get(decodeURIComponent(match[1]));
        return session ? json(res, 200, session.snapshot()) : json(res, 404, { error: 'unknown conversation' });
      }

      if ((match = /^\/conversations\/([^/]+)$/.exec(path)) && req.method === 'DELETE') {
        return json(res, 200, { forgotten: await sessions.forget(decodeURIComponent(match[1])) });
      }

      if ((match = /^\/conversations\/([^/]+)\/turns$/.exec(path)) && req.method === 'POST') {
        const body = await readJson(req, config.maxBodyBytes);
        const session = sessions.getOrCreate(decodeURIComponent(match[1]));
        return json(res, 202, { accepted: true, ...session.sendTurn(body) });
      }

      if ((match = /^\/conversations\/([^/]+)\/warm$/.exec(path)) && req.method === 'POST') {
        const body = await readJson(req, config.maxBodyBytes);
        const session = sessions.getOrCreate(decodeURIComponent(match[1]));
        return json(res, 200, session.warm(body));
      }

      if ((match = /^\/conversations\/([^/]+)\/permissions\/([^/]+)$/.exec(path)) && req.method === 'POST') {
        const body = await readJson(req, config.maxBodyBytes);
        const session = sessions.get(decodeURIComponent(match[1]));
        if (!session) return json(res, 404, { error: 'unknown conversation' });
        return json(res, 200, session.answerPermission(decodeURIComponent(match[2]), body.behavior, body.message ?? null, body.answers ?? null, body.rules ?? null));
      }

      if ((match = /^\/conversations\/([^/]+)\/interrupt$/.exec(path)) && req.method === 'POST') {
        const session = sessions.get(decodeURIComponent(match[1]));
        if (!session) return json(res, 404, { error: 'unknown conversation' });
        return json(res, 200, session.interrupt());
      }

      return json(res, 404, { error: 'not found' });
    } catch (error) {
      // a download cut short: the status is already sent, so the connection ends instead
      if (res.headersSent) return res.destroy();
      if (error instanceof BridgeError || error instanceof CommandError || error instanceof FileError || error instanceof GitError) return json(res, error.status, { error: error.message });
      log(`request failed: ${error.stack || error.message}`);
      return json(res, 500, { error: error.message || 'internal error' });
    }
  });

  // The Terminal tab: GET /shell upgraded to a raw socket for one shell (src/shell.js), which
  // Studio's SSH gateway relays to the browser. The token and the sandbox header as for the rest.
  const shells = new Set();
  server.on('upgrade', (req, socket) => {
    try {
      openShell(req, socket);
    } catch (error) {
      // a shell that cannot start must not take the bridge down with it
      log(`shell failed: ${error.stack || error.message}`);
      socket.destroy();
    }
  });
  function openShell(req, socket) {
    const url = new URL(req.url, 'http://bridge');
    const refuse = (status, message) => socket.end(`HTTP/1.1 ${status} ${message}\r\ncontent-type: text/plain\r\nconnection: close\r\n\r\n${message}`);
    if (url.pathname.replace(/\/+$/, '') !== '/shell' || (req.headers.upgrade ?? '').toLowerCase() !== 'studio-shell') return refuse(404, 'Not Found');
    if (!authorized(req, config.token)) return refuse(401, 'invalid bridge token');
    if (shells.size >= config.maxShells) return refuse(429, `at most ${config.maxShells} shells at a time`);

    let cwd;
    try {
      const sandbox = req.headers['x-studio-sandbox'];
      cwd = sandbox ? sandboxes.resolve(String(sandbox)) : config.workspaceDir;
    } catch (error) {
      return refuse(error.status ?? 400, error.message);
    }

    socket.write('HTTP/1.1 101 Switching Protocols\r\nupgrade: studio-shell\r\nconnection: Upgrade\r\n\r\n');
    const shell = new ShellSession(socket, {
      cwd,
      // the workspace itself starts at ~/<name>; a sandbox's worktree keeps its full path
      home: req.headers['x-studio-sandbox'] ? null : config.home,
      cols: Number(url.searchParams.get('cols')) || 80,
      rows: Number(url.searchParams.get('rows')) || 24,
      shell: config.shell,
      onClose: () => shells.delete(shell),
    });
    shells.add(shell);
    log(`shell opened in ${cwd}`);
  }

  server.sessions = sessions;
  server.shutdown = async () => {
    await sessions.closeAll();
    for (const shell of shells) shell.close();
    await new Promise((resolve) => server.close(() => resolve()));
  };

  return server;
}

/** The git routes: what the Changes tab of Studio reads and does in the workspace's repository. */
async function gitRoute(req, res, path, url, config, dir) {
  const query = (name) => url.searchParams.get(name);
  const flag = (name) => ['1', 'true'].includes(query(name) ?? '');
  const body = req.method === 'POST' ? await readJson(req, config.maxBodyBytes) : {};
  let match;

  if (req.method === 'GET') {
    if (path === '/git') return json(res, 200, await gitState(dir));
    if (path === '/git/log') return json(res, 200, await gitLog(dir, { limit: query('limit') ?? 80, all: query('all') !== '0', search: query('search') ?? '' }));
    if (path === '/git/branches') return json(res, 200, await gitBranches(dir));
    if (path === '/git/diff') return json(res, 200, await gitDiffFile(dir, query('path') ?? ''));
    if (path === '/git/compare') return json(res, 200, await gitCompare(dir, query('branch') ?? ''));
    if (path === '/git/tags') return json(res, 200, await gitTags(dir));
    if (path === '/git/stashes') return json(res, 200, await gitStashes(dir));
    if (path === '/git/stash/show') return json(res, 200, await gitStashShow(dir, query('index') ?? 0));
    if ((match = /^\/git\/commit\/([^/]+)$/.exec(path))) return json(res, 200, await gitCommit(dir, decodeURIComponent(match[1])));
  }

  if (req.method === 'POST') {
    switch (path) {
      case '/git/checkout': return json(res, 200, await gitCheckout(dir, body.branch));
      case '/git/fetch': return json(res, 200, await gitFetch(dir));
      case '/git/stage': return json(res, 200, await gitStage(dir, body.paths ?? null));
      case '/git/unstage': return json(res, 200, await gitUnstage(dir, body.paths ?? null));
      case '/git/commit': return json(res, 200, await gitCommitAll(dir, body.message, { all: body.all !== false, amend: body.amend === true }));
      case '/git/commit-message': return json(res, 200, await suggestCommitMessage(dir, body, config));
      case '/git/resolve': return json(res, 200, await gitResolve(dir, body.path, body.side));
      case '/git/discard': return json(res, 200, await gitDiscard(dir, body.path ?? null));
      case '/git/pull': return json(res, 200, await gitPull(dir));
      case '/git/push': return json(res, 200, await gitPush(dir, { force: body.force === true }));
      case '/git/merge': return json(res, 200, await gitMerge(dir, body.branch, { mode: body.mode ?? 'merge' }));
      case '/git/merge/abort': return json(res, 200, await gitMergeAbort(dir));
      case '/git/branches': return json(res, 201, await gitCreateBranch(dir, body.branch, body.from ?? null));
      case '/git/branches/rename': return json(res, 200, await gitRenameBranch(dir, body.from, body.to));
      case '/git/revert': return json(res, 200, await gitRevert(dir, body.sha));
      case '/git/cherry-pick': return json(res, 200, await gitCherryPick(dir, body.sha));
      case '/git/reset': return json(res, 200, await gitReset(dir, body.sha, body.mode ?? 'mixed'));
      case '/git/tags': return json(res, 201, await gitCreateTag(dir, body.tag, { at: body.at ?? null, message: body.message ?? '' }));
      case '/git/tags/push': return json(res, 200, await gitPushTags(dir, body.tag ?? null));
      // the older body {pop: true} restores the newest stash
      case '/git/stash': return json(res, 200, body.pop === true ? await gitStash(dir, true) : await gitStashPush(dir, { message: body.message ?? '', untracked: body.untracked !== false }));
      case '/git/stash/apply': return json(res, 200, await gitStashApply(dir, body.index ?? 0, { pop: body.pop !== false }));
    }
  }

  if (req.method === 'DELETE') {
    if (path === '/git/branches') return json(res, 200, await gitDeleteBranch(dir, query('branch') ?? '', { force: flag('force'), remote: flag('remote') }));
    if (path === '/git/remote-branches') return json(res, 200, await gitDeleteRemoteBranch(dir, query('branch') ?? ''));
    if (path === '/git/tags') return json(res, 200, await gitDeleteTag(dir, query('tag') ?? '', { remote: flag('remote') }));
    if (path === '/git/stash') return json(res, 200, await gitStashDrop(dir, query('index') ?? 0));
  }
}

function authorized(req, token) {
  const header = req.headers.authorization || '';
  const presented = header.startsWith('Bearer ') ? header.slice(7).trim() : '';
  if (presented === '' || typeof token !== 'string') return false;
  const a = Buffer.from(presented, 'utf8');
  const b = Buffer.from(token, 'utf8');
  return a.length === b.length && timingSafeEqual(a, b);
}

function json(res, status, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(status, { 'content-type': 'application/json', 'content-length': Buffer.byteLength(body) });
  res.end(body);
}

function readJson(req, limit) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', (chunk) => {
      size += chunk.length;
      if (size > limit) {
        reject(new BridgeError(413, 'body too large'));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => {
      if (chunks.length === 0) return resolve({});
      try {
        resolve(JSON.parse(Buffer.concat(chunks).toString('utf8')));
      } catch {
        reject(new BridgeError(400, 'invalid JSON body'));
      }
    });
    req.on('error', reject);
  });
}
