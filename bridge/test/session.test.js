import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, statSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { Session, claudeEnv, explainExit, normalizeMcpServers } from '../src/session.js';
import { startCollector, spawnFake, testConfig, waitFor } from './helpers.js';

const callback = (studio) => ({ url: studio.url, token: 'cb' });

test('a plain turn streams deltas, a text block and a result, then the session idles with its id', async () => {
  const studio = await startCollector();
  const session = new Session('11', testConfig(), { spawn: spawnFake });

  const snapshot = session.sendTurn({ text: 'ciao', permission_mode: 'default', callback: callback(studio) });
  assert.equal(snapshot.state, 'running');

  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  await session.close();

  const types = studio.events().map((e) => e.type).filter((type) => type !== 'plan_usage');
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
  assert.deepEqual(ask.suggestions, ['Bash(npm run build:*)']);
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
  assert.match(result.result, /stopped unexpectedly \(exit code 1\)/);
  assert.equal(session.state, 'idle');
  await studio.close();
});

test('a crash explains itself with what the CLI said on stderr', async () => {
  const studio = await startCollector();
  const session = new Session('16b', testConfig(), { spawn: spawnFake });
  session.sendTurn({ text: 'crash Invalid API key · Please run /login', callback: callback(studio) });
  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  await session.close();
  const error = studio.events().find((e) => e.type === 'error');
  assert.match(error.message, /not signed in/);
  assert.equal(studio.events().find((e) => e.type === 'result').result, error.message);
  await studio.close();
});

test('explainExit turns what the CLI said into something to act on', () => {
  assert.match(explainExit({ code: 1, stderr: '\u001b[31mError: Not logged in · Please run /login\u001b[0m' }), /Sign in again/);
  assert.match(explainExit({ code: 1, stderr: 'Credit balance is too low' }), /run out of credit/);
  assert.match(explainExit({ code: 1, stderr: 'No conversation found with session ID: abc' }), /could not be resumed/);
  assert.match(explainExit({ code: 1, stderr: '--dangerously-skip-permissions cannot be used with root/sudo privileges for security reasons' }), /Bypass permissions/);
  assert.match(explainExit({ signal: 'SIGKILL' }), /ran out of memory/);
  assert.match(explainExit({ error: Object.assign(new Error('spawn claude ENOENT'), { code: 'ENOENT' }) }), /not installed/);
  assert.equal(
    explainExit({ code: 2, stderr: 'warming up\n\nError: something odd\n' }),
    'Claude Code stopped unexpectedly (exit code 2) before finishing the turn. It said: warming up Error: something odd',
  );
  assert.equal(explainExit({ code: 1 }), 'Claude Code stopped unexpectedly (exit code 1) before finishing the turn.');
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

test('the Claude account Studio chooses decides the key the process sees, and a change restarts it', async () => {
  const studio = await startCollector();
  const envs = [];
  const spy = (bin, args, options) => { envs.push(options.env); return spawnFake(bin, args, options); };
  const session = new Session('19', testConfig(), { spawn: spy });
  const saved = { ...process.env };
  process.env.STUDIO_ANTHROPIC_API_KEY = 'sk-ant-api03-key';
  process.env.ANTHROPIC_API_KEY = 'left-over';
  try {
    session.sendTurn({ text: 'ciao', auth: 'subscription', callback: callback(studio) });
    await waitFor(() => studio.events().some((e) => e.type === 'result'));
    session.sendTurn({ text: 'ancora', auth: 'api_key', callback: callback(studio) });
    await waitFor(() => studio.events().filter((e) => e.type === 'result').length === 2);
  } finally {
    process.env = saved;
  }
  await session.close();

  assert.equal(envs.length, 2, 'another account starts a new process');
  assert.equal(envs[0].ANTHROPIC_API_KEY, undefined, 'the subscription runs on the login in the home');
  assert.equal(envs[1].ANTHROPIC_API_KEY, 'sk-ant-api03-key');
  assert.ok(envs.every((env) => env.STUDIO_ANTHROPIC_API_KEY === undefined), 'the agent never sees the kept key');
  assert.throws(() => session.sendTurn({ text: 'x', auth: 'someone-else', callback: callback(studio) }), /auth/);
  await studio.close();
});

test('without a choice from Studio the environment is left as it is, minus the kept key', () => {
  const env = claudeEnv({ ANTHROPIC_API_KEY: 'mine', STUDIO_ANTHROPIC_API_KEY: 'kept', PATH: '/bin' }, undefined);
  assert.equal(env.ANTHROPIC_API_KEY, 'mine');
  assert.equal(env.STUDIO_ANTHROPIC_API_KEY, undefined);
  assert.equal(claudeEnv({ STUDIO_ANTHROPIC_API_KEY: 'kept' }, 'api_key').ANTHROPIC_API_KEY, 'kept');
  assert.equal(claudeEnv({ CLAUDE_CODE_OAUTH_TOKEN: 'old' }, 'subscription').CLAUDE_CODE_OAUTH_TOKEN, undefined);
});

test('the API key sent with the turn wins over the kept one', async () => {
  const studio = await startCollector();
  const envs = [];
  const spy = (bin, args, options) => { envs.push(options.env); return spawnFake(bin, args, options); };
  const session = new Session('20', testConfig(), { spawn: spy });
  const saved = { ...process.env };
  process.env.STUDIO_ANTHROPIC_API_KEY = 'sk-ant-api03-kept';
  process.env.ANTHROPIC_API_KEY = 'left-over';
  process.env.CLAUDE_CODE_OAUTH_TOKEN = 'left-over-token';
  try {
    session.sendTurn({ text: 'ciao', auth: 'api_key', account: 'acc-1', api_key: 'sk-ant-api03-from-turn', callback: callback(studio) });
    await waitFor(() => studio.events().some((e) => e.type === 'result'));
  } finally {
    process.env = saved;
  }
  await session.close();

  assert.equal(envs[0].ANTHROPIC_API_KEY, 'sk-ant-api03-from-turn');
  assert.equal(envs[0].CLAUDE_CODE_OAUTH_TOKEN, undefined);
  assert.equal(envs[0].STUDIO_ANTHROPIC_API_KEY, undefined);
  assert.ok(!JSON.stringify(studio.posts).includes('from-turn'), 'the key never reaches the events');
  assert.ok(!JSON.stringify(session.snapshot()).includes('from-turn'));
  await studio.close();
});

test('claude_dir picks the subscription\'s config directory in the home; a bad one is refused', () => {
  const env = claudeEnv({ HOME: '/home/ws-maria', ANTHROPIC_API_KEY: 'x', CLAUDE_CODE_OAUTH_TOKEN: 'y' }, 'subscription', { claudeDir: '.claude-work' });
  assert.equal(env.CLAUDE_CONFIG_DIR, '/home/ws-maria/.claude-work');
  assert.equal(env.ANTHROPIC_API_KEY, undefined);
  assert.equal(env.CLAUDE_CODE_OAUTH_TOKEN, undefined);
  assert.equal(claudeEnv({ HOME: '/home/ws-maria' }, 'subscription', { claudeDir: '.claude' }).CLAUDE_CONFIG_DIR, '/home/ws-maria/.claude');
  // absent: whatever the process has
  assert.equal(claudeEnv({ HOME: '/home/ws-maria', CLAUDE_CONFIG_DIR: '/elsewhere' }, 'subscription').CLAUDE_CONFIG_DIR, '/elsewhere');
  // the key's own account: the dir does not apply
  assert.equal(claudeEnv({ HOME: '/h' }, 'api_key', { claudeDir: '.claude-work', apiKey: 'sk-ant-k' }).CLAUDE_CONFIG_DIR, undefined);

  const session = new Session('21', testConfig(), { spawn: spawnFake });
  const cb = { url: 'http://127.0.0.1:1/x', token: 'cb' };
  for (const claude_dir of ['../etc', '.claude-', '.claude-Work', '.claude/x', '.claudex', '.claude-' + 'a'.repeat(41), 7]) {
    assert.throws(() => session.sendTurn({ text: 'x', auth: 'subscription', claude_dir, callback: cb }), (e) => e.status === 422 && /claude_dir/.test(e.message));
  }
  assert.throws(() => session.sendTurn({ text: 'x', account: 'a b', callback: cb }), (e) => e.status === 422 && /account/.test(e.message));
  assert.throws(() => session.sendTurn({ text: 'x', account: 'a'.repeat(65), callback: cb }), /account/);
  for (const api_key of ['sk-live-secretvalue', 'sk-ant-' + 'a'.repeat(506), 'sk-ant-se cret']) {
    assert.throws(() => session.sendTurn({ text: 'x', auth: 'api_key', api_key, callback: cb }), (e) => e.status === 422 && !e.message.includes('secret') && !e.message.includes('aaaa'));
  }
  assert.equal(session.snapshot().alive, false, 'nothing started');
});

test('a subscription turn spawns with its CLAUDE_CONFIG_DIR, and another account resumes the session in a new process', async () => {
  const studio = await startCollector();
  const seen = [];
  const spy = (bin, args, options) => { seen.push({ args, env: options.env }); return spawnFake(bin, args, options); };
  const session = new Session('22', testConfig(), { spawn: spy });

  session.sendTurn({ text: 'ciao', auth: 'subscription', account: 'default', claude_dir: '.claude', callback: callback(studio) });
  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  const first = session.sessionId;

  // same account: the same process takes the next turn
  session.sendTurn({ text: 'ancora', auth: 'subscription', account: 'default', claude_dir: '.claude', callback: callback(studio) });
  await waitFor(() => studio.events().filter((e) => e.type === 'result').length === 2);
  assert.equal(seen.length, 1);

  session.sendTurn({ text: 'altro', auth: 'subscription', account: 'work', claude_dir: '.claude-work', callback: callback(studio) });
  await waitFor(() => studio.events().filter((e) => e.type === 'result').length === 3);
  await session.close();

  assert.equal(seen.length, 2, 'another account starts a new process');
  assert.equal(seen[0].env.CLAUDE_CONFIG_DIR, join(process.env.HOME ?? homedir(), '.claude'));
  assert.equal(seen[1].env.CLAUDE_CONFIG_DIR, join(process.env.HOME ?? homedir(), '.claude-work'));
  assert.equal(seen[1].args[seen[1].args.indexOf('--resume') + 1], first, 'the conversation carries on');
  await studio.close();
});

test('after a turn the CLI is asked what /usage shows, and the plan windows reach Studio', async () => {
  const studio = await startCollector();
  const session = new Session('31', testConfig(), { spawn: spawnFake });

  session.sendTurn({ text: 'ciao', auth: 'subscription', callback: callback(studio) });
  const usage = await waitFor(() => studio.events().find((e) => e.type === 'plan_usage'));
  assert.equal(usage.subscription_type, 'max');
  assert.deepEqual(usage.rate_limits.five_hour, { utilization: 23, resets_at: '2026-10-13T16:00:00Z' });

  // not again within the minute
  session.sendTurn({ text: 'ancora', auth: 'subscription', callback: callback(studio) });
  await waitFor(() => studio.events().filter((e) => e.type === 'result').length === 2);
  await new Promise((r) => setTimeout(r, 100));
  await session.close();
  assert.equal(studio.events().filter((e) => e.type === 'plan_usage').length, 1);
  await studio.close();
});

test('on an API key the plan is not asked about', async () => {
  const studio = await startCollector();
  const session = new Session('32', testConfig(), { spawn: spawnFake });

  session.sendTurn({ text: 'ciao', auth: 'api_key', callback: callback(studio) });
  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  await new Promise((r) => setTimeout(r, 100));
  await session.close();
  assert.ok(!studio.events().some((e) => e.type === 'plan_usage'));
  await studio.close();
});

test('"Always allow" answers with the rule for the rest of the session', async () => {
  const studio = await startCollector();
  const session = new Session('13', testConfig(), { spawn: spawnFake });

  session.sendTurn({ text: 'tool please', permission_mode: 'default', callback: callback(studio) });
  await waitFor(() => studio.events().some((e) => e.type === 'permission_request'));
  assert.throws(() => session.answerPermission('req_1', 'allow', null, null, ['rm -rf /']), /allowed_tools/);
  session.answerPermission('req_1', 'allow', null, null, ['Bash(npm run build:*)']);
  await waitFor(() => studio.events().some((e) => e.type === 'result'));
  await session.close();

  assert.match(studio.events().find((e) => e.type === 'tool_result').content, /always session:Bash\(npm run build:\*\)/);
  await studio.close();
});

test('a control request the bridge does not handle gets an error answer, so the turn goes on', async () => {
  const studio = await startCollector();
  const session = new Session('14', testConfig(), { spawn: spawnFake });

  session.sendTurn({ text: 'unknown request', permission_mode: 'default', callback: callback(studio) });
  const result = await waitFor(() => studio.events().find((e) => e.type === 'result'));
  await session.close();

  assert.equal(result.result, 'error: Studio does not handle elicitation');
  assert.ok(studio.events().some((e) => e.type === 'raw'));
  await studio.close();
});

test('a subagent still running in the background keeps the CLI past the idle time; its woken result ends that turn', async (t) => {
  const studio = await startCollector();
  // idle after 0.2 s, the background task takes 0.6 s
  const session = new Session('15', testConfig({ idleTtl: 0.2 }), { spawn: spawnFake });
  t.after(async () => {
    await session.close();
    await studio.close();
  });

  session.sendTurn({ text: 'background', permission_mode: 'default', callback: callback(studio) });
  const woken = await waitFor(() => studio.events().find((e) => e.type === 'result' && e.woken));
  assert.equal(woken.result, 'woken');
  assert.ok(studio.events().some((e) => e.type === 'text' && e.text === 'Riprendo dal punto in cui ero.'));
  assert.equal(session.state, 'idle');
});
