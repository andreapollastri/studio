import { spawn as spawnProcess } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { StringDecoder } from 'node:string_decoder';

// the bridge's terminal (Python's pty), shared: it gives the shell a real terminal and takes its size on fd 3
const TERMINAL = fileURLToPath(new URL('../../bridge/src/terminal.py', import.meta.url));

/**
 * The Terminal's Develop mode: a login shell on a project's own site (not a person's workspace), as the
 * site's user, in its app, through the privileged helper (studio-admin site-shell). Studio's ticket says
 * which project and its PHP; whoever got it may develop on that project and confirmed their password.
 *
 * Messages are the SSH session's: data and resize in; status, data, exit, error and closed out.
 */
export class SiteShellSession {
  #ws;
  #target;
  #config;
  #size;
  #spawn;
  #log;
  #child = null;
  #closed = false;

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
    const key = String(this.#target.key ?? '');
    const php = String(this.#target.php ?? '');
    if (!/^[a-z0-9][a-z0-9-]{0,62}$/.test(key) || !/^\d+\.\d+$/.test(php)) {
      this.#send({ type: 'error', message: 'This project has no site on this server to open a shell on.' });
      return this.close();
    }

    const cols = clamp(this.#size.cols, 20, 500);
    const rows = clamp(this.#size.rows, 5, 200);
    this.#child = this.#spawn('python3', ['-I', TERMINAL, String(cols), String(rows), ...this.#config.siteShellCommand(key, php)], {
      stdio: ['pipe', 'pipe', 'pipe', 'pipe'],
      env: { PATH: process.env.PATH ?? '/usr/bin:/bin', LANG: 'C.UTF-8' },
    });
    this.#log(`site shell on ${key} for user ${this.#target.user_id ?? '?'}`);
    this.#send({ type: 'status', state: 'ready' });

    const out = new StringDecoder('utf8');
    const err = new StringDecoder('utf8');
    this.#child.stdout.on('data', (chunk) => this.#data(out.write(chunk)));
    this.#child.stderr.on('data', (chunk) => this.#data(err.write(chunk)));
    for (const pipe of [this.#child.stdin, this.#child.stdio[3]]) pipe?.on('error', () => {});
    this.#child.on('error', (error) => {
      this.#send({ type: 'error', message: `The shell could not start: ${error.message}` });
      this.close();
    });
    this.#child.on('close', (code) => {
      this.#send({ type: 'exit', code });
      this.close();
    });
  }

  close() {
    if (this.#closed) return;
    this.#closed = true;
    // stdin closing hangs the shell up, as a terminal window closing does
    try { this.#child?.stdin.end(); } catch {}
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
    if (!this.#child) return;
    if (message.type === 'data' && typeof message.data === 'string' && this.#child.stdin.writable) {
      this.#child.stdin.write(message.data);
    } else if (message.type === 'resize') {
      this.#child.stdio[3]?.write(`${clamp(message.cols, 20, 500)} ${clamp(message.rows, 5, 200)}\n`);
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
