import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from '../src/server.js';
import { listen, spawnFake, startCollector, testConfig, waitFor } from './helpers.js';

const headers = { authorization: 'Bearer bridge-secret', 'content-type': 'application/json' };

test('health is open, everything else wants the bearer token', async () => {
  const server = createServer(testConfig(), { spawn: spawnFake });
  const base = await listen(server);

  const health = await fetch(`${base}/health`);
  assert.equal(health.status, 200);
  const pulse = await health.json();
  assert.equal(pulse.ok, true);
  assert.match(pulse.version, /^\d+\.\d+\.\d+/);
  // the open route says nothing about the workspace: that is /info, behind the token
  assert.equal(pulse.workspace, undefined);
  assert.equal((await fetch(`${base}/info`)).status, 401);
  const info = await (await fetch(`${base}/info`, { headers })).json();
  assert.equal(info.version, pulse.version);
  assert.equal(typeof info.workspace, 'string');

  assert.equal((await fetch(`${base}/skills`)).status, 401);
  // a token of the wrong length must be refused just like a wrong one of the right length
  assert.equal((await fetch(`${base}/skills`, { headers: { authorization: 'Bearer bridge-secret-but-longer' } })).status, 401);
  assert.equal((await fetch(`${base}/skills`, { headers: { authorization: 'Bearer wrong' } })).status, 401);
  assert.equal((await fetch(`${base}/skills`, { headers })).status, 200);
  assert.equal((await fetch(`${base}/nothing`, { headers })).status, 404);

  await server.shutdown();
});

test('a turn is accepted and its events reach the callback; permissions and interrupt have routes', async () => {
  const studio = await startCollector();
  const server = createServer(testConfig(), { spawn: spawnFake });
  const base = await listen(server);

  const accepted = await fetch(`${base}/conversations/42/turns`, {
    method: 'POST', headers,
    body: JSON.stringify({ text: 'tool please', permission_mode: 'default', callback: { url: studio.url, token: 'cb' } }),
  });
  assert.equal(accepted.status, 202);
  assert.equal((await accepted.json()).state, 'running');

  // one turn at a time: a second message while this one runs is refused, not queued behind it
  const busy = await fetch(`${base}/conversations/42/turns`, {
    method: 'POST', headers,
    body: JSON.stringify({ text: 'and this too', permission_mode: 'default', callback: { url: studio.url, token: 'cb' } }),
  });
  assert.equal(busy.status, 409);

  await waitFor(() => studio.events().some((e) => e.type === 'permission_request'));
  assert.equal(studio.posts[0].body.conversation, '42');

  const missing = await fetch(`${base}/conversations/42/permissions/req_nope`, { method: 'POST', headers, body: JSON.stringify({ behavior: 'allow' }) });
  assert.equal(missing.status, 404);

  const answered = await fetch(`${base}/conversations/42/permissions/req_1`, { method: 'POST', headers, body: JSON.stringify({ behavior: 'allow' }) });
  assert.equal(answered.status, 200);
  await waitFor(() => studio.events().some((e) => e.type === 'result'));

  const state = await (await fetch(`${base}/conversations/42`, { headers })).json();
  assert.equal(state.state, 'idle');

  const interrupted = await fetch(`${base}/conversations/42/interrupt`, { method: 'POST', headers });
  assert.equal(interrupted.status, 200);

  const bad = await fetch(`${base}/conversations/43/turns`, { method: 'POST', headers, body: '{not json' });
  assert.equal(bad.status, 400);
  const incomplete = await fetch(`${base}/conversations/43/turns`, { method: 'POST', headers, body: JSON.stringify({ text: 'x' }) });
  assert.equal(incomplete.status, 422);

  await server.shutdown();
  await studio.close();
});

test('the terminal runs allowed commands and refuses the rest', async () => {
  const server = createServer(testConfig({ workspaceDir: process.cwd() }), { spawn: spawnFake });
  const base = await listen(server);

  const refused = await fetch(`${base}/run`, { method: 'POST', headers, body: JSON.stringify({ command: 'rm -rf /' }) });
  assert.equal(refused.status, 422);

  const started = await fetch(`${base}/run`, { method: 'POST', headers, body: JSON.stringify({ command: 'git --version' }) });
  assert.equal(started.status, 202);
  const { id } = await started.json();
  const done = await waitFor(async () => { const r = await (await fetch(`${base}/run/${id}`, { headers })).json(); return r.status !== 'running' ? r : null; });
  assert.equal(done.status, 'done');
  assert.match(done.output, /git version/);

  const list = await (await fetch(`${base}/run`, { headers })).json();
  assert.equal(list.runs[0].id, id);
  await server.shutdown();
});

test('git state is readable from the workspace', async () => {
  const server = createServer(testConfig({ workspaceDir: process.cwd() }), { spawn: spawnFake });
  const base = await listen(server);
  const response = await fetch(`${base}/git`, { headers });
  const body = await response.json();
  assert.equal(response.status, 200, JSON.stringify(body));
  assert.ok(typeof body.branch === 'string');
  assert.ok(Array.isArray(body.status));
  await server.shutdown();
});

test('the answers to Claude\'s questions travel through the permissions route', async () => {
  const studio = await startCollector();
  const server = createServer(testConfig(), { spawn: spawnFake });
  const base = await listen(server);

  await fetch(`${base}/conversations/44/turns`, { method: 'POST', headers, body: JSON.stringify({ text: 'ask me', callback: { url: studio.url, token: 'cb' } }) });
  await waitFor(() => studio.events().some((e) => e.type === 'permission_request'));

  const answered = await fetch(`${base}/conversations/44/permissions/req_q`, {
    method: 'POST', headers, body: JSON.stringify({ behavior: 'allow', answers: { 'How deep should Larapilot work?': 'STANDARD' } }),
  });
  assert.equal(answered.status, 200);
  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  assert.equal(studio.events().find((e) => e.type === 'result').result, 'User has answered your questions: "How deep should Larapilot work?"="STANDARD".');

  await server.shutdown();
  await studio.close();
});
