import test from 'node:test';
import assert from 'node:assert/strict';
import ssh2 from 'ssh2';
import WebSocket from 'ws';
import { spawn as spawnReal } from 'node:child_process';
import { createGateway, CLOSE } from '../src/server.js';
import { fingerprint } from '../src/session.js';
import { gatewayToken, loadConfig } from '../src/config.js';

const { Server, utils } = ssh2;

const hostKey = utils.generateKeyPairSync('ed25519');
const clientKey = utils.generateKeyPairSync('ed25519');
const hostFingerprint = fingerprint(utils.parseKey(hostKey.public).getPublicSSH());

/** A real SSH server on a free port: the key above or password "secret"; the shell echoes. */
async function sshServer() {
  const allowed = utils.parseKey(clientKey.public);
  const server = new Server({ hostKeys: [hostKey.private] }, (client) => {
    client.on('error', () => {}); // a refused host key drops the connection from the client side
    client.on('authentication', (ctx) => {
      if (ctx.method === 'password' && ctx.username === 'deploy' && ctx.password === 'secret') return ctx.accept();
      if (ctx.method === 'publickey' && ctx.key.algo === allowed.type && ctx.key.data.equals(allowed.getPublicSSH())) {
        if (!ctx.signature) return ctx.accept();
        return allowed.verify(ctx.blob, ctx.signature, ctx.hashAlgo) ? ctx.accept() : ctx.reject();
      }
      ctx.reject(['publickey', 'password']);
    });
    client.on('ready', () => {
      client.on('session', (accept) => {
        const session = accept();
        session.on('pty', (ok, reject, info) => { server.lastPty = info; ok?.(); });
        session.on('window-change', (ok, reject, info) => { server.lastWindow = info; ok?.(); });
        session.on('shell', (ok) => {
          const stream = ok();
          stream.write('welcome è\r\n');
          stream.on('data', (data) => stream.write(`echo:${data}`));
        });
      });
    });
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  return server;
}

async function gateway(target, overrides = {}) {
  const config = { ...loadConfig({ SSH_GATEWAY_TOKEN: 't', SSH_GATEWAY_LOG: '0' }), origin: '', ...overrides };
  const redeem = async (ticket) => (ticket === 'good' ? target() : null);
  const server = createGateway(config, { redeem });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  return server;
}

function open(server, ticket, headers = {}) {
  const ws = new WebSocket(`ws://127.0.0.1:${server.address().port}/ssh/ws?ticket=${ticket}&cols=100&rows=30`, { headers });
  const messages = [];
  const waiters = [];
  ws.on('message', (raw) => {
    const message = JSON.parse(raw.toString());
    messages.push(message);
    for (const w of [...waiters]) if (w.test(message, messages)) { waiters.splice(waiters.indexOf(w), 1); w.resolve(message); }
  });
  const closed = new Promise((resolve) => ws.on('close', (code) => resolve(code)));
  const until = (test) => {
    const found = messages.find((m) => test(m, messages));
    return found ? Promise.resolve(found) : new Promise((resolve) => waiters.push({ test, resolve }));
  };
  const output = () => messages.filter((m) => m.type === 'data').map((m) => m.data).join('');
  return { ws, messages, until, closed, output, send: (m) => ws.send(JSON.stringify(m)) };
}

test('the token is derived from APP_KEY the same way as on the PHP side', () => {
  // php -r "echo hash_hmac('sha256', 'studio-ssh-gateway', 'base64:abc');"
  assert.equal(gatewayToken('base64:abc'), '3cbfdfe372730db4a31a4aa64b773622c18352b46de851cf894f0f5238604d32');
  assert.equal(loadConfig({ APP_KEY: 'base64:abc', APP_URL: 'https://studio.example.com/' }).origin, 'https://studio.example.com');
  assert.throws(() => loadConfig({}));
});

test('a new host waits for its key to be accepted, then the shell runs both ways', async (t) => {
  const ssh = await sshServer();
  const gw = await gateway(() => ({ user_id: 1, host: '127.0.0.1', port: ssh.address().port, username: 'deploy', auth: 'key', private_key: clientKey.private }));
  t.after(() => { gw.close(); ssh.close(); });

  const s = open(gw, 'good');
  const prompt = await s.until((m) => m.type === 'hostkey');
  assert.equal(prompt.fingerprint, hostFingerprint);
  s.send({ type: 'hostkey', accept: true });

  await s.until((m) => m.type === 'status' && m.state === 'ready');
  await s.until(() => s.output().includes('welcome è'));
  assert.deepEqual([ssh.lastPty.cols, ssh.lastPty.rows, ssh.lastPty.term], [100, 30, 'xterm-256color']);

  s.send({ type: 'data', data: 'ls\r' });
  await s.until(() => s.output().includes('echo:ls'));

  s.send({ type: 'resize', cols: 120, rows: 40 });
  await new Promise((r) => setTimeout(r, 100));
  assert.deepEqual([ssh.lastWindow.cols, ssh.lastWindow.rows], [120, 40]);

  s.ws.close();
  await s.closed;
});

test('a known host key connects straight away, a changed one is refused', async (t) => {
  const ssh = await sshServer();
  let hostKeyOnFile = hostFingerprint;
  const gw = await gateway(() => ({ user_id: 1, host: '127.0.0.1', port: ssh.address().port, username: 'deploy', auth: 'password', password: 'secret', host_key: hostKeyOnFile }));
  t.after(() => { gw.close(); ssh.close(); });

  const ok = open(gw, 'good');
  await ok.until((m) => m.type === 'status' && m.state === 'ready');
  assert.ok(!ok.messages.some((m) => m.type === 'hostkey'));
  ok.ws.close();

  hostKeyOnFile = 'SHA256:somethingelse';
  const changed = open(gw, 'good');
  const error = await changed.until((m) => m.type === 'error');
  assert.match(error.message, /host key .* changed/);
  await changed.closed;
  assert.equal(changed.messages.filter((m) => m.type === 'error').length, 1);
});

test('a wrong password and a bad ticket end with a reason', async (t) => {
  const ssh = await sshServer();
  const gw = await gateway(() => ({ user_id: 1, host: '127.0.0.1', port: ssh.address().port, username: 'deploy', auth: 'password', password: 'nope', host_key: hostFingerprint }));
  t.after(() => { gw.close(); ssh.close(); });

  const wrong = open(gw, 'good');
  assert.match((await wrong.until((m) => m.type === 'error')).message, /Authentication failed/);
  await wrong.closed;

  const bad = open(gw, 'spent');
  assert.match((await bad.until((m) => m.type === 'error')).message, /ticket/);
  assert.equal(await bad.closed, CLOSE.badTicket);
});

test('another origin and too many sessions are refused', async (t) => {
  const ssh = await sshServer();
  const gw = await gateway(() => ({ user_id: 7, host: '127.0.0.1', port: ssh.address().port, username: 'deploy', auth: 'password', password: 'secret', host_key: hostFingerprint }), { origin: 'https://studio.example.com', maxPerUser: 1 });
  t.after(() => { gw.close(); ssh.close(); });

  const evil = open(gw, 'good', { origin: 'https://evil.example.com' });
  assert.equal(await evil.closed, CLOSE.forbiddenOrigin);

  const first = open(gw, 'good', { origin: 'https://studio.example.com' });
  await first.until((m) => m.type === 'status' && m.state === 'ready');
  const second = open(gw, 'good', { origin: 'https://studio.example.com' });
  assert.equal(await second.closed, CLOSE.tooMany);
  first.ws.close();
  await first.closed;
});

test('a claude-login ticket runs the helper\'s sign-in for that person and relays the terminal both ways', async (t) => {
  let launched;
  const spawn = (bin, args) => {
    launched = [bin, ...args];
    // a stand-in for `claude auth login`: shows a prompt, echoes the pasted code, exits 0
    return spawnReal(process.execPath, ['-e', `
      process.stdout.write('Paste code here if prompted > ');
      process.stdin.once('data', (d) => { process.stdout.write('\\nLogin successful: ' + d.toString().trim() + '\\n'); process.exit(0); });
    `]);
  };
  const config = { ...loadConfig({ SSH_GATEWAY_TOKEN: 't', SSH_GATEWAY_LOG: '0', SSH_GATEWAY_HELPER: '/usr/local/sbin/studio-admin' }), origin: '' };
  const gw = createGateway(config, { redeem: async (ticket) => (ticket === 'good' ? { kind: 'claude-login', user_id: 3, handle: 'ada' } : null), spawn });
  await new Promise((resolve) => gw.listen(0, '127.0.0.1', resolve));
  t.after(() => gw.close());

  const s = open(gw, 'good');
  await s.until(() => s.output().includes('Paste code here'));
  assert.deepEqual(launched, ['sudo', '-n', '/usr/local/sbin/studio-admin', 'claude-login', 'ada', '100', '30']);

  s.send({ type: 'data', data: 'abc123\r' });
  const exit = await s.until((m) => m.type === 'exit');
  assert.equal(exit.code, 0);
  assert.match(s.output(), /Login successful: abc123/);
  await s.closed;
});

test('a claude-login ticket without a valid home name is refused before anything runs', async (t) => {
  let ran = false;
  const config = { ...loadConfig({ SSH_GATEWAY_TOKEN: 't', SSH_GATEWAY_LOG: '0' }), origin: '' };
  const gw = createGateway(config, { redeem: async () => ({ kind: 'claude-login', user_id: 3, handle: '../root' }), spawn: () => { ran = true; } });
  await new Promise((resolve) => gw.listen(0, '127.0.0.1', resolve));
  t.after(() => gw.close());

  const s = open(gw, 'good');
  assert.match((await s.until((m) => m.type === 'error')).message, /no home/);
  await s.closed;
  assert.equal(ran, false);
});
