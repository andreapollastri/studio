import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { lstat, mkdir, mkdtemp, readlink, realpath, rm, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from '../src/server.js';
import { homeLink, shellEnv, terminalScript } from '../src/shell.js';
import { testConfig, listen } from './helpers.js';

/** Open GET /shell as the gateway does; resolves to { socket, messages, until } or the refusal's status. */
function openShell(base, { token = 'bridge-secret', sandbox = null, cols = 80, rows = 24 } = {}) {
  return new Promise((resolve, reject) => {
    const req = http.request(`${base}/shell?cols=${cols}&rows=${rows}`, {
      headers: { connection: 'Upgrade', upgrade: 'studio-shell', authorization: `Bearer ${token}`, ...(sandbox ? { 'x-studio-sandbox': sandbox } : {}) },
    });
    req.on('response', (res) => resolve({ status: res.statusCode }));
    req.on('upgrade', (res, socket) => {
      const messages = [];
      const waiters = [];
      let buffer = '';
      socket.on('data', (chunk) => {
        buffer += chunk.toString('utf8');
        let index;
        while ((index = buffer.indexOf('\n')) !== -1) {
          messages.push(JSON.parse(buffer.slice(0, index)));
          buffer = buffer.slice(index + 1);
        }
        for (const waiter of [...waiters]) waiter();
      });
      const screen = () => messages.filter((m) => m.type === 'data').map((m) => m.data).join('');
      const until = (check, ms = 8000) => new Promise((done, fail) => {
        const timer = setTimeout(() => fail(new Error(`timed out; screen so far: ${JSON.stringify(screen())}`)), ms);
        const waiter = () => {
          const found = check({ screen: screen(), messages });
          if (found) {
            clearTimeout(timer);
            waiters.splice(waiters.indexOf(waiter), 1);
            done(found);
          }
        };
        waiters.push(waiter);
        waiter();
      });
      const send = (message) => socket.write(`${JSON.stringify(message)}\n`);
      resolve({ status: 101, socket, send, until, screen });
    });
    req.on('error', reject);
    req.end();
  });
}

test('the shell is a real terminal: prompts read what is typed, the size follows, the exit code comes back', async (t) => {
  const root = await realpath(await mkdtemp(join(tmpdir(), 'studio-shell-')));
  const server = createServer(testConfig({ workspaceDir: root, shell: '/bin/sh' }));
  const base = await listen(server);
  t.after(() => server.shutdown());

  const shell = await openShell(base, { cols: 90, rows: 20 });
  assert.equal(shell.status, 101);

  // a prompt that waits for an answer, as `php artisan` asking for a password does
  shell.send({ type: 'data', data: 'printf "Password: "; read -r answer; echo "got:$answer"\r' });
  await shell.until(({ screen }) => screen.includes('Password: '));
  shell.send({ type: 'data', data: 'secret\r' });
  await shell.until(({ screen }) => screen.includes('got:secret'));

  // on a terminal, in the workspace, without the bridge's token in its environment
  shell.send({ type: 'data', data: 'test -t 0 && echo "tty:yes"; pwd; echo "token:${BRIDGE_TOKEN:-none}"\r' });
  await shell.until(({ screen }) => screen.includes('tty:yes') && screen.includes(root) && screen.includes('token:none'));

  shell.send({ type: 'data', data: 'stty size\r' });
  await shell.until(({ screen }) => screen.includes('20 90'));
  shell.send({ type: 'resize', cols: 120, rows: 40 });
  shell.send({ type: 'data', data: 'stty size\r' });
  await shell.until(({ screen }) => screen.includes('40 120'));

  shell.send({ type: 'data', data: 'exit 3\r' });
  const exit = await shell.until(({ messages }) => messages.find((m) => m.type === 'exit'));
  assert.equal(exit.code, 3);
});

test('closing the socket hangs the shell up; the token, the protocol and the sandbox are checked', async (t) => {
  const root = await mkdtemp(join(tmpdir(), 'studio-shell-'));
  const server = createServer(testConfig({ workspaceDir: root, shell: '/bin/sh', maxShells: 1 }));
  const base = await listen(server);
  t.after(() => server.shutdown());

  assert.equal((await openShell(base, { token: 'wrong' })).status, 401);
  assert.equal((await openShell(base, { sandbox: '../etc' })).status, 400);
  assert.equal((await openShell(base, { sandbox: 'c404' })).status, 404);

  const first = await openShell(base);
  assert.equal(first.status, 101);
  assert.equal((await openShell(base)).status, 429);
  first.socket.end();
  // the slot frees once the shell is gone
  await new Promise((resolve) => setTimeout(resolve, 300));
  const again = await openShell(base);
  assert.equal(again.status, 101);
  again.socket.end();
});

test("the shell's environment leaves the bridge's secrets out and is a colour terminal", () => {
  const env = shellEnv({ PATH: '/usr/bin', BRIDGE_TOKEN: 't', BRIDGE_PORT: '4455', STUDIO_ANTHROPIC_API_KEY: 'k', CI: '1', GIT_ASKPASS: '/usr/local/bin/askpass', HOME: '/home/ws-ada' });
  assert.deepEqual(env, { PATH: '/usr/bin', GIT_ASKPASS: '/usr/local/bin/askpass', HOME: '/home/ws-ada', TERM: 'xterm-256color', COLORTERM: 'truecolor', LANG: 'C.UTF-8' });
});

test('the workspace shell starts at ~/<workspace>, a link made in the home, so the prompt is short', async (t) => {
  const root = await realpath(await mkdtemp(join(tmpdir(), 'studio-ws-')));
  const home = await realpath(await mkdtemp(join(tmpdir(), 'studio-home-')));
  const server = createServer(testConfig({ workspaceDir: root, shell: '/bin/sh', home }));
  const base = await listen(server);
  t.after(() => server.shutdown());

  const shell = await openShell(base);
  shell.send({ type: 'data', data: 'echo "at:$(pwd)"\r' });
  const name = root.split('/').pop();
  await shell.until(({ screen }) => screen.includes(`at:${home}/${name}`));
  assert.equal(await readlink(join(home, name)), root);
  shell.send({ type: 'data', data: 'exit\r' });
  await shell.until(({ messages }) => messages.find((m) => m.type === 'exit'));
});

test('a link already there is used again; anything else of that name keeps the full path', async () => {
  const dir = await realpath(await mkdtemp(join(tmpdir(), 'studio-ws-')));
  const home = await realpath(await mkdtemp(join(tmpdir(), 'studio-home-')));
  const name = dir.split('/').pop();

  assert.equal(homeLink(dir, home), join(home, name));
  assert.ok((await lstat(join(home, name))).isSymbolicLink());
  assert.equal(homeLink(dir, home), join(home, name));

  const other = await realpath(await mkdtemp(join(tmpdir(), 'studio-home-')));
  await writeFile(join(other, name), 'mine');
  assert.equal(homeLink(dir, other), dir);
});

test('terminal.py comes from the live release, not from the one a long-running bridge started in', async () => {
  const studio = await mkdtemp(join(tmpdir(), 'studio-releases-'));
  for (const release of ['v2.4.3', 'v2.4.8']) {
    await mkdir(join(studio, 'releases', release, 'bridge', 'bin'), { recursive: true });
    await mkdir(join(studio, 'releases', release, 'bridge', 'src'), { recursive: true });
    await writeFile(join(studio, 'releases', release, 'bridge', 'src', 'terminal.py'), '');
  }
  // started as the unit starts it, through current; the release it started in is removed later
  await symlink('releases/v2.4.8', join(studio, 'current'));
  await rm(join(studio, 'releases', 'v2.4.3'), { recursive: true });
  const started = join(studio, 'current', 'bridge', 'bin', 'bridge.js');

  assert.equal(terminalScript(started), join(studio, 'current', 'bridge', 'src', 'terminal.py'));
  // run some other way (the tests, development): the file next to shell.js
  assert.match(terminalScript(join(studio, 'nowhere', 'bin', 'bridge.js')), /bridge\/src\/terminal\.py$/);
  assert.match(terminalScript(undefined), /bridge\/src\/terminal\.py$/);
});
