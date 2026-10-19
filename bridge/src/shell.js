import { spawn as spawnProcess } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { StringDecoder } from 'node:string_decoder';
import { NdjsonParser } from './ndjson.js';

// -I: Python's isolated mode, nothing from the workspace or its environment is imported
const TERMINAL = fileURLToPath(new URL('./terminal.py', import.meta.url));

/**
 * The Terminal tab's shell: the workspace user's login shell on a real
 * terminal (src/terminal.py), in the workspace or a sandbox's worktree, so prompts,
 * passwords, colours and full-screen programs work as over SSH. Studio's SSH
 * gateway carries it to the browser over its WebSocket; here it is one socket,
 * upgraded from GET /shell, speaking newline-delimited JSON both ways:
 * data and resize in; data and exit out.
 */
export class ShellSession {
  #socket;
  #child = null;
  #closed = false;

  constructor(socket, { cwd, cols = 80, rows = 24, shell = null, spawn = spawnProcess, onClose = () => {} }) {
    this.#socket = socket;
    this.onClose = onClose;

    const program = shell || process.env.SHELL || '/bin/bash';
    this.#child = spawn('python3', ['-I', TERMINAL, String(cols), String(rows), program, '-l'], {
      cwd,
      env: shellEnv(process.env),
      stdio: ['pipe', 'pipe', 'pipe', 'pipe'],
    });

    const out = new StringDecoder('utf8');
    const err = new StringDecoder('utf8');
    this.#child.stdout.on('data', (chunk) => this.#send({ type: 'data', data: out.write(chunk) }));
    this.#child.stderr.on('data', (chunk) => this.#send({ type: 'data', data: err.write(chunk) }));
    this.#child.on('error', (error) => {
      this.#send({ type: 'data', data: `\r\nThe shell could not start: ${error.code === 'ENOENT' ? 'python3 is not installed' : error.message}\r\n` });
      this.close();
    });
    this.#child.on('close', (code) => {
      this.#send({ type: 'exit', code });
      this.close();
    });
    // the pty helper may be gone already: a write then fails, and that is all
    for (const pipe of [this.#child.stdin, this.#child.stdio[3]]) pipe?.on('error', () => {});

    // an upgraded socket of the HTTP server takes no setEncoding: decode here, whole characters only
    const input = new NdjsonParser((message) => this.#onMessage(message));
    const text = new StringDecoder('utf8');
    socket.on('data', (chunk) => input.push(text.write(chunk)));
    socket.on('end', () => this.close());
    socket.on('close', () => this.close());
    socket.on('error', () => this.close());
  }

  close() {
    if (this.#closed) return;
    this.#closed = true;
    // stdin closing hangs the shell up; the helper exits with it
    try { this.#child?.stdin.end(); } catch {}
    try { this.#socket.end(); } catch {}
    this.onClose();
  }

  #onMessage(message) {
    if (!message || typeof message !== 'object' || !this.#child) return;
    if (message.type === 'data' && typeof message.data === 'string' && this.#child.stdin.writable) {
      this.#child.stdin.write(message.data);
    } else if (message.type === 'resize' && Number.isFinite(message.cols) && Number.isFinite(message.rows)) {
      this.#child.stdio[3]?.write(`${Math.trunc(message.cols)} ${Math.trunc(message.rows)}\n`);
    }
  }

  #send(message) {
    if (message.type === 'data' && message.data === '') return;
    if (!this.#socket.destroyed && this.#socket.writable) this.#socket.write(`${JSON.stringify(message)}\n`);
  }
}

/**
 * The bridge's environment for a person at a terminal: the bridge's token and the API key it
 * hands to conversations stay out, git keeps answering with the person's token, and the
 * terminal is a real one.
 */
export function shellEnv(env) {
  const out = Object.fromEntries(Object.entries(env).filter(([key]) => !key.startsWith('BRIDGE_') && !['STUDIO_ANTHROPIC_API_KEY', 'CI', 'NO_COLOR', 'COMPOSER_NO_INTERACTION'].includes(key)));
  return { ...out, TERM: 'xterm-256color', COLORTERM: 'truecolor', LANG: env.LANG || 'C.UTF-8' };
}
