import { spawn as spawnProcess } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { BridgeError } from './session.js';
import { CURSOR_DIR, cursorEnv } from './cursor.js';

/** Claude Code aliases that always resolve to the account's latest on that slot. */
const CLAUDE_ALIASES = [
  { id: 'fable', label: 'Fable (latest)', hint: 'Hardest reasoning and long agentic work' },
  { id: 'opus', label: 'Opus (latest)', hint: 'Always the newest Opus on your provider' },
  { id: 'sonnet', label: 'Sonnet (latest)', hint: 'Always the newest Sonnet on your provider' },
  { id: 'haiku', label: 'Haiku (latest)', hint: 'Always the newest Haiku on your provider' },
];

/**
 * Models the chat can offer for the active agent: Cursor runs `agent models` for
 * the signed-in account; Claude uses the Anthropic Models API when there is a
 * key, else a Claude Code gateway cache if present, else the built-in aliases.
 *
 * @param {{ agent: string, cursorDir?: string|null, apiKey?: string|null, claudeDir?: string|null }} body
 * @param {{ cursorBin?: string, home?: string|null, spawn?: typeof spawnProcess, fetch?: typeof fetch }} [options]
 * @returns {Promise<list<{id: string, label: string, hint?: string}>>}
 */
export async function listModels(body, options = {}) {
  const agent = body?.agent === 'cursor' ? 'cursor' : 'claude';
  const models = agent === 'cursor'
    ? await listCursorModels(body, options)
    : await listClaudeModels(body, options);

  return withDefault(agent, models);
}

async function listCursorModels(body, { cursorBin = 'agent', home = null, spawn = spawnProcess } = {}) {
  const cursorDir = typeof body?.cursorDir === 'string' && CURSOR_DIR.test(body.cursorDir) ? body.cursorDir : null;
  const env = cursorEnv(process.env, { cursorDir, home: home || process.env.HOME || homedir() });
  let stdout = '';

  try {
    stdout = await runOnce(spawn, cursorBin, ['models'], { env, timeoutMs: 20_000 });
  } catch (error) {
    if (error instanceof BridgeError) throw error;
    throw new BridgeError(502, error?.code === 'ENOENT'
      ? 'Cursor Agent is not installed in the workspace.'
      : (error?.message || 'Cursor models could not be listed.'));
  }

  return parseCursorModels(stdout);
}

/**
 * Cursor prints one model per line as `id - description` (see `agent models`).
 * @param {string} stdout
 * @returns {list<{id: string, label: string, hint?: string}>}
 */
export function parseCursorModels(stdout) {
  const out = [];
  const seen = new Set();

  for (const raw of String(stdout || '').split(/\r?\n/)) {
    const line = raw.trim();
    if (!line || !line.includes(' - ')) continue;
    const sep = line.indexOf(' - ');
    const id = line.slice(0, sep).trim();
    if (!id || /\s/.test(id) || seen.has(id)) continue;
    seen.add(id);
    const hint = line.slice(sep + 3).trim();
    out.push({ id, label: labelForId(id), ...(hint ? { hint } : {}) });
  }

  return out;
}

async function listClaudeModels(body, { home = null, fetch: fetchImpl = globalThis.fetch } = {}) {
  const apiKey = typeof body?.apiKey === 'string' && body.apiKey !== '' ? body.apiKey : null;
  if (apiKey) {
    const fromApi = await fetchAnthropicModels(apiKey, fetchImpl);
    if (fromApi.length > 0) return [...CLAUDE_ALIASES, ...fromApi];
  }

  const fromCache = await readClaudeGatewayCache(body?.claudeDir, home);
  if (fromCache.length > 0) return [...CLAUDE_ALIASES, ...fromCache];

  return [...CLAUDE_ALIASES];
}

async function fetchAnthropicModels(apiKey, fetchImpl) {
  if (typeof fetchImpl !== 'function') return [];

  let response;
  try {
    response = await fetchImpl('https://api.anthropic.com/v1/models?limit=1000&lifecycle=active', {
      headers: {
        'x-api-key': apiKey,
        'anthropic-version': '2023-06-01',
        accept: 'application/json',
      },
      signal: AbortSignal.timeout(8_000),
    });
  } catch {
    return [];
  }

  if (!response?.ok) return [];

  let payload;
  try {
    payload = await response.json();
  } catch {
    return [];
  }

  return parseAnthropicModels(payload);
}

/**
 * @param {unknown} payload
 * @returns {list<{id: string, label: string, hint?: string}>}
 */
export function parseAnthropicModels(payload) {
  const rows = Array.isArray(payload?.data) ? payload.data : [];
  const out = [];
  const seen = new Set();

  for (const row of rows) {
    const id = typeof row?.id === 'string' ? row.id.trim() : '';
    if (!id || seen.has(id) || !isClaudeCodingModel(id)) continue;
    seen.add(id);
    const label = typeof row.display_name === 'string' && row.display_name.trim() !== ''
      ? row.display_name.trim()
      : labelForId(id);
    const hint = typeof row.description === 'string' && row.description.trim() !== ''
      ? row.description.trim().split(/\r?\n/)[0]
      : undefined;
    out.push({ id, label, ...(hint ? { hint } : {}) });
  }

  return out;
}

async function readClaudeGatewayCache(claudeDir, home) {
  const root = home || process.env.HOME || homedir();
  const folder = typeof claudeDir === 'string' && /^\.claude(-[a-z0-9]{1,40})?$/.test(claudeDir)
    ? join(root, claudeDir)
    : (process.env.CLAUDE_CONFIG_DIR || join(root, '.claude'));

  try {
    const raw = await readFile(join(folder, 'cache', 'gateway-models.json'), 'utf8');
    const payload = JSON.parse(raw);
    return parseAnthropicModels(payload?.models ? { data: payload.models } : payload);
  } catch {
    return [];
  }
}

function isClaudeCodingModel(id) {
  if (!id.startsWith('claude-')) return false;
  // skip embedding / non-chat ids if Anthropic ever lists them under the same prefix
  if (/embed|rerank|classifier/i.test(id)) return false;
  return true;
}

function withDefault(agent, models) {
  const empty = agent === 'cursor'
    ? { id: '', label: 'Auto' }
    : { id: '', label: 'Default' };
  const seen = new Set(['']);
  const rest = [];

  for (const model of models) {
    const id = typeof model?.id === 'string' ? model.id : '';
    if (id === '' || seen.has(id)) continue;
    seen.add(id);
    rest.push({
      id,
      label: typeof model.label === 'string' && model.label !== '' ? model.label : labelForId(id),
      ...(typeof model.hint === 'string' && model.hint !== '' ? { hint: model.hint } : {}),
    });
  }

  return [empty, ...rest];
}

/** Human label when the provider did not send one. */
export function labelForId(id) {
  if (id === 'opus' || id === 'sonnet' || id === 'haiku' || id === 'fable' || id === 'best') {
    return `${id.charAt(0).toUpperCase()}${id.slice(1)} (latest)`;
  }

  const cursor = id.match(/^(composer|grok|gpt|gemini|kimi|muse|glm|deepseek)[-.](.+)$/i);
  if (cursor) {
    return `${cursor[1].charAt(0).toUpperCase()}${cursor[1].slice(1)} ${cursor[2].replace(/-/g, ' ')}`;
  }

  const claude = id.match(/^claude-([a-z]+)-(\d+)(?:-(\d{1,2}))?(?:-\d{8})?(?:-fast)?$/i);
  if (claude) {
    return `${claude[1].charAt(0).toUpperCase()}${claude[1].slice(1)} ${claude[2]}${claude[3] ? `.${claude[3]}` : ''}`;
  }

  return id;
}

function runOnce(spawn, bin, args, { env, timeoutMs }) {
  return new Promise((resolve, reject) => {
    const child = spawn(bin, args, { env, stdio: ['ignore', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';
    const timer = setTimeout(() => {
      child.kill('SIGTERM');
      reject(new BridgeError(504, 'Listing models took too long.'));
    }, timeoutMs);
    child.stdout.setEncoding?.('utf8');
    child.stderr.setEncoding?.('utf8');
    child.stdout.on('data', (chunk) => { stdout += String(chunk); });
    child.stderr.on('data', (chunk) => { stderr += String(chunk); });
    child.on('error', (error) => {
      clearTimeout(timer);
      reject(error);
    });
    child.on('close', (code) => {
      clearTimeout(timer);
      if (code === 0) return resolve(stdout);
      const said = (stderr || stdout).trim().split('\n').slice(-3).join(' ');
      reject(new BridgeError(502, `Models could not be listed${said ? `: ${said}` : ''}.`));
    });
  });
}
