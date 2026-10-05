/**
 * Turns one raw stream-json line from Claude Code into zero or more Studio
 * events. Studio's vocabulary is small on purpose: init, text_delta, text,
 * thinking, tool_use, tool_result, permission_request, permission_resolved,
 * result, status, error, raw. Anything new the CLI ships lands in `raw`.
 *
 * `control_request` is NOT handled here: it is a question the session must
 * answer, see Session.
 */
const RAW_LIMIT = 4000;

export function normalize(line) {
  if (!line || typeof line !== 'object') return [];

  switch (line.type) {
    case 'system':
      return normalizeSystem(line);
    case 'stream_event':
      return normalizeStreamEvent(line);
    case 'assistant':
      return normalizeAssistant(line);
    case 'user':
      return normalizeUser(line);
    case 'result':
      return normalizeResult(line);
    case 'rate_limit_event':
      return [{ type: 'status', status: 'rate_limit', info: line.rate_limit_info ?? null }];
    case 'control_request':
    case 'control_response':
    case 'control_cancel_request':
      return [];
    default:
      return [raw(line)];
  }
}

function normalizeSystem(line) {
  switch (line.subtype) {
    case 'init':
      return [{
        type: 'init',
        session_id: line.session_id ?? null,
        model: line.model ?? null,
        cwd: line.cwd ?? null,
        permission_mode: line.permissionMode ?? null,
        tools: Array.isArray(line.tools) ? line.tools.length : null,
      }];
    case 'status':
      return [{ type: 'status', status: line.status ?? 'unknown' }];
    case 'thinking_tokens':
    case 'hook_started':
    case 'hook_response':
    case 'task_started':
    case 'task_updated':
      return []; // noise for the chat, and hook payloads can be enormous
    case 'task_notification':
      return [{ type: 'status', status: 'task_notification', summary: line.summary ?? null }];
    default:
      return [raw(line)];
  }
}

function normalizeStreamEvent(line) {
  const event = line.event;
  if (!event || event.type !== 'content_block_delta') return [];
  const delta = event.delta;
  if (!delta) return [];
  if (delta.type === 'text_delta' && typeof delta.text === 'string' && delta.text !== '') {
    return [{ type: 'text_delta', text: delta.text, index: event.index ?? 0 }];
  }
  return [];
}

function normalizeAssistant(line) {
  const message = line.message ?? {};
  const blocks = Array.isArray(message.content) ? message.content : [];
  const events = [];

  for (const block of blocks) {
    const base = {
      message_id: message.id ?? null,
      uuid: line.uuid ?? null,
      parent_tool_use_id: line.parent_tool_use_id ?? null,
    };
    if (block.type === 'text') {
      events.push({ type: 'text', text: block.text ?? '', ...base });
    } else if (block.type === 'thinking') {
      events.push({ type: 'thinking', text: block.thinking ?? '', ...base });
    } else if (block.type === 'tool_use') {
      events.push({ type: 'tool_use', id: block.id ?? null, name: block.name ?? 'tool', input: block.input ?? {}, ...base });
    }
  }

  return events;
}

function normalizeUser(line) {
  const message = line.message ?? {};
  const blocks = Array.isArray(message.content) ? message.content : [];
  const events = [];

  for (const block of blocks) {
    if (block.type !== 'tool_result') continue;
    events.push({
      type: 'tool_result',
      tool_use_id: block.tool_use_id ?? null,
      content: flattenContent(block.content),
      is_error: block.is_error === true,
      parent_tool_use_id: line.parent_tool_use_id ?? null,
    });
  }

  return events;
}

function normalizeResult(line) {
  // A result that the CLI started on its own (a background task waking the
  // session) is not the answer to the person's turn: keep it, do not flip state.
  if (line.origin) return [raw(line)];

  const modelUsage = line.modelUsage && typeof line.modelUsage === 'object' ? Object.values(line.modelUsage)[0] : null;

  return [{
    type: 'result',
    subtype: line.subtype ?? 'success',
    is_error: line.is_error === true,
    result: typeof line.result === 'string' ? line.result : '',
    session_id: line.session_id ?? null,
    duration_ms: line.duration_ms ?? null,
    num_turns: line.num_turns ?? null,
    total_cost_usd: line.total_cost_usd ?? null,
    usage: line.usage ?? null,
    context_window: modelUsage?.contextWindow ?? null,
    stop_reason: line.stop_reason ?? null,
  }];
}

function flattenContent(content) {
  if (typeof content === 'string') return content;
  if (Array.isArray(content)) {
    return content
      .map((part) => (part && part.type === 'text' ? part.text : part && part.type === 'image' ? '[image]' : ''))
      .join('\n');
  }
  if (content == null) return '';
  return JSON.stringify(content);
}

function raw(line) {
  let payload = JSON.stringify(line);
  if (payload.length > RAW_LIMIT) payload = payload.slice(0, RAW_LIMIT) + '…';
  return { type: 'raw', raw_type: line.type ?? null, subtype: line.subtype ?? null, payload };
}

/** Strip ANSI escapes the CLI may put in decision reasons. */
export function stripAnsi(text) {
  return String(text ?? '').replace(/\u001b\[[0-9;]*[A-Za-z]/g, '');
}
