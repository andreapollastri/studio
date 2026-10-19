import { chmodSync, rmSync, writeFileSync } from 'node:fs';
import { homedir, tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawn } from 'node:child_process';
import { EventEmitter } from 'node:events';
import { NdjsonParser } from './ndjson.js';
import { normalize, stripAnsi } from './normalize.js';
import { CallbackPoster } from './callback.js';
import { BridgeError } from './errors.js';
import { BROWSER_READ_TOOLS, BROWSER_SERVER, browserServer, normalizeBrowser, removeBrowserFiles } from './browser.js';
import { studioAgents, studioPrompt, writeAgentsFile } from './agents.js';

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
 *
 * Studio may also hand over, per turn, a preview to look at (`browser`: the
 * agent gets a headless browser signed in to it, see browser.js) and a sandbox
 * (`sandbox`: the conversation runs in that git worktree, see sandboxes.js).
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
  /** Background tasks (subagents, commands) started and not finished yet, by task id. */
  #background = new Set();
  #idleTimer = null;
  #spawn;
  #log;
  #sandboxes;
  #turn = null; // { permission_mode, model, effort, auth, account, apiKey, claudeDir, mcp, browser, sandbox, cwd }
  #stderr = ''; // the tail of what the current process said outside stream-json
  #usageRequest = null; // the request_id of the get_usage still unanswered
  #usageAskedAt = 0;

  constructor(key, config, { spawn: spawnImpl = spawn, log = () => {}, sandboxes = null } = {}) {
    super();
    this.key = String(key);
    this.#config = config;
    this.#spawn = spawnImpl;
    this.#sandboxes = sandboxes;
    this.#log = (message) => log(`[${this.key}] ${message}`);
  }

  /** The directory this conversation's Claude Code runs in. */
  get cwd() {
    return this.#turn?.cwd ?? this.#config.workspaceDir;
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
   * The process of the next turn, started before it is written (the person opened the chat or
   * began typing), so Enter does not wait for the CLI to boot, resume and connect its MCP servers.
   * The same process serves the turn when nothing changed meanwhile; unused, it ends like any idle one.
   */
  warm(turn) {
    if (this.#process && this.state !== 'idle') return this.snapshot();
    const started = this.#prepare(turn);
    // ready and waiting: it ends after the idle time unless a turn comes
    this.#armIdleTimer();
    return { ...this.snapshot(), warmed: started };
  }

  /** Check a turn's settings and have a process for them: true when one had to be started. */
  #prepare(turn) {
    if (!turn || typeof turn !== 'object') throw new BridgeError(422, 'a turn is required');
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
    const account = normalizeAccount(turn);
    const allowed = normalizeAllowedTools(turn.allowed_tools);
    // One turn at a time: the CLI would queue a second message and Studio would lose
    // track of which answer belongs to what. Interrupt first.
    if (this.#process && this.state !== 'idle') {
      throw new BridgeError(409, 'a turn is still running in this conversation: interrupt it first');
    }
    const mcp = normalizeMcpServers(turn.mcp_servers);
    // no browser on this server: the turn goes on without one rather than failing
    const browser = this.#config.browserCmd ? normalizeBrowser(turn.browser) : null;
    const sandbox = this.#sandbox(turn.sandbox);

    this.#clearIdleTimer();

    // A new callback target or a changed mode/model means a fresh process.
    const sameTarget = this.#poster && this.#turn
      && this.#turn.permission_mode === (turn.permission_mode || 'default')
      && this.#turn.model === (turn.model || null)
      && this.#turn.effort === (turn.effort || null)
      && this.#turn.auth === (turn.auth ?? undefined)
      && this.#turn.account === account.account
      && this.#turn.apiKey === account.apiKey
      && this.#turn.claudeDir === account.claudeDir
      && this.#turn.mcp === mcp.key
      && (this.#turn.browser?.url ?? null) === (browser?.url ?? null)
      && this.#turn.cwd === sandbox.cwd
      && this.#turn.allowed.join('\n') === allowed.join('\n');

    // a fresh pass for the browser on every turn: the process reads it when it next opens the browser
    if (browser) browserServer(this.key, browser, this.#config);

    if (!this.#process || !sameTarget) {
      this.#stopProcess();
      if (turn.session_id && !this.sessionId) this.sessionId = turn.session_id;
      this.#turn = { permission_mode: turn.permission_mode || 'default', model: turn.model || null, effort: turn.effort || null, auth: turn.auth ?? undefined, ...account, mcp: mcp.key, mcpServers: mcp.servers, browser, sandbox: sandbox.info, cwd: sandbox.cwd, allowed };
      this.#poster = new CallbackPoster({
        url: turn.callback.url,
        token: turn.callback.token,
        conversation: this.key,
        flushInterval: this.#config.flushInterval,
        log: this.#log,
      });
      this.#start();
      return true;
    }
    return false;
  }

  /**
   * @param {{text: string, session_id?: string|null, permission_mode?: string, model?: string|null, effort?: string|null, callback: {url: string, token: string}}} turn
   */
  sendTurn(turn) {
    if (!turn || typeof turn.text !== 'string' || turn.text.trim() === '') {
      throw new BridgeError(422, 'text is required');
    }
    this.#prepare(turn);

    this.state = 'running';
    this.#write({ type: 'user', message: { role: 'user', content: [{ type: 'text', text: turn.text }] } });
    this.emit('turn', turn.text);

    return this.snapshot();
  }

  /**
   * @param {Record<string, string>|null} answers AskUserQuestion: question text → the chosen label(s), comma
   *   separated, or the person's own words. Without them the CLI tells the agent "the user did not answer".
   */
  answerPermission(requestId, behavior, message = null, answers = null, rules = null) {
    const pending = this.#pending.get(requestId);
    if (!pending) throw new BridgeError(404, `no pending permission ${requestId}`);
    if (behavior !== 'allow' && behavior !== 'deny') throw new BridgeError(422, 'behavior must be allow or deny');
    if (answers !== null && (typeof answers !== 'object' || Array.isArray(answers) || Object.values(answers).some((a) => typeof a !== 'string'))) {
      throw new BridgeError(422, 'answers must map each question to a string');
    }

    // "Always allow": the rules apply to the rest of this process at once; Studio keeps them and
    // hands them over as --allowedTools to every later process
    const always = normalizeAllowedTools(rules).map(ruleValue);
    const response = behavior === 'allow'
      ? {
        behavior: 'allow',
        updatedInput: answers ? { ...pending.input, answers } : pending.input,
        ...(always.length ? { updatedPermissions: [{ type: 'addRules', rules: always, behavior: 'allow', destination: 'session' }] } : {}),
      }
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
    removeBrowserFiles(this.key);
    if (this.#poster) await this.#poster.drain();
  }

  /** Where this turn runs: the workspace, or the sandbox Studio named (it must exist). */
  #sandbox(name) {
    if (name === undefined || name === null || name === '') return { cwd: this.#config.workspaceDir, info: null };
    if (!this.#sandboxes) throw new BridgeError(422, 'this bridge has no sandboxes');
    let dir;
    try {
      dir = this.#sandboxes.resolve(name);
    } catch (error) {
      throw new BridgeError(error.status === 404 ? 409 : 422, error.message);
    }
    return { cwd: dir, info: { name: String(name), dir, branch: this.#sandboxes.branchOf(name) } };
  }

  // ---------------------------------------------------------------- process

  /**
   * Studio's MCP servers go to Claude Code in a file only this user can read:
   * a bearer token on the command line would show in the process list.
   */
  #writeMcpConfig() {
    const file = join(tmpdir(), `studio-mcp-${this.key.replace(/[^A-Za-z0-9_-]/g, '_')}.json`);
    const servers = { ...(this.#turn.mcpServers ?? {}) };
    // Studio's own browser: a project server of the same name gives way to it
    if (this.#turn.browser) servers[BROWSER_SERVER] = browserServer(this.key, this.#turn.browser, this.#config);
    if (Object.keys(servers).length === 0) {
      rmSync(file, { force: true });
      return null;
    }
    writeFileSync(file, JSON.stringify({ mcpServers: servers }), { mode: 0o600 });
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
    if (this.#config.studioAgents !== false) {
      const context = { browser: this.#turn.browser, sandbox: this.#turn.sandbox };
      args.push('--agents', writeAgentsFile(this.key, studioAgents(context)));
      args.push('--append-system-prompt', studioPrompt(context));
    }
    // Looking at the preview never asks, in any mode; acting in it (clicks, forms) is part of the work in
    // the modes that let edits run, and asks like any other tool in the others.
    const allowedTools = [...this.#turn.allowed];
    if (this.#turn.browser) {
      const free = ['acceptEdits', 'auto', 'bypassPermissions'].includes(this.#turn.permission_mode);
      allowedTools.push(...(free ? [`mcp__${BROWSER_SERVER}`] : BROWSER_READ_TOOLS));
    }
    // what Studio lets run without asking (the role's list, the person's "Always allow"): one
    // argument per rule, since a rule may hold a comma; plan mode still changes nothing
    if (allowedTools.length && this.#turn.permission_mode !== 'plan') args.push('--allowedTools', ...allowedTools);

    this.#log(`spawn ${this.#config.claudeBin} ${args.filter((arg) => !arg.includes('\n')).join(' ')}`);

    const child = this.#spawn(this.#config.claudeBin, args, {
      cwd: this.#turn.cwd ?? this.#config.workspaceDir,
      env: claudeEnv(process.env, this.#turn.auth, { apiKey: this.#turn.apiKey, claudeDir: this.#turn.claudeDir }),
      stdio: ['pipe', 'pipe', 'pipe'],
    });

    this.#process = child;
    this.#stderr = '';
    const parser = new NdjsonParser(
      (line) => this.#onLine(line),
      (text) => {
        this.#log(`malformed line skipped (${text.length} bytes)`);
        this.#keepStderr(text + '\n');
      },
    );
    this.#parser = parser;

    child.stdout.setEncoding('utf8');
    // a process this session let go (idle, interrupted, replaced) may still flush a last line, an
    // answer to the usage question for one: it belongs to no turn any more, not to the next process's
    child.stdout.on('data', (chunk) => {
      if (this.#process === child) parser.push(chunk);
    });
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
    // the CLI is at work on its own (a background task finished and it writes the rest): the idle time
    // counts from its last word, not from the end of the person's turn
    if (this.state === 'idle' && this.#idleTimer) this.#armIdleTimer();

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
      // what runs in the background keeps the process alive past the idle time (see #armIdleTimer)
      if (event.type === 'task' && event.task_id) {
        if (event.phase === 'started') this.#background.add(event.task_id);
        else this.#background.delete(event.task_id);
      }
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
      // Other control questions are the CLI talking to a client we are not: keep them, and
      // say we do not handle them, so the CLI goes on instead of waiting for ever.
      this.#log(`control request not handled: ${request.subtype ?? 'unknown'}`);
      this.#emitEvents(normalize({ ...line, type: 'control_request_other' }));
      if (requestId) {
        this.#write({ type: 'control_response', response: { subtype: 'error', request_id: requestId, error: `Studio does not handle ${request.subtype ?? 'this request'}` } });
      }
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
      suggestions: suggestedRules(request.permission_suggestions),
    }]);
  }

  #onExit(code, signal, error) {
    this.#process = null;
    this.#parser?.end();
    this.#parser = null;

    const wasBusy = this.state !== 'idle';
    this.state = 'idle';
    this.#pending.clear();
    this.#background.clear();
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
      // subagents or commands still at work in the background: their results would be lost
      if (this.#background.size > 0) {
        this.#armIdleTimer();
        return;
      }
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
    'Claude is not signed in, or its credentials are no longer valid. Sign in again from Settings › Accounts, then resend the message.'],
  [/credit balance is too low/i,
    'The Anthropic account has run out of credit. Top it up or switch to your Claude plan in Settings › Accounts.'],
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

export { BridgeError };

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
 * Which of the person's Claude accounts a turn runs on, besides `auth`:
 * `account` names it (a change means a new process, resumed), `api_key` is that
 * account's key (kept in memory only, never logged or sent back), `claude_dir`
 * the config directory in the home that holds that subscription's login.
 * Error messages never repeat what was sent.
 */
export function normalizeAccount(turn) {
  const given = (value) => (value === undefined || value === null || value === '' ? null : value);
  const account = given(turn.account);
  const apiKey = given(turn.api_key);
  const claudeDir = given(turn.claude_dir);
  if (account !== null && (typeof account !== 'string' || !/^[A-Za-z0-9-]{1,64}$/.test(account))) {
    throw new BridgeError(422, 'invalid account');
  }
  if (apiKey !== null && (typeof apiKey !== 'string' || !apiKey.startsWith('sk-ant-') || apiKey.length > 512 || /\s/.test(apiKey))) {
    throw new BridgeError(422, 'invalid api_key');
  }
  if (claudeDir !== null && (typeof claudeDir !== 'string' || !CLAUDE_DIR.test(claudeDir))) {
    throw new BridgeError(422, 'invalid claude_dir');
  }
  return { account, apiKey, claudeDir };
}

const CLAUDE_DIR = /^\.claude(-[a-z0-9]{1,40})?$/;

/**
 * The environment of one Claude Code process. With an account chosen by Studio,
 * no key or token is left over from the bridge's own environment: "subscription"
 * uses the person's login in their home (in `claudeDir` when Studio names one of
 * the person's several), "api_key" hands over the key Studio sent with the turn,
 * or else the one Studio keeps as STUDIO_ANTHROPIC_API_KEY.
 * The agent never sees STUDIO_ANTHROPIC_API_KEY itself. Without a choice (an older
 * Studio, local development) the environment stays as it is.
 */
export function claudeEnv(base, auth, { apiKey = null, claudeDir = null, home = null } = {}) {
  const env = { ...base, CLAUDE_CODE_ENTRYPOINT: 'studio-bridge' };
  const key = env.STUDIO_ANTHROPIC_API_KEY;
  delete env.STUDIO_ANTHROPIC_API_KEY;
  if (auth === undefined) return env;

  delete env.ANTHROPIC_API_KEY;
  delete env.CLAUDE_CODE_OAUTH_TOKEN;
  if (auth === 'api_key' && (apiKey || key)) env.ANTHROPIC_API_KEY = apiKey || key;
  if (auth === 'subscription' && claudeDir && CLAUDE_DIR.test(claudeDir)) {
    env.CLAUDE_CONFIG_DIR = join(home ?? base.HOME ?? homedir(), claudeDir);
  }
  return env;
}

/** Claude Code's rule syntax, Tool or Tool(specifier), one per argument of --allowedTools. */
const RULE = /^[A-Za-z][\w-]*(\([^()\n]+\))?$/;

export function normalizeAllowedTools(value) {
  if (value == null) return [];
  if (!Array.isArray(value) || value.some((rule) => typeof rule !== 'string' || rule.length > 500 || !RULE.test(rule))) {
    throw new BridgeError(422, 'allowed_tools must be a list of rules like Bash(npm run build:*)');
  }
  return [...new Set(value)];
}

/** Bash(npm run build:*) → { toolName: 'Bash', ruleContent: 'npm run build:*' }, as the CLI's permission updates have it. */
function ruleValue(rule) {
  const open = rule.indexOf('(');
  return open === -1 ? { toolName: rule } : { toolName: rule.slice(0, open), ruleContent: rule.slice(open + 1, -1) };
}

/** The allow rules the CLI suggests with a request ("don't ask again for …"), back in rule syntax. */
export function suggestedRules(updates) {
  if (!Array.isArray(updates)) return [];
  const rules = updates
    .filter((update) => update?.type === 'addRules' && update.behavior === 'allow' && Array.isArray(update.rules))
    .flatMap((update) => update.rules)
    .filter((rule) => typeof rule?.toolName === 'string')
    .map((rule) => (typeof rule.ruleContent === 'string' && rule.ruleContent !== '' ? `${rule.toolName}(${rule.ruleContent})` : rule.toolName))
    .filter((rule) => rule.length <= 500 && RULE.test(rule));
  return [...new Set(rules)];
}
