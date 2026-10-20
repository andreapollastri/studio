import { join } from 'node:path';
import { normalize } from './normalize.js';

/** A subscription's folder in the person's home: the first is `.cursor`. */
export const CURSOR_DIR = /^\.cursor(-[a-z0-9]{1,40})?$/;

/**
 * Cursor's headless print mode: one process per turn, the prompt as an argument,
 * NDJSON on stdout. There is no stdin stream and no permission question to answer.
 *
 * Plan and the everyday "ask" mode map onto Cursor's modes. The modes that let
 * Claude edit map onto `--force`, which lets Cursor act unless its own rules deny it.
 *
 * @param {{ text: string, sessionId?: string|null, permissionMode?: string }} turn
 * @returns {string[]}
 */
export function cursorArgs(turn) {
  const args = ['-p', '--output-format', 'stream-json', '--stream-partial-output', '--trust'];
  const mode = turn.permissionMode || 'default';

  if (mode === 'plan') args.push('--mode', 'plan');
  else if (mode === 'default') args.push('--mode', 'ask');
  else args.push('--force');

  if (turn.sessionId) args.push('--resume', turn.sessionId);
  args.push(turn.text);

  return args;
}

/**
 * The login of one subscription, in its own folder, the way the sign-in terminal
 * wrote it. The API key of a Claude account is never part of this environment.
 *
 * @param {NodeJS.ProcessEnv} base
 * @param {{ cursorDir?: string|null, home?: string|null }} account
 */
export function cursorEnv(base, { cursorDir = null, home = null } = {}) {
  const env = { ...base };
  delete env.ANTHROPIC_API_KEY;
  delete env.STUDIO_ANTHROPIC_API_KEY;

  const root = home || env.HOME;
  if (cursorDir && root && CURSOR_DIR.test(cursorDir)) {
    const dir = join(root, cursorDir);
    env.CURSOR_CONFIG_DIR = dir;
    env.XDG_CONFIG_HOME = dir;
  }

  env.AGENT_CLI_CREDENTIAL_STORE = 'file';
  env.NO_OPEN_BROWSER = '1';

  return env;
}

/**
 * Cursor's stream-json into Studio's events. Partial assistant lines are live
 * text only; the stored answer is the result. A later assistant line that repeats
 * the same text (the one carrying `model_call_id`) is dropped.
 *
 * @param {Record<string, unknown>} line
 * @returns {Record<string, unknown>[]}
 */
export function normalizeCursor(line) {
  if (!line || typeof line !== 'object') return [];

  if (line.type === 'assistant') {
    if (line.timestamp_ms != null && line.model_call_id == null) {
      const text = assistantText(line);
      return text ? [{ type: 'text_delta', text }] : [];
    }
    return [];
  }

  if (line.type === 'tool_call') return normalizeToolCall(line);

  if (line.type === 'user') return [];

  if (line.type === 'result') {
    const events = normalize(line);
    const text = typeof line.result === 'string' ? line.result.trim() : '';
    if (text && !line.is_error) events.unshift({ type: 'text', text });
    return events;
  }

  return normalize(line);
}

function assistantText(line) {
  const content = line.message?.content;
  if (!Array.isArray(content)) return '';

  return content
    .filter((block) => block && block.type === 'text' && typeof block.text === 'string')
    .map((block) => block.text)
    .join('');
}

function normalizeToolCall(line) {
  const call = line.tool_call && typeof line.tool_call === 'object' ? line.tool_call : {};
  const key = Object.keys(call)[0] ?? 'tool';
  const body = call[key] && typeof call[key] === 'object' ? call[key] : {};
  const name = key === 'function' && typeof body.name === 'string' ? body.name : key.replace(/ToolCall$/, '');
  const id = typeof line.call_id === 'string' ? line.call_id : null;

  if (line.subtype === 'completed') {
    const failed = Boolean(body.result && typeof body.result === 'object' && body.result.error);
    return [{
      type: 'tool_result',
      tool_use_id: id,
      content: toolResultText(body).slice(0, 8000),
      is_error: failed,
    }];
  }

  return [{ type: 'tool_use', id, name: name || 'tool', input: body.args ?? body.arguments ?? {} }];
}

function toolResultText(body) {
  const result = body?.result;
  if (result == null) return '';
  if (typeof result === 'string') return result;

  const payload = result.success ?? result.error ?? result;
  if (typeof payload === 'string') return payload;
  if (payload && typeof payload.content === 'string') return payload.content;

  try {
    return JSON.stringify(payload);
  } catch {
    return '';
  }
}
