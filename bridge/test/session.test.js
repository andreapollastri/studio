import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, statSync } from 'node:fs';
import { Session, normalizeMcpServers } from '../src/session.js';
import { startCollector, spawnFake, testConfig, waitFor } from './helpers.js';

const callback = (studio) => ({ url: studio.url, token: 'cb' });

test('a plain turn streams deltas, a text block and a result, then the session idles with its id', async () => {
  const studio = await startCollector();
  const session = new Session('11', testConfig(), { spawn: spawnFake });

  const snapshot = session.sendTurn({ text: 'ciao', permission_mode: 'default', callback: callback(studio) });
  assert.equal(snapshot.state, 'running');

  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  await session.close();

  const types = studio.events().map((e) => e.type);
  assert.equal(types[0], 'init');
  assert.ok(types.includes('text_delta'));
  assert.ok(types.includes('text'));
  assert.equal(types.at(-1), 'result');
  assert.equal(studio.events().find((e) => e.type === 'text').text, 'Ho letto: ciao');
  assert.equal(session.state, 'idle');
  assert.match(session.sessionId, /^sess-/);
  await studio.close();
});

test('a permission question is forwarded and the answer lets the turn finish', async () => {
  const studio = await startCollector();
  const session = new Session('12', testConfig(), { spawn: spawnFake });

  session.sendTurn({ text: 'tool please', permission_mode: 'default', callback: callback(studio) });

  const ask = await waitFor(() => studio.events().find((e) => e.type === 'permission_request'));
  assert.equal(ask.request_id, 'req_1');
  assert.equal(ask.tool_name, 'Bash');
  assert.equal(ask.input.command, 'npm run build');
  assert.equal(ask.description, 'Build the assets');
  assert.equal(session.state, 'waiting_permission');

  session.answerPermission('req_1', 'allow');
  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  await session.close();

  const types = studio.events().map((e) => e.type);
  assert.ok(types.includes('permission_resolved'));
  assert.ok(types.includes('tool_result'));
  assert.equal(studio.events().find((e) => e.type === 'result').result, 'Fatto, assets ricompilati.');
  assert.throws(() => session.answerPermission('req_1', 'allow'), /no pending permission/);
  await studio.close();
});

test('denying tells the agent and still ends the turn', async () => {
  const studio = await startCollector();
  const session = new Session('13', testConfig(), { spawn: spawnFake });
  session.sendTurn({ text: 'tool please', callback: callback(studio) });
  await waitFor(() => studio.events().some((e) => e.type === 'permission_request'));
  session.answerPermission('req_1', 'deny', 'Not now');
  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  await session.close();
  assert.equal(studio.events().find((e) => e.type === 'result').result, 'Va bene, non lo faccio.');
  await studio.close();
});

test('bypassPermissions never asks', async () => {
  const studio = await startCollector();
  const session = new Session('14', testConfig(), { spawn: spawnFake });
  session.sendTurn({ text: 'tool please', permission_mode: 'bypassPermissions', callback: callback(studio) });
  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  await session.close();
  assert.ok(!studio.events().some((e) => e.type === 'permission_request'));
  await studio.close();
});

test('the next turn after the process ended resumes the same session id', async () => {
  const studio = await startCollector();
  const session = new Session('15', testConfig(), { spawn: spawnFake });

  session.sendTurn({ text: 'exit now', callback: callback(studio) });
  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  const first = session.sessionId;
  await waitFor(() => session.snapshot().alive === false);

  session.sendTurn({ text: 'again', callback: callback(studio) });
  await waitFor(() => studio.events().filter((e) => e.type === 'result').length === 2);
  await session.close();

  const inits = studio.events().filter((e) => e.type === 'init');
  assert.equal(inits.length, 2);
  assert.equal(inits[1].session_id, first);
  await studio.close();
});

test('a crash mid-turn becomes an error and a failed result', async () => {
  const studio = await startCollector();
  const session = new Session('16', testConfig(), { spawn: spawnFake });
  session.sendTurn({ text: 'crash', callback: callback(studio) });
  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  await session.close();
  const result = studio.events().find((e) => e.type === 'result');
  assert.equal(result.is_error, true);
  assert.ok(studio.events().some((e) => e.type === 'error' && e.code === 'process_exit'));
  assert.equal(session.state, 'idle');
  await studio.close();
});

test('model, effort and mode become flags and a change restarts the process', async () => {
  const studio = await startCollector();
  const seen = [];
  const spy = (bin, args, options) => { seen.push(args); return spawnFake(bin, args, options); };
  const session = new Session('18', testConfig(), { spawn: spy });

  session.sendTurn({ text: 'ciao', permission_mode: 'auto', model: 'claude-opus-5-5', effort: 'high', callback: callback(studio) });
  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  assert.ok(seen[0].includes('--effort') && seen[0][seen[0].indexOf('--effort') + 1] === 'high');
  assert.ok(seen[0][seen[0].indexOf('--model') + 1] === 'claude-opus-5-5');
  assert.ok(seen[0][seen[0].indexOf('--permission-mode') + 1] === 'auto');

  session.sendTurn({ text: 'ancora', permission_mode: 'plan', model: 'claude-opus-5-5', effort: 'high', callback: callback(studio) });
  await waitFor(() => studio.events().filter((e) => e.type === 'result').length === 2);
  await session.close();
  assert.equal(seen.length, 2, 'a mode change starts a new process');
  assert.ok(seen[1].includes('--resume'));
  assert.throws(() => session.sendTurn({ text: 'x', effort: 'extreme', callback: callback(studio) }), /effort/);
  await studio.close();
});

test('Studio MCP servers reach Claude Code through a private file, and a change restarts the process', async () => {
  const studio = await startCollector();
  const seen = [];
  const spy = (bin, args, options) => { seen.push(args); return spawnFake(bin, args, options); };
  const session = new Session('mcp-1', testConfig(), { spawn: spy });
  const docs = { type: 'http', url: 'https://docs.example.com/mcp', headers: { Authorization: 'Bearer docs-token' } };

  session.sendTurn({ text: 'ciao', mcp_servers: { docs }, callback: callback(studio) });
  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  const file = seen[0][seen[0].indexOf('--mcp-config') + 1];
  assert.ok(file && !seen[0].join(' ').includes('docs-token'), 'the token is never on the command line');
  assert.deepEqual(JSON.parse(readFileSync(file, 'utf8')), { mcpServers: { docs } });
  assert.equal(statSync(file).mode & 0o777, 0o600);

  session.sendTurn({ text: 'ancora', mcp_servers: { docs }, callback: callback(studio) });
  await waitFor(() => studio.events().filter((e) => e.type === 'result').length === 2);
  assert.equal(seen.length, 1, 'the same servers keep the process');

  session.sendTurn({ text: 'senza', mcp_servers: null, callback: callback(studio) });
  await waitFor(() => studio.events().filter((e) => e.type === 'result').length === 3);
  await session.close();
  assert.equal(seen.length, 2, 'dropping the servers restarts it');
  assert.ok(!seen[1].includes('--mcp-config'));
  await studio.close();
});

test('only remote MCP servers are accepted from Studio', () => {
  assert.equal(normalizeMcpServers(null).servers, null);
  assert.throws(() => normalizeMcpServers({ evil: { type: 'stdio', command: 'rm', args: ['-rf', '/'] } }), /type: "http"/);
  assert.throws(() => normalizeMcpServers({ 'bad name': { type: 'http', url: 'https://x' } }), /invalid MCP server name/);
  assert.throws(() => normalizeMcpServers({ prod: { type: 'http', url: 'file:///etc/passwd' } }), /type: "http"/);
  assert.equal(Object.keys(normalizeMcpServers({ docs: { type: 'http', url: 'https://docs.example.com/mcp' } }).servers).length, 1);
});

test('turns without text or callback are refused before anything starts', () => {
  const session = new Session('17', testConfig(), { spawn: spawnFake });
  assert.throws(() => session.sendTurn({ text: '', callback: { url: 'x', token: 'y' } }), /text is required/);
  assert.throws(() => session.sendTurn({ text: 'hi' }), /callback/);
  assert.equal(session.snapshot().alive, false);
});

test('a question from AskUserQuestion is answered with the person\'s choices', async () => {
  const studio = await startCollector();
  const session = new Session('31', testConfig(), { spawn: spawnFake });
  session.sendTurn({ text: 'ask me', callback: callback(studio) });

  const ask = await waitFor(() => studio.events().find((e) => e.type === 'permission_request'));
  assert.equal(ask.tool_name, 'AskUserQuestion');
  assert.equal(ask.input.questions[0].header, 'Effort');

  assert.throws(() => session.answerPermission('req_q', 'allow', null, ['MAX']), /answers must map/);
  session.answerPermission('req_q', 'allow', null, { 'How deep should Larapilot work?': 'MAX' });
  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  await session.close();

  assert.equal(studio.events().find((e) => e.type === 'result').result, 'User has answered your questions: "How deep should Larapilot work?"="MAX".');
  await studio.close();
});

test('allowing a question without answers is what makes the agent read "did not answer"', async () => {
  const studio = await startCollector();
  const session = new Session('32', testConfig(), { spawn: spawnFake });
  session.sendTurn({ text: 'ask me', callback: callback(studio) });
  await waitFor(() => studio.events().some((e) => e.type === 'permission_request'));
  session.answerPermission('req_q', 'allow');
  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  await session.close();
  assert.equal(studio.events().find((e) => e.type === 'result').result, 'The user did not answer the questions.');
  await studio.close();
});
