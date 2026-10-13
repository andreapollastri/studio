import { chmodSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawn } from 'node:child_process';
import { EventEmitter } from 'node:events';
import { NdjsonParser } from './ndjson.js';
import { normalize, stripAnsi } from './normalize.js';
import { CallbackPoster } from './callback.js';

/**
 * One Claude Code process per conversation.
 *
 * The CLI is started with `-p` and stream-json on both ends; stdin stays open
 * so the next turn is one more line. Between turns the process idles for a
 * while, then is killed: the next turn starts a new process with `--resume`
 * and the session id the result carried.
 *
 * `--permission-prompt-tool stdio` makes the CLI ask before a tool it may
 * not run outright; the question is forwarded to Studio as
 * `permission_request` and the turn waits for `answerPermission`.
 *
 * After a turn the CLI is asked what `/usage` shows (`get_usage`): the plan's
 * windows as percentages, read by Claude Code itself with its own credentials.
 * They reach Studio as `plan_usage`; an API key, or a CLI that does not know
 * the question, answers nothing worth forwarding.
 */
export class Session extends EventEmitter {
  key;
  state = 'idle'; // idle | running | waiting_permission
  sessionId = null;
  #config;
  #process = null;
  #parser = null;
  #poster = null;
  #pending = new Map(); // request_id -> { tool_name, input }
  #idleTimer = null;
  #spawn;
  #log;
  #turn = null; // { permission_mode, model }
  #stderr = ''; // the tail of what the current process said outside stream-json
  #usageRequest = null; // the request_id of the get_usage still unanswered
  #usageAskedAt = 0;

  constructor(key, config, { spawn: spawnImpl = spawn, log = () => {} } = {}) {
    super();
    this.key = String(key);
    this.#config = config;
    this.#spawn = spawnImpl;
    this.#log = (message) => log(`[${this.key}] ${message}`);
  }

  snapshot() {
    return {
      key: this.key,
      state: this.state,
      session_id: this.sessionId,
      alive: this.#process !== null,
      pending_permissions: [...this.#pending.keys()],
    };
  }

  /**
   * @param {{text: string, session_id?: string|null, permission_mode?: string, model?: string|null, effort?: string|null, callback: {url: string, token: string}}} turn
   */
  sendTurn(turn) {
    if (!turn || typeof turn.text !== 'string' || turn.text.trim() === '') {
      throw new BridgeError(422, 'text is required');
    }
    if (!turn.callback || !turn.callback.url || !turn.callback.token) {
      throw new BridgeError(422, 'callback.url and callback.token are required');
    }
    if (turn.permission_mode && !['default', 'acceptEdits', 'auto', 'plan', 'bypassPermissions'].includes(turn.permission_mode)) {
      throw new BridgeError(422, 'unknown permission_mode');
    }
    if (turn.effort && !['low', 'medium', 'high', 'max'].includes(turn.effort)) {
      throw new BridgeError(422, 'unknown effort');
    }
    if (turn.auth != null && !['subscription', 'api_key'].includes(turn.auth)) {
      throw new BridgeError(422, 'unknown auth');
    }
    // One turn at a time: the CLI would queue a second message and Studio would lose
    // track of which answer belongs to what. Interrupt first.
    if (this.#process && this.state !== 'idle') {
      throw new BridgeError(409, 'a turn is still running in this conversation: interrupt it first');
    }
    const mcp = normalizeMcpServers(turn.mcp_servers);

    this.#clearIdleTimer();

    // A new callback target or a changed mode/model means a fresh process.
    const sameTarget = this.#poster && this.#turn
      && this.#turn.permission_mode === (turn.permission_mode || 'default')
      && this.#turn.model === (turn.model || null)
      && this.#turn.effort === (turn.effort || null)
      && this.#turn.auth === (turn.auth ?? undefined)
      && this.#turn.mcp === mcp.key;

    if (!this.#process || !sameTarget) {
      this.#stopProcess();
      if (turn.session_id && !this.sessionId) this.sessionId = turn.session_id;
      this.#turn = { permission_mode: turn.permission_mode || 'default', model: turn.model || null, effort: turn.effort || null, auth: turn.auth ?? undefined, mcp: mcp.key, mcpServers: mcp.servers };
      this.#poster = new CallbackPoster({
        url: turn.callback.url,
        token: turn.callback.token,
        conversation: this.key,
        flushInterval: this.#config.flushInterval,
        log: this.#log,
      });
      this.#start();
    }

    this.state = 'running';
    this.#write({ type: 'user', message: { role: 'user', content: [{ type: 'text', text: turn.text }] } });
    this.emit('turn', turn.text);

    return this.snapshot();
  }

  /**
   * @param {Record<string, string>|null} answers AskUserQuestion: question text → the chosen label(s), comma
   *   separated, or the person's own words. Without them the CLI tells the agent "the user did not answer".
   */
  answerPermission(requestId, behavior, message = null, answers = null) {
    const pending = this.#pending.get(requestId);
    if (!pending) throw new BridgeError(404, `no pending permission ${requestId}`);
    if (behavior !== 'allow' && behavior !== 'deny') throw new BridgeError(422, 'behavior must be allow or deny');
    if (answers !== null && (typeof answers !== 'object' || Array.isArray(answers) || Object.values(answers).some((a) => typeof a !== 'string'))) {
      throw new BridgeError(422, 'answers must map each question to a string');
    }

    const response = behavior === 'allow'
      ? { behavior: 'allow', updatedInput: answers ? { ...pending.input, answers } : pending.input }
      : { behavior: 'deny', message: message || 'The person declined this action.', interrupt: false };

    this.#write({ type: 'control_response', response: { request_id: requestId, subtype: 'success', response } });
    this.#pending.delete(requestId);

    if (this.#pending.size === 0) this.state = 'running';
    this.#emitEvents([{ type: 'permission_resolved', request_id: requestId, behavior }]);

    return this.snapshot();
  }

  interrupt() {
    if (!this.#process) return this.snapshot();
    const wasBusy = this.state !== 'idle';
    this.#stopProcess();
    if (wasBusy) {
      this.#emitEvents([
        { type: 'error', code: 'interrupted', message: 'Stopped on request.' },
        { type: 'result', subtype: 'interrupted', is_error: false, result: '', session_id: this.sessionId },
      ]);
    }
    this.state = 'idle';
    this.#pending.clear();
    return this.snapshot();
  }

  async close() {
    this.#clearIdleTimer();
    this.#stopProcess();
    if (this.#poster) await this.#poster.drain();
  }

  // ---------------------------------------------------------------- process

  /**
   * Studio's MCP servers go to Claude Code in a file only this user can read:
   * a bearer token on the command line would show in the process list.
   */
  #writeMcpConfig() {
    const file = join(tmpdir(), `studio-mcp-${this.key.replace(/[^A-Za-z0-9_-]/g, '_')}.json`);
    if (!this.#turn.mcpServers) {
      rmSync(file, { force: true });
      return null;
    }
    writeFileSync(file, JSON.stringify({ mcpServers: this.#turn.mcpServers }), { mode: 0o600 });
    chmodSync(file, 0o600);
    return file;
  }

  #start() {
    const args = [
      '-p',
      '--output-format', 'stream-json',
      '--input-format', 'stream-json',
      '--include-partial-messages',
      '--verbose',
      '--permission-mode', this.#turn.permission_mode,
      '--permission-prompt-tool', 'stdio',
    ];
    if (this.#turn.model) args.push('--model', this.#turn.model);
    if (this.#turn.effort) args.push('--effort', this.#turn.effort);
    if (this.sessionId) args.push('--resume', this.sessionId);
    const mcpFile = this.#writeMcpConfig();
    if (mcpFile) args.push('--mcp-config', mcpFile);

    this.#log(`spawn ${this.#config.claudeBin} ${args.join(' ')}`);

    const child = this.#spawn(this.#config.claudeBin, args, {
      cwd: this.#config.workspaceDir,
      env: claudeEnv(process.env, this.#turn.auth),
      stdio: ['pipe', 'pipe', 'pipe'],
    });

    this.#process = child;
    this.#stderr = '';
    this.#parser = new NdjsonParser(
      (line) => this.#onLine(line),
      (text) => {
        this.#log(`malformed line skipped (${text.length} bytes)`);
        this.#keepStderr(text + '\n');
      },
    );

    child.stdout.setEncoding('utf8');
    child.stdout.on('data', (chunk) => this.#parser.push(chunk));
    child.stderr.setEncoding('utf8');
    child.stderr.on('data', (chunk) => {
      this.#log(`stderr: ${chunk.trim().slice(0, 500)}`);
      this.#keepStderr(chunk);
    });
    // a line written as the CLI exits (the usage question after a result) must not take the bridge down
    child.stdin.on('error', (error) => this.#log(`stdin: ${error.message}`));
    child.on('error', (error) => this.#onExit(null, null, error));
    child.on('close', (code, signal) => this.#onExit(code, signal, null));
  }

  #keepStderr(text) {
    this.#stderr = (this.#stderr + text).slice(-STDERR_TAIL);
  }

  #stopProcess() {
    this.#clearIdleTimer();
    const child = this.#process;
    if (!child) return;
    this.#process = null;
    this.#parser = null;
    child.removeAllListeners('close');
    child.removeAllListeners('error');
    child.on('error', () => {});
    try { child.stdin.end(); } catch {}
    try { child.kill('SIGTERM'); } catch {}
  }

  #write(object) {
    if (!this.#process) throw new BridgeError(409, 'no running process');
    this.#process.stdin.write(JSON.stringify(object) + '\n');
  }

  #onLine(line) {
    if (line.type === 'control_request') {
      this.#onControlRequest(line);
      return;
    }

    if (line.type === 'control_response' && this.#usageRequest && line.response?.request_id === this.#usageRequest) {
      this.#usageRequest = null;
      const usage = line.response.subtype === 'success' ? line.response.response : null;
      if (usage?.rate_limits) {
        this.#emitEvents([{ type: 'plan_usage', subscription_type: usage.subscription_type ?? null, rate_limits: usage.rate_limits }]);
      }
      return;
    }

    const events = normalize(line);

    for (const event of events) {
      if (event.type === 'init' && event.session_id) this.sessionId = event.session_id;
      if (event.type === 'result') {
        if (event.session_id) this.sessionId = event.session_id;
        this.state = 'idle';
        this.#pending.clear();
        this.#armIdleTimer();
      }
    }

    if (events.length) this.#emitEvents(events);
    if (events.some((event) => event.type === 'result')) this.#askUsage();
  }

  /** At most once a minute per conversation: the CLI asks claude.ai each time. */
  #askUsage() {
    if (!this.#process || this.#turn?.auth === 'api_key' || Date.now() - this.#usageAskedAt < 60_000) return;
    this.#usageAskedAt = Date.now();
    this.#usageRequest = `studio_usage_${this.#usageAskedAt}`;
    this.#write({ type: 'control_request', request_id: this.#usageRequest, request: { subtype: 'get_usage', skip_behaviors: true } });
  }

  #onControlRequest(line) {
    const request = line.request ?? {};
    const requestId = line.request_id;

    if (request.subtype !== 'can_use_tool' || !requestId) {
      // Other control questions are the CLI talking to a client we are not;
      // keep them visible without pretending to understand them.
      this.#emitEvents(normalize({ ...line, type: 'control_request_other' }));
      return;
    }

    this.#pending.set(requestId, { tool_name: request.tool_name, input: request.input ?? {} });
    this.state = 'waiting_permission';

    this.#emitEvents([{
      type: 'permission_request',
      request_id: requestId,
      tool_name: request.tool_name ?? 'tool',
      input: request.input ?? {},
      description: stripAnsi(request.description ?? request.decision_reason ?? ''),
      tool_use_id: request.tool_use_id ?? null,
    }]);
  }

  #onExit(code, signal, error) {
    this.#process = null;
    this.#parser?.end();
    this.#parser = null;

    const wasBusy = this.state !== 'idle';
    this.state = 'idle';
    this.#pending.clear();
    this.#clearIdleTimer();

    if (wasBusy) {
      const message = explainExit({ code, signal, error, stderr: this.#stderr });
      this.#log(`claude ended the turn early (code ${code}, signal ${signal}${error ? `, ${error.message}` : ''}): ${message}`);
      this.#emitEvents([
        { type: 'error', code: 'process_exit', message },
        { type: 'result', subtype: 'error_during_execution', is_error: true, result: message, session_id: this.sessionId },
      ]);
    }
  }

  #armIdleTimer() {
    this.#clearIdleTimer();
    this.#idleTimer = setTimeout(() => {
      this.#log('idle: releasing the process (next turn resumes the session)');
      this.#stopProcess();
    }, this.#config.idleTtl * 1000);
    this.#idleTimer.unref?.();
  }

  #clearIdleTimer() {
    if (this.#idleTimer) clearTimeout(this.#idleTimer);
    this.#idleTimer = null;
  }

  #emitEvents(events) {
    this.emit('events', events);
    this.#poster?.push(events);
  }
}

const STDERR_TAIL = 4000;

/**
 * What the CLI says when it gives up is meant for a terminal: the known cases
 * become a sentence the person can act on, the rest keep the CLI's last words.
 */
const EXIT_REASONS = [
  [/not logged in|please run \/login|invalid api key|authentication_error|oauth token (has )?(expired|revoked)|invalid bearer token/i,
    'Claude is not signed in, or its credentials are no longer valid. Sign in again from Settings › Connections, then resend the message.'],
  [/credit balance is too low/i,
    'The Anthropic account has run out of credit. Top it up or switch to your Claude plan in Settings › Connections.'],
  [/usage limit|limit reached|rate[ _]limit/i,
    'The Claude usage limit has been reached. Try again once it resets.'],
  [/overloaded/i,
    'The Claude API is overloaded right now. Try again in a moment.'],
  [/no conversation found with session id/i,
    'The previous Claude session for this conversation no longer exists in the workspace, so it could not be resumed.'],
  [/cannot be used with root\/sudo/i,
    '"Bypass permissions" cannot be used while Claude runs as root. Pick another permission mode.'],
  [/model.*(not found|not available|invalid|does not exist)|(invalid|unknown) model/i,
    'The selected model is not available for this account. Pick another model and resend the message.'],
  [/ENOSPC|no space left on device/i,
    'The workspace disk is full, so Claude could not continue.'],
];

export function explainExit({ code = null, signal = null, error = null, stderr = '' } = {}) {
  if (error) {
    return error.code === 'ENOENT'
      ? 'Claude Code is not installed in this workspace.'
      : `Claude Code could not start: ${error.message}`;
  }

  const said = stripAnsi(stderr).trim();
  for (const [pattern, sentence] of EXIT_REASONS) {
    if (pattern.test(said)) return sentence;
  }

  if (signal === 'SIGKILL') return 'Claude Code was killed before finishing the turn, most likely because the workspace ran out of memory.';
  if (signal) return `Claude Code was stopped (${signal}) before finishing the turn.`;

  const lastWords = said.split('\n').map((l) => l.trim()).filter(Boolean).slice(-3).join(' ').slice(-500);
  const base = `Claude Code stopped unexpectedly (exit code ${code}) before finishing the turn.`;
  return lastWords ? `${base} It said: ${lastWords}` : base;
}

export class BridgeError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

/**
 * Only remote (HTTP) MCP servers come from Studio: a stdio server would be a
 * command to run in the workspace, and that is not Studio's to decide.
 * Returns the servers and a key that changes when they do.
 */
export function normalizeMcpServers(input) {
  if (input === undefined || input === null) return { servers: null, key: '' };
  if (typeof input !== 'object' || Array.isArray(input)) throw new BridgeError(422, 'mcp_servers must be an object');
  const servers = {};
  for (const [name, server] of Object.entries(input)) {
    if (!/^[A-Za-z0-9_-]{1,40}$/.test(name)) throw new BridgeError(422, `invalid MCP server name ${name}`);
    if (!server || server.type !== 'http' || typeof server.url !== 'string' || !/^https?:\/\//.test(server.url)) {
      throw new BridgeError(422, `MCP server ${name} must be {type: "http", url}`);
    }
    const headers = {};
    for (const [header, value] of Object.entries(server.headers || {})) {
      if (typeof value !== 'string') throw new BridgeError(422, `MCP header ${header} must be a string`);
      headers[header] = value;
    }
    servers[name] = { type: 'http', url: server.url, headers };
  }
  const names = Object.keys(servers).sort();
  if (names.length === 0) return { servers: null, key: '' };
  return { servers, key: JSON.stringify(names.map((n) => [n, servers[n]])) };
}

/**
 * The environment of one Claude Code process. With an account chosen by Studio,
 * "subscription" leaves no key around, so the CLI uses the person's own login in
 * their home; "api_key" hands over the key Studio keeps as STUDIO_ANTHROPIC_API_KEY.
 * The agent never sees STUDIO_ANTHROPIC_API_KEY itself. Without a choice (an older
 * Studio, local development) the environment stays as it is.
 */
export function claudeEnv(base, auth) {
  const env = { ...base, CLAUDE_CODE_ENTRYPOINT: 'studio-bridge' };
  const key = env.STUDIO_ANTHROPIC_API_KEY;
  delete env.STUDIO_ANTHROPIC_API_KEY;
  if (auth === undefined) return env;

  delete env.ANTHROPIC_API_KEY;
  delete env.CLAUDE_CODE_OAUTH_TOKEN;
  if (auth === 'api_key' && key) env.ANTHROPIC_API_KEY = key;
  return env;
}
