import { spawn as spawnProcess } from 'node:child_process';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { StringDecoder } from 'node:string_decoder';
import { fileURLToPath } from 'node:url';

const terminalPy = fileURLToPath(new URL('../../bridge/src/terminal.py', import.meta.url));

/**
 * A person signing in to their own subscription: the official CLI
 * (`claude auth login`, or Cursor's `agent login`), run by the privileged
 * helper as the person, in their home, on a pseudo-terminal. The gateway only
 * relays keystrokes and screen, like any terminal: the login lands in the
 * person's folder for that account and nothing of it is kept here or in Studio.
 *
 * Messages are the SSH session's: data and resize in; status, data, exit,
 * error and closed out.
 */

const PROVIDERS = {
  'claude-login': {
    dir: /^\.claude(-[a-z0-9]{1,40})?$/,
    fallback: '.claude',
    place: 'This Claude account has no place on the server.',
    command: (config) => config.loginCommand,
    label: 'claude',
  },
  'cursor-login': {
    dir: /^\.cursor(-[a-z0-9]{1,40})?$/,
    fallback: '.cursor',
    place: 'This Cursor account has no place on the server.',
    command: (config) => config.cursorLoginCommand,
    label: 'cursor',
  },
};
export class LoginSession {
  #ws;
  #target;
  #config;
  #size;
  #spawn;
  #child = null;
  #closed = false;
  #timer = null;
  #log;

  constructor(ws, target, config, { size, log = () => {}, spawn = spawnProcess } = {}) {
    this.#ws = ws;
    this.#target = target;
    this.#config = config;
    this.#size = size ?? { cols: 100, rows: 30 };
    this.#log = log;
    this.#spawn = spawn;

    ws.on('message', (raw) => this.#onMessage(raw));
    ws.on('close', () => this.close());
    ws.on('error', () => this.close());
  }

  start() {
    if (this.#target.local && this.#target.kind === 'cursor-login') {
      this.#startLocal();
      return;
    }

    const handle = String(this.#target.handle ?? '');
    if (!/^[a-z0-9][a-z0-9-]{0,27}$/.test(handle)) {
      this.#send({ type: 'error', message: 'This account has no home on the server yet.' });
      return this.close();
    }

    const provider = PROVIDERS[this.#target.kind] ?? PROVIDERS['claude-login'];
    const dir = String(this.#target.dir ?? provider.fallback);
    if (!provider.dir.test(dir)) {
      this.#send({ type: 'error', message: provider.place });
      return this.close();
    }

    const cols = clamp(this.#size.cols, 40, 300);
    const rows = clamp(this.#size.rows, 10, 100);
    const [bin, ...args] = provider.command(this.#config)(handle, cols, rows, dir);

    this.#child = this.#spawn(bin, args, { stdio: ['pipe', 'pipe', 'pipe'], env: { PATH: process.env.PATH ?? '/usr/bin:/bin' } });
    this.#log(`${provider.label} login for ${handle} (user ${this.#target.user_id ?? '?'})`);
    this.#follow();
  }

  /**
   * Development has no server home. `agent login` runs on this computer, in the
   * subscription's folder, on the same terminal the page already shows.
   */
  #startLocal() {
    const provider = PROVIDERS['cursor-login'];
    const dir = String(this.#target.dir ?? provider.fallback);
    if (!provider.dir.test(dir)) {
      this.#send({ type: 'error', message: provider.place });
      return this.close();
    }

    const cols = clamp(this.#size.cols, 40, 300);
    const rows = clamp(this.#size.rows, 10, 100);
    const home = homedir();
    const folder = join(home, dir);
    const path = [join(home, '.local', 'bin'), '/usr/local/bin', process.env.PATH].filter(Boolean).join(':');

    this.#child = this.#spawn('python3', ['-I', terminalPy, String(cols), String(rows), 'agent', 'login'], {
      env: {
        ...process.env,
        HOME: home,
        PATH: path,
        NO_OPEN_BROWSER: '1',
        AGENT_CLI_CREDENTIAL_STORE: 'file',
        CURSOR_CONFIG_DIR: folder,
        XDG_CONFIG_HOME: folder,
      },
      stdio: ['pipe', 'pipe', 'pipe', 'pipe'],
    });
    this.#log(`cursor login on this computer (user ${this.#target.user_id ?? '?'})`);
    this.#follow({
      // exec of a missing program exits 127 and prints nothing, which leaves the page on an empty terminal
      silentExit: 'Cursor CLI is not installed on this computer (agent was not found). Install it, then sign in again.\n',
    });
  }

  #follow({ silentExit = null } = {}) {
    this.#send({ type: 'status', state: 'ready' });

    const out = new StringDecoder('utf8');
    const err = new StringDecoder('utf8');
    let saw = false;
    const take = (text) => {
      if (text !== '') saw = true;
      this.#data(text);
    };
    this.#child.stdout.on('data', (chunk) => take(out.write(chunk)));
    this.#child.stderr.on('data', (chunk) => take(err.write(chunk)));
    this.#child.on('error', (error) => {
      this.#send({ type: 'error', message: `The sign-in could not start: ${error.message}` });
      this.close();
    });
    this.#child.on('close', (code) => {
      if (!saw && silentExit && code === 127) this.#data(silentExit);
      this.#send({ type: 'exit', code });
      this.close();
    });

    // A sign-in nobody finishes does not hold a process for ever.
    this.#timer = setTimeout(() => {
      this.#send({ type: 'error', message: 'The sign-in took too long and was stopped. Start it again.' });
      this.close();
    }, this.#config.loginTimeout);
    this.#timer.unref?.();
  }

  close() {
    if (this.#closed) return;
    this.#closed = true;
    clearTimeout(this.#timer);
    if (this.#child && this.#child.exitCode === null) {
      try { this.#child.stdin.end(); } catch {}
      try { this.#child.kill('SIGTERM'); } catch {}
    }
    this.#send({ type: 'closed' });
    try { this.#ws.close(1000); } catch {}
  }

  #onMessage(raw) {
    let message;
    try {
      message = JSON.parse(raw.toString());
    } catch {
      return;
    }
    if (message.type === 'data' && typeof message.data === 'string' && this.#child?.stdin.writable) {
      this.#child.stdin.write(message.data);
    }
  }

  #data(text) {
    if (text !== '') this.#send({ type: 'data', data: text });
  }

  #send(message) {
    if (this.#ws.readyState === 1) this.#ws.send(JSON.stringify(message));
  }
}

function clamp(value, min, max) {
  const n = Math.trunc(Number(value));
  return Number.isFinite(n) ? Math.min(max, Math.max(min, n)) : min;
}
