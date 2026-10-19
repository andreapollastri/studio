import test from 'node:test';
import assert from 'node:assert/strict';
import { normalize, stripAnsi } from '../src/normalize.js';

test('init carries the session id and the model', () => {
  const [event] = normalize({ type: 'system', subtype: 'init', session_id: 's1', model: 'claude-sonnet-5', cwd: '/w', permissionMode: 'default', tools: ['Bash'] });
  assert.equal(event.type, 'init');
  assert.equal(event.session_id, 's1');
  assert.equal(event.model, 'claude-sonnet-5');
  assert.equal(event.tools, 1);
  assert.equal(event.api_key_source, null);
  assert.equal(normalize({ type: 'system', subtype: 'init', apiKeySource: 'ANTHROPIC_API_KEY' })[0].api_key_source, 'ANTHROPIC_API_KEY');
});

test('a rate limit event keeps what the CLI says about the plan', () => {
  const info = { status: 'allowed_warning', utilization: 0.82, resetsAt: 1792000000 };
  assert.deepEqual(normalize({ type: 'rate_limit_event', rate_limit_info: info }), [{ type: 'status', status: 'rate_limit', info }]);
});

test('only text deltas become text_delta events', () => {
  assert.deepEqual(normalize({ type: 'stream_event', event: { type: 'content_block_delta', index: 0, delta: { type: 'text_delta', text: 'Ho ' } } }), [{ type: 'text_delta', text: 'Ho ', index: 0 }]);
  assert.deepEqual(normalize({ type: 'stream_event', event: { type: 'content_block_delta', index: 0, delta: { type: 'input_json_delta', partial_json: '{"a' } } }), []);
  assert.deepEqual(normalize({ type: 'stream_event', event: { type: 'message_stop' } }), []);
});

test('assistant blocks split into text, thinking and tool_use', () => {
  const events = normalize({ type: 'assistant', uuid: 'u', parent_tool_use_id: null, message: { id: 'm', content: [
    { type: 'thinking', thinking: 'hmm', signature: 'x' },
    { type: 'text', text: 'Ciao' },
    { type: 'tool_use', id: 't1', name: 'Bash', input: { command: 'ls' } },
  ] } });
  assert.deepEqual(events.map((e) => e.type), ['thinking', 'text', 'tool_use']);
  assert.equal(events[2].input.command, 'ls');
  assert.equal(events[1].message_id, 'm');
});

test('tool results flatten block arrays into text', () => {
  const [event] = normalize({ type: 'user', message: { role: 'user', content: [{ type: 'tool_result', tool_use_id: 't1', content: [{ type: 'text', text: 'a' }, { type: 'image' }], is_error: true }] } });
  assert.equal(event.type, 'tool_result');
  assert.equal(event.content, 'a\n[image]');
  assert.equal(event.is_error, true);
});

test('a result the CLI started on its own (woken by background tasks) is a result too, marked woken', () => {
  const [event] = normalize({ type: 'result', subtype: 'success', origin: { kind: 'task-notification' }, num_turns: 0 });
  assert.equal(event.type, 'result');
  assert.equal(event.woken, true);
  assert.equal(normalize({ type: 'result', subtype: 'success' })[0].woken, false);
});

test('a real result exposes cost, usage and the context window', () => {
  const [event] = normalize({ type: 'result', subtype: 'success', is_error: false, session_id: 's1', total_cost_usd: 0.5, usage: { input_tokens: 1 }, modelUsage: { 'claude-sonnet-5': { contextWindow: 200000 } }, result: 'ok' });
  assert.equal(event.type, 'result');
  assert.equal(event.context_window, 200000);
  assert.equal(event.total_cost_usd, 0.5);
});

test('hook payloads are dropped and unknown types are kept raw but bounded', () => {
  assert.deepEqual(normalize({ type: 'system', subtype: 'hook_response', output: 'x'.repeat(100000) }), []);
  const [event] = normalize({ type: 'something_new', payload: 'y'.repeat(10000) });
  assert.equal(event.type, 'raw');
  assert.ok(event.payload.length < 5000);
});

test('ansi escapes are stripped from reasons', () => {
  assert.equal(stripAnsi('Build the \u001b[1massets\u001b[0m'), 'Build the assets');
});

test('what Claude Code refused on its own reaches Studio: as it happens, and listed with the result', () => {
  assert.deepEqual(normalize({ type: 'system', subtype: 'permission_denied', tool_name: 'AskUserQuestion', tool_use_id: 'toolu_q', reason: 'no host' }), [
    { type: 'permission_denied', tool_name: 'AskUserQuestion', tool_use_id: 'toolu_q', reason: 'no host' },
  ]);
  const [result] = normalize({ type: 'result', subtype: 'success', result: 'ok', permission_denials: [{ tool_name: 'Bash', tool_use_id: 'toolu_b', tool_input: { command: 'rm -rf /' } }, { nope: 1 }] });
  assert.deepEqual(result.permission_denials, [{ tool_name: 'Bash', tool_use_id: 'toolu_b' }]);
  assert.deepEqual(normalize({ type: 'result', subtype: 'success', result: 'ok' })[0].permission_denials, []);
});
