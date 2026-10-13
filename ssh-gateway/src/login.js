import { spawn as spawnProcess } from 'node:child_process';
import { StringDecoder } from 'node:string_decoder';

/**
 * A person signing in to their Claude subscription: the official
 * `claude auth login` of the unmodified CLI, run by the privileged helper as
 * the person, in their home, on a pseudo-terminal (studio-admin claude-login).
 * The gateway only relays keystrokes and screen, like any terminal: the login
 * lands in the person's ~/.claude and nothing of it is kept here or in Studio.
 *
 * Messages are the SSH session's: data and resize in; status, data, exit,
 * error and closed out.
 */
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
    const handle = String(this.#target.handle ?? '');
    if (!/^[a-z0-9][a-z0-9-]{0,27}$/.test(handle)) {
      this.#send({ type: 'error', message: 'This account has no home on the server yet.' });
      return this.close();
    }

    const dir = String(this.#target.dir ?? '.claude');
    if (!/^\.claude(-[a-z0-9]{1,40})?$/.test(dir)) {
      this.#send({ type: 'error', message: 'This Claude account has no place on the server.' });
      return this.close();
    }

    const cols = clamp(this.#size.cols, 40, 300);
    const rows = clamp(this.#size.rows, 10, 100);
    const [bin, ...args] = this.#config.loginCommand(handle, cols, rows, dir);

    this.#child = this.#spawn(bin, args, { stdio: ['pipe', 'pipe', 'pipe'], env: { PATH: process.env.PATH ?? '/usr/bin:/bin' } });
    this.#log(`claude login for ${handle} (user ${this.#target.user_id ?? '?'})`);
    this.#send({ type: 'status', state: 'ready' });

    const out = new StringDecoder('utf8');
    const err = new StringDecoder('utf8');
    this.#child.stdout.on('data', (chunk) => this.#data(out.write(chunk)));
    this.#child.stderr.on('data', (chunk) => this.#data(err.write(chunk)));
    this.#child.on('error', (error) => {
      this.#send({ type: 'error', message: `The sign-in could not start: ${error.message}` });
      this.close();
    });
    this.#child.on('close', (code) => {
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
