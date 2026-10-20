import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn as spawnReal } from 'node:child_process';
import { cursorArgs, cursorEnv, normalizeCursor } from '../src/cursor.js';
import { Session } from '../src/session.js';
import { startCollector, testConfig, waitFor } from './helpers.js';

test('cursor print mode asks, plans, or acts, and resumes a session', () => {
  assert.deepEqual(cursorArgs({ text: 'hello', permissionMode: 'default' }).slice(0, -1), [
    '-p', '--output-format', 'stream-json', '--stream-partial-output', '--trust', '--mode', 'ask',
  ]);
  assert.equal(cursorArgs({ text: 'hello', permissionMode: 'default' }).at(-1), 'hello');
  assert.ok(cursorArgs({ text: 'think', permissionMode: 'plan' }).includes('plan'));
  assert.ok(cursorArgs({ text: 'do it', permissionMode: 'auto' }).includes('--force'));
  assert.ok(cursorArgs({ text: 'again', permissionMode: 'default', sessionId: 'cur-9' }).includes('cur-9'));
});

test('the cursor environment is one subscription folder and never a Claude key', () => {
  const env = cursorEnv(
    { HOME: '/home/ada', PATH: '/bin', ANTHROPIC_API_KEY: 'sk-ant-secret', STUDIO_ANTHROPIC_API_KEY: 'sk-ant-secret' },
    { cursorDir: '.cursor-work' },
  );

  assert.equal(env.CURSOR_CONFIG_DIR, '/home/ada/.cursor-work');
  assert.equal(env.XDG_CONFIG_HOME, '/home/ada/.cursor-work');
  assert.equal(env.AGENT_CLI_CREDENTIAL_STORE, 'file');
  assert.equal(env.NO_OPEN_BROWSER, '1');
  assert.equal(env.ANTHROPIC_API_KEY, undefined);
  assert.equal(env.STUDIO_ANTHROPIC_API_KEY, undefined);
  assert.equal(cursorEnv({ HOME: '/home/ada' }, { cursorDir: '../.ssh' }).CURSOR_CONFIG_DIR, undefined);
});

test('partial cursor text is live only, a repeated assistant line is dropped, and a tool call is a tool', () => {
  const delta = normalizeCursor({
    type: 'assistant',
    timestamp_ms: 1,
    message: { content: [{ type: 'text', text: 'Hi' }] },
  });
  assert.deepEqual(delta, [{ type: 'text_delta', text: 'Hi' }]);

  assert.deepEqual(normalizeCursor({
    type: 'assistant',
    timestamp_ms: 2,
    model_call_id: 'call',
    message: { content: [{ type: 'text', text: 'Hi' }] },
  }), []);

  const started = normalizeCursor({
    type: 'tool_call',
    subtype: 'started',
    call_id: 'c1',
    tool_call: { readToolCall: { args: { path: 'a.txt' } } },
  });
  assert.equal(started[0].type, 'tool_use');
  assert.equal(started[0].name, 'read');
  assert.equal(started[0].id, 'c1');

  const done = normalizeCursor({
    type: 'tool_call',
    subtype: 'completed',
    call_id: 'c1',
    tool_call: { readToolCall: { result: { success: { content: 'file' } } } },
  });
  assert.equal(done[0].type, 'tool_result');
  assert.equal(done[0].content, 'file');

  const result = normalizeCursor({ type: 'result', subtype: 'success', is_error: false, result: 'Hi', session_id: 'cur-1' });
  assert.equal(result[0].type, 'text');
  assert.equal(result[0].text, 'Hi');
  assert.equal(result.at(-1).type, 'result');
  assert.equal(result.at(-1).session_id, 'cur-1');
});

test('a cursor turn spawns agent with the prompt and does not talk Claude\'s stdin protocol', async () => {
  const studio = await startCollector();
  let launched;
  const spawn = (bin, args, options) => {
    launched = { bin, args, env: options.env };
    const script = `
      const lines = ${JSON.stringify([
        { type: 'system', subtype: 'init', session_id: 'cur-1', model: 'composer', apiKeySource: 'login' },
        { type: 'assistant', timestamp_ms: 1, message: { content: [{ type: 'text', text: 'Hi' }] } },
        { type: 'assistant', model_call_id: 'x', timestamp_ms: 2, message: { content: [{ type: 'text', text: 'Hi again' }] } },
        { type: 'tool_call', subtype: 'started', call_id: 'c1', tool_call: { readToolCall: { args: { path: 'a.txt' } } } },
        { type: 'tool_call', subtype: 'completed', call_id: 'c1', tool_call: { readToolCall: { result: { success: { content: 'file' } } } } },
        { type: 'result', subtype: 'success', is_error: false, result: 'Hi', session_id: 'cur-1' },
      ])};
      for (const line of lines) process.stdout.write(JSON.stringify(line) + '\\n');
    `;
    return spawnReal(process.execPath, ['-e', script], { stdio: ['pipe', 'pipe', 'pipe'] });
  };

  const session = new Session('cur', testConfig({ cursorBin: 'agent' }), { spawn });
  const snapshot = session.sendTurn({
    text: 'hello there',
    agent: 'cursor',
    cursor_dir: '.cursor-work',
    permission_mode: 'plan',
    session_id: 'old',
    callback: { url: studio.url, token: 'cb' },
  });

  assert.equal(snapshot.state, 'running');
  assert.equal(launched.bin, 'agent');
  assert.ok(launched.args.includes('--mode'));
  assert.ok(launched.args.includes('plan'));
  assert.ok(launched.args.includes('--resume'));
  assert.ok(launched.args.includes('old'));
  assert.equal(launched.args.at(-1), 'hello there');
  assert.match(launched.env.CURSOR_CONFIG_DIR, /\.cursor-work$/);
  assert.equal(launched.env.ANTHROPIC_API_KEY, undefined);

  await waitFor(() => studio.events().some((event) => event.type === 'result'));
  await session.close();

  const types = studio.events().map((event) => event.type);
  assert.ok(types.includes('text_delta'));
  assert.ok(types.includes('tool_use'));
  assert.ok(types.includes('tool_result'));
  assert.equal(studio.events().find((event) => event.type === 'text').text, 'Hi');
  assert.equal(studio.events().filter((event) => event.type === 'text_delta').length, 1);
  assert.equal(session.sessionId, 'cur-1');
  await studio.close();
});
