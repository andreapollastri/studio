#!/usr/bin/env node
/**
 * A stand-in for the `claude` CLI that speaks the stream-json protocol the
 * bridge drives: init, deltas, assistant blocks, a permission question held
 * open on stdin, tool results, a result line. Deterministic and fast.
 *
 * Prompts it understands: anything (plain answer), "tool …" (asks to run a
 * tool first), "ask …" (asks the person questions with AskUserQuestion),
 * "exit …" (ends the process after the result), "crash [stderr]" (dies without
 * a result, saying what follows on stderr), "screenshot …" (a subagent opens
 * the preview with the browser and takes a screenshot).
 *
 * With FAKE_CLAUDE_ARGS_DIR set, it writes its arguments and directory there.
 */
import { createInterface } from 'node:readline';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';

const args = process.argv.slice(2);
const flag = (name) => {
  const i = args.indexOf(name);
  return i === -1 ? null : args[i + 1];
};
const permissionMode = flag('--permission-mode') || 'default';
const model = flag('--model') || 'claude-sonnet-5';
const sessionId = flag('--resume') || `sess-${Math.random().toString(36).slice(2, 10)}`;
const hasStdioPermissions = args.includes('--permission-prompt-tool');

if (process.env.FAKE_CLAUDE_ARGS_DIR) {
  writeFileSync(join(process.env.FAKE_CLAUDE_ARGS_DIR, `args-${process.pid}.json`), JSON.stringify({ args, cwd: process.cwd() }));
}

const out = (object) => process.stdout.write(JSON.stringify(object) + '\n');
let uuid = 0;
const next = () => `u${++uuid}`;

out({ type: 'system', subtype: 'init', session_id: sessionId, cwd: process.cwd(), model, permissionMode, tools: ['Bash', 'Read', 'Edit'], uuid: next() });

let pending = null;

const rl = createInterface({ input: process.stdin });
rl.on('line', (line) => {
  let message;
  try {
    message = JSON.parse(line);
  } catch {
    return;
  }

  // what /usage shows: plan windows on a subscription, nothing on an API key
  if (message.type === 'control_request' && message.request?.subtype === 'get_usage') {
    const plan = !process.env.ANTHROPIC_API_KEY;
    out({ type: 'control_response', response: { subtype: 'success', request_id: message.request_id, response: {
      subscription_type: plan ? 'max' : null,
      rate_limits_available: plan,
      rate_limits: plan ? { five_hour: { utilization: 23, resets_at: '2026-10-13T16:00:00Z' }, seven_day: { utilization: 41.5, resets_at: '2026-10-17T09:00:00Z' } } : null,
    } } });
    return;
  }

  if (message.type === 'control_response' && message.response?.request_id === 'req_unknown') {
    // the bridge answered a request it does not handle: the turn goes on with what it said
    const said = `${message.response.subtype}: ${message.response.error ?? ''}`;
    out({ type: 'assistant', session_id: sessionId, uuid: next(), parent_tool_use_id: null, message: { id: 'msg_unknown', role: 'assistant', model, content: [{ type: 'text', text: said }] } });
    out({ type: 'result', subtype: 'success', is_error: false, duration_ms: 1, num_turns: 1, session_id: sessionId, result: said, uuid: next() });
    return;
  }

  if (message.type === 'control_response') {
    const response = message.response ?? {};
    if (pending && response.request_id === pending.requestId) {
      const behavior = response.response?.behavior;
      const finish = pending.finish;
      pending = null;
      finish(behavior === 'allow', response.response ?? {});
    }
    return;
  }

  if (message.type !== 'user') return;
  const text = message.message?.content?.[0]?.text ?? '';

  if (text === 'unknown request') {
    out({ type: 'control_request', request_id: 'req_unknown', request: { subtype: 'elicitation', message: 'Pick one' } });
    return;
  }

  if (text.startsWith('crash')) {
    const said = text.slice('crash'.length).trim();
    if (!said) process.exit(1);
    process.stderr.write(said + '\n', () => process.exit(1));
    return;
  }

  const say = (sentence, id) => {
    const words = sentence.split(' ');
    for (let i = 0; i < words.length; i++) {
      out({ type: 'stream_event', session_id: sessionId, uuid: next(), event: { type: 'content_block_delta', index: 0, delta: { type: 'text_delta', text: words[i] + (i < words.length - 1 ? ' ' : '') } } });
    }
    out({ type: 'assistant', session_id: sessionId, uuid: next(), parent_tool_use_id: null, message: { id, role: 'assistant', model, content: [{ type: 'text', text: sentence }] } });
  };

  const result = (text) => {
    out({
      type: 'result', subtype: 'success', is_error: false, duration_ms: 42, duration_api_ms: 40, num_turns: 1,
      stop_reason: 'end_turn', session_id: sessionId, total_cost_usd: 0.01, result: text,
      usage: { input_tokens: 10, output_tokens: 20 }, modelUsage: { [model]: { contextWindow: 200000, costUSD: 0.01 } }, uuid: next(),
    });
    if (text.startsWith('exit') || process.env.FAKE_EXIT_AFTER_RESULT === '1') process.exit(0);
  };

  if (text.startsWith('ask')) {
    // AskUserQuestion goes through can_use_tool like any tool; the answers come back in updatedInput.
    const questions = [{ question: 'How deep should Larapilot work?', header: 'Effort', multiSelect: false, options: [{ label: 'STANDARD', description: 'normal depth' }, { label: 'MAX', description: 'deep on every flow' }] }];
    out({ type: 'assistant', session_id: sessionId, uuid: next(), parent_tool_use_id: null, message: { id: 'msg_ask', role: 'assistant', model, content: [{ type: 'tool_use', id: 'toolu_q', name: 'AskUserQuestion', input: { questions } }] } });

    const finish = (allowed, response) => {
      const answers = Object.entries(response.updatedInput?.answers ?? {}).map(([q, a]) => `"${q}"="${a}"`).join(', ');
      const content = !allowed ? (response.message ?? 'denied') : (answers ? `User has answered your questions: ${answers}.` : 'The user did not answer the questions.');
      out({ type: 'user', session_id: sessionId, uuid: next(), parent_tool_use_id: null, message: { role: 'user', content: [{ type: 'tool_result', tool_use_id: 'toolu_q', content, is_error: !allowed }] } });
      result(content);
    };

    pending = { requestId: 'req_q', finish };
    out({ type: 'control_request', request_id: 'req_q', request: { subtype: 'can_use_tool', tool_name: 'AskUserQuestion', input: { questions }, tool_use_id: 'toolu_q' } });
    return;
  }

  if (text.startsWith('screenshot')) {
    // a 1×1 PNG, as the browser's screenshot tool answers it
    const png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    out({ type: 'system', subtype: 'task_started', session_id: sessionId, task_id: 'task_1', tool_use_id: 'toolu_agent', description: 'Check the home page', task_type: 'local_agent', uuid: next() });
    out({ type: 'assistant', session_id: sessionId, uuid: next(), parent_tool_use_id: null, message: { id: 'msg_agent', role: 'assistant', model, content: [{ type: 'tool_use', id: 'toolu_agent', name: 'Task', input: { description: 'Check the home page', subagent_type: 'browser-qa', prompt: 'Open / and take a screenshot' } }] } });
    out({ type: 'assistant', session_id: sessionId, uuid: next(), parent_tool_use_id: 'toolu_agent', message: { id: 'msg_sub', role: 'assistant', model, content: [{ type: 'tool_use', id: 'toolu_shot', name: 'mcp__browser__browser_take_screenshot', input: {} }] } });
    out({ type: 'user', session_id: sessionId, uuid: next(), parent_tool_use_id: 'toolu_agent', message: { role: 'user', content: [{ type: 'tool_result', tool_use_id: 'toolu_shot', content: [{ type: 'text', text: 'Took the screenshot of the viewport' }, { type: 'image', source: { type: 'base64', media_type: 'image/png', data: png } }] }] } });
    out({ type: 'user', session_id: sessionId, uuid: next(), parent_tool_use_id: null, message: { role: 'user', content: [{ type: 'tool_result', tool_use_id: 'toolu_agent', content: [{ type: 'text', text: 'The home page renders, no console errors.' }] }] } });
    out({ type: 'system', subtype: 'task_notification', session_id: sessionId, task_id: 'task_1', tool_use_id: 'toolu_agent', status: 'completed', summary: 'Home page checked', uuid: next() });
    say('La home funziona.', 'msg_end');
    result('La home funziona.');
    return;
  }

  if (text.startsWith('tool')) {
    say('Prima lancio la build.', 'msg_tool');
    out({ type: 'assistant', session_id: sessionId, uuid: next(), parent_tool_use_id: null, message: { id: 'msg_tool', role: 'assistant', model, content: [{ type: 'tool_use', id: 'toolu_1', name: 'Bash', input: { command: 'npm run build' } }] } });

    const finish = (allowed, response = {}) => {
      if (allowed) {
        // rules that came back with the answer ("Always allow") show in the result, for the tests
        const always = (response.updatedPermissions ?? []).flatMap((update) => update.rules.map((rule) => `${update.destination}:${rule.toolName}(${rule.ruleContent})`));
        const content = `built in 1.2s${always.length ? ` · always ${always.join(', ')}` : ''}`;
        out({ type: 'user', session_id: sessionId, uuid: next(), parent_tool_use_id: null, message: { role: 'user', content: [{ type: 'tool_result', tool_use_id: 'toolu_1', content, is_error: false }] } });
        say('Fatto, assets ricompilati.', 'msg_done');
        result('Fatto, assets ricompilati.');
      } else {
        say('Va bene, non lo faccio.', 'msg_denied');
        result('Va bene, non lo faccio.');
      }
    };

    if (hasStdioPermissions && permissionMode !== 'bypassPermissions') {
      pending = { requestId: 'req_1', finish };
      out({ type: 'control_request', request_id: 'req_1', request: { subtype: 'can_use_tool', tool_name: 'Bash', input: { command: 'npm run build' }, tool_use_id: 'toolu_1', description: 'Build the \u001b[1massets\u001b[0m', permission_suggestions: [{ type: 'addRules', rules: [{ toolName: 'Bash', ruleContent: 'npm run build:*' }], behavior: 'allow', destination: 'localSettings' }] } });
    } else {
      finish(true);
    }
    return;
  }

  say(`Ho letto: ${text}`, 'msg_1');
  result(text.startsWith('exit') ? text : `Ho letto: ${text}`);
});
