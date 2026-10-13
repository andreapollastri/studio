import test from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { existsSync, readdirSync, readFileSync, realpathSync } from 'node:fs';
import { mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { normalize } from '../src/normalize.js';
import { normalizeBrowser } from '../src/browser.js';
import { Sandboxes } from '../src/sandboxes.js';
import { Session } from '../src/session.js';
import { createServer } from '../src/server.js';
import { listen, spawnFake, startCollector, testConfig, waitFor } from './helpers.js';

const run = promisify(execFile);
const env = { ...process.env, GIT_AUTHOR_NAME: 'Maria', GIT_AUTHOR_EMAIL: 'maria@agency.test', GIT_COMMITTER_NAME: 'Maria', GIT_COMMITTER_EMAIL: 'maria@agency.test' };
const git = (dir, ...args) => run('git', args, { cwd: dir, env });
const headers = { authorization: 'Bearer bridge-secret', 'content-type': 'application/json' };
const callback = (studio) => ({ url: studio.url, token: 'cb' });

async function workspace() {
  const parent = await mkdtemp(join(tmpdir(), 'studio-ws-'));
  const dir = join(parent, 'shop');
  await run('mkdir', [dir]);
  await git(dir, 'init', '-q', '-b', 'main');
  await git(dir, 'config', 'user.name', 'Maria');
  await git(dir, 'config', 'user.email', 'maria@agency.test');
  await writeFile(join(dir, 'a.txt'), 'a\n');
  await writeFile(join(dir, '.env'), 'APP_URL=https://maria-shop.studio.test\n');
  await writeFile(join(dir, '.gitignore'), '.env\n');
  await git(dir, 'add', '-A');
  await git(dir, 'commit', '-q', '-m', 'first');
  return dir;
}

/** What the fake CLI was started with: its arguments and directory, newest last. */
async function spawned(argsDir) {
  await waitFor(() => readdirSync(argsDir).length > 0);
  return readdirSync(argsDir).map((f) => JSON.parse(readFileSync(join(argsDir, f), 'utf8'))).at(-1);
}

const flag = (args, name) => {
  const i = args.indexOf(name);
  return i === -1 ? null : args[i + 1];
};

test('screenshots in tool results travel as images; subagent tasks as task events', () => {
  const [result] = normalize({ type: 'user', parent_tool_use_id: 'toolu_agent', message: { content: [{ type: 'tool_result', tool_use_id: 't', content: [
    { type: 'text', text: 'shot' },
    { type: 'image', source: { type: 'base64', media_type: 'image/png', data: 'iVBOR' } },
    { type: 'image', source: { type: 'base64', media_type: 'text/html', data: 'PGh0bWw+' } },
  ] }] } });
  assert.equal(result.content, 'shot\n[image]\n[image]');
  assert.deepEqual(result.images, [{ media_type: 'image/png', data: 'iVBOR' }]);
  assert.equal(result.parent_tool_use_id, 'toolu_agent');

  const plain = normalize({ type: 'user', message: { content: [{ type: 'tool_result', tool_use_id: 't', content: 'ok' }] } })[0];
  assert.equal(plain.images, undefined);

  const big = normalize({ type: 'user', message: { content: [{ type: 'tool_result', tool_use_id: 't', content: [{ type: 'image', source: { type: 'base64', media_type: 'image/png', data: 'x'.repeat(5 * 1024 * 1024) } }] }] } })[0];
  assert.equal(big.images[0].data, null);
  assert.ok(big.images[0].omitted_bytes > 3_000_000);

  const [started] = normalize({ type: 'system', subtype: 'task_started', task_id: 'k', tool_use_id: 'toolu_agent', description: 'Check', task_type: 'local_agent' });
  assert.deepEqual([started.type, started.phase, started.tool_use_id, started.status], ['task', 'started', 'toolu_agent', 'running']);
  const [finished, status] = normalize({ type: 'system', subtype: 'task_notification', task_id: 'k', tool_use_id: 'toolu_agent', status: 'completed', summary: 'Done' });
  assert.deepEqual([finished.phase, finished.status, finished.summary], ['finished', 'completed', 'Done']);
  assert.equal(status.status, 'task_notification');
});

test('the browser is only for an http(s) preview, and its pass only for that host', () => {
  assert.equal(normalizeBrowser(null), null);
  assert.throws(() => normalizeBrowser({ url: 'file:///etc/passwd' }), /http/);
  assert.throws(() => normalizeBrowser({ url: 'https://a.test', cookies: [{ name: 'bad name', value: 'x' }] }), /cookies/);
  const browser = normalizeBrowser({ url: 'https://maria-shop.studio.test/', cookies: [{ name: 'studio_site_pass', value: 'sealed', domain: 'evil.test' }] });
  assert.equal(browser.url, 'https://maria-shop.studio.test');
  assert.deepEqual(browser.cookies[0], { name: 'studio_site_pass', value: 'sealed', domain: 'maria-shop.studio.test', path: '/', expires: -1, httpOnly: true, secure: true, sameSite: 'Lax' });
});

test('a turn with a preview gets the browser server, its signed-in state, the subagents and the context', async () => {
  const studio = await startCollector();
  const argsDir = await mkdtemp(join(tmpdir(), 'studio-args-'));
  process.env.FAKE_CLAUDE_ARGS_DIR = argsDir;
  const session = new Session('71', testConfig({ browserCmd: 'playwright-mcp' }), { spawn: spawnFake });

  session.sendTurn({
    text: 'ciao', permission_mode: 'default', callback: callback(studio),
    browser: { url: 'https://maria-shop.studio.test', cookies: [{ name: 'studio_site_pass', value: 'pass-1' }] },
  });
  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  const { args } = await spawned(argsDir);

  const mcp = JSON.parse(readFileSync(flag(args, '--mcp-config'), 'utf8')).mcpServers;
  assert.equal(mcp.browser.type, 'stdio');
  assert.equal(mcp.browser.command, 'playwright-mcp');
  assert.ok(mcp.browser.args.includes('--headless') && mcp.browser.args.includes('--isolated'));
  const state = JSON.parse(readFileSync(flag(mcp.browser.args, '--storage-state'), 'utf8'));
  assert.equal(state.cookies[0].value, 'pass-1');
  assert.equal(state.cookies[0].domain, 'maria-shop.studio.test');
  assert.equal(JSON.parse(readFileSync(flag(mcp.browser.args, '--config'), 'utf8')).browser.browserName, 'chromium');

  // asking before actions: looking at the preview is free, clicking in it asks
  const allowed = flag(args, '--allowedTools');
  assert.match(allowed, /mcp__browser__browser_take_screenshot/);
  assert.doesNotMatch(allowed, /browser_click/);

  const agents = JSON.parse(readFileSync(flag(args, '--agents'), 'utf8'));
  assert.deepEqual(Object.keys(agents).sort(), ['browser-qa', 'code-reviewer']);
  assert.match(flag(args, '--append-system-prompt'), /maria-shop\.studio\.test/);

  // the next turn brings a fresh pass, written where the running browser server reads it, without a restart
  session.sendTurn({ text: 'again', permission_mode: 'default', callback: callback(studio), browser: { url: 'https://maria-shop.studio.test', cookies: [{ name: 'studio_site_pass', value: 'pass-2' }] } });
  await waitFor(() => studio.events().filter((e) => e.type === 'result').length === 2);
  assert.equal(readdirSync(argsDir).length, 1);
  assert.equal(JSON.parse(readFileSync(flag(mcp.browser.args, '--storage-state'), 'utf8')).cookies[0].value, 'pass-2');

  await session.close();
  assert.equal(existsSync(flag(mcp.browser.args, '--storage-state')), false);
  delete process.env.FAKE_CLAUDE_ARGS_DIR;
  await studio.close();
});

test('with edits allowed the whole browser is free; without a browser on the server the turn runs without one', async () => {
  const studio = await startCollector();
  const argsDir = await mkdtemp(join(tmpdir(), 'studio-args-'));
  process.env.FAKE_CLAUDE_ARGS_DIR = argsDir;
  const browser = { url: 'https://maria-shop.studio.test', cookies: [] };

  const free = new Session('72', testConfig({ browserCmd: 'playwright-mcp' }), { spawn: spawnFake });
  free.sendTurn({ text: 'ciao', permission_mode: 'acceptEdits', callback: callback(studio), browser });
  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  assert.equal(flag((await spawned(argsDir)).args, '--allowedTools'), 'mcp__browser');
  await free.close();

  const none = new Session('73', testConfig({ browserCmd: null }), { spawn: spawnFake });
  none.sendTurn({ text: 'ciao', permission_mode: 'default', callback: callback(studio), browser });
  await waitFor(() => studio.events().filter((e) => e.type === 'result').length === 2);
  await waitFor(() => readdirSync(argsDir).length === 2);
  const files = readdirSync(argsDir).map((f) => JSON.parse(readFileSync(join(argsDir, f), 'utf8')));
  const args = files.find((f) => !f.args.includes('--allowedTools')).args;
  assert.equal(args.includes('--mcp-config'), false);
  assert.deepEqual(Object.keys(JSON.parse(readFileSync(flag(args, '--agents'), 'utf8'))), ['code-reviewer']);
  await none.close();

  delete process.env.FAKE_CLAUDE_ARGS_DIR;
  await studio.close();
});

test('the screenshot of a subagent reaches Studio as an image under its task', async () => {
  const studio = await startCollector();
  const session = new Session('74', testConfig(), { spawn: spawnFake });
  session.sendTurn({ text: 'screenshot', callback: callback(studio) });
  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  await session.close();

  const events = studio.events();
  const shot = events.find((e) => e.type === 'tool_result' && e.tool_use_id === 'toolu_shot');
  assert.equal(shot.parent_tool_use_id, 'toolu_agent');
  assert.equal(shot.images[0].media_type, 'image/png');
  assert.ok(events.some((e) => e.type === 'task' && e.phase === 'started' && e.tool_use_id === 'toolu_agent'));
  assert.ok(events.some((e) => e.type === 'task' && e.phase === 'finished' && e.status === 'completed'));
  await studio.close();
});

test('a sandbox is a worktree on its own branch: create, change, commit, merge from the workspace, remove', async () => {
  const dir = await workspace();
  const sandboxes = new Sandboxes(dir);
  assert.equal(sandboxes.root, join(dir, '..', '.sandboxes', 'shop'));
  assert.throws(() => sandboxes.dir('../etc'), /invalid sandbox name/);
  await assert.rejects(sandboxes.create('c1', { branch: 'bad..name' }), /invalid branch name/);

  const created = await sandboxes.create('c1', { branch: 'studio/c1-checkout' });
  assert.equal(created.branch, 'studio/c1-checkout');
  assert.equal(created.workspace_branch, 'main');
  assert.equal(created.files, 0);
  assert.equal(sandboxes.branchOf('c1'), 'studio/c1-checkout');
  // the workspace's settings come along, so artisan runs there too
  assert.match(readFileSync(join(created.path, '.env'), 'utf8'), /APP_URL/);
  // asking again answers the same sandbox
  assert.equal((await sandboxes.create('c1', { branch: 'studio/c1-checkout' })).path, created.path);

  await writeFile(join(created.path, 'checkout.txt'), 'new flow\n');
  assert.equal((await sandboxes.state('c1')).files, 1);
  const committed = await sandboxes.commit('c1', 'Checkout flow');
  assert.equal(committed.files, 0);
  assert.equal(committed.ahead, 1);
  // nothing new: no empty commit, no error
  assert.equal((await sandboxes.commit('c1', 'Again')).ahead, 1);

  // the workspace merges the branch: same repository
  await git(dir, 'merge', '-q', '--no-edit', 'studio/c1-checkout');
  assert.equal(readFileSync(join(dir, 'checkout.txt'), 'utf8'), 'new flow\n');

  const removed = await sandboxes.remove('c1', { deleteBranch: true });
  assert.equal(removed.branch, 'studio/c1-checkout');
  assert.equal(existsSync(created.path), false);
  const { stdout } = await git(dir, 'branch', '--list', 'studio/*');
  assert.equal(stdout.trim(), '');
  await assert.rejects(sandboxes.state('c1'), /does not exist/);
});

test('a sandboxed conversation runs Claude Code in its worktree and says so in its context', async () => {
  const dir = await workspace();
  const sandboxes = new Sandboxes(dir);
  const { path } = await sandboxes.create('c9', { branch: 'studio/c9' });
  const studio = await startCollector();
  const argsDir = await mkdtemp(join(tmpdir(), 'studio-args-'));
  process.env.FAKE_CLAUDE_ARGS_DIR = argsDir;

  const session = new Session('9', testConfig({ workspaceDir: dir }), { spawn: spawnFake, sandboxes });
  assert.throws(() => session.sendTurn({ text: 'x', callback: callback(studio), sandbox: 'c404' }), /does not exist/);
  session.sendTurn({ text: 'ciao', callback: callback(studio), sandbox: 'c9' });
  await waitFor(() => studio.events().some((e) => e.type === 'result'));

  const init = studio.events().find((e) => e.type === 'init');
  assert.equal(realpathSync(init.cwd), realpathSync(path));
  assert.equal(session.cwd, path);
  const { args } = await spawned(argsDir);
  assert.match(flag(args, '--append-system-prompt'), /sandbox[\s\S]*studio\/c9/);

  await session.close();
  delete process.env.FAKE_CLAUDE_ARGS_DIR;
  await studio.close();
});

test('the sandbox routes, and the git and file routes pointed at a sandbox', async () => {
  const dir = await workspace();
  const server = createServer(testConfig({ workspaceDir: dir }), { spawn: spawnFake });
  const base = await listen(server);

  const created = await fetch(`${base}/sandboxes`, { method: 'POST', headers, body: JSON.stringify({ name: 'c5', branch: 'studio/c5' }) });
  assert.equal(created.status, 201);
  const sandbox = await created.json();

  await writeFile(join(sandbox.path, 'b.txt'), 'b\n');
  const sandboxed = { ...headers, 'x-studio-sandbox': 'c5' };
  assert.equal((await (await fetch(`${base}/git`, { headers: sandboxed })).json()).branch, 'studio/c5');
  assert.equal((await (await fetch(`${base}/git`, { headers })).json()).branch, 'main');
  const listing = await (await fetch(`${base}/files`, { headers: sandboxed })).json();
  assert.ok(listing.entries.some((e) => e.name === 'b.txt'));
  assert.equal((await fetch(`${base}/git`, { headers: { ...headers, 'x-studio-sandbox': 'c6' } })).status, 404);
  assert.equal((await fetch(`${base}/git`, { headers: { ...headers, 'x-studio-sandbox': '../..' } })).status, 400);

  const committed = await (await fetch(`${base}/sandboxes/c5/commit`, { method: 'POST', headers, body: JSON.stringify({ message: 'Add b' }) })).json();
  assert.equal(committed.ahead, 1);
  assert.equal((await (await fetch(`${base}/sandboxes/c5`, { headers })).json()).files, 0);

  // merging is the workspace's own git route
  const merged = await fetch(`${base}/git/merge`, { method: 'POST', headers, body: JSON.stringify({ branch: 'studio/c5' }) });
  assert.equal(merged.status, 200);
  assert.ok(existsSync(join(dir, 'b.txt')));

  const removed = await (await fetch(`${base}/sandboxes/c5?branch=1`, { method: 'DELETE', headers })).json();
  assert.equal(removed.branch_deleted, true);
  assert.equal((await fetch(`${base}/sandboxes/c5`, { headers })).status, 404);

  await server.shutdown();
});
