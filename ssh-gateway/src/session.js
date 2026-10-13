import { createHash } from 'node:crypto';
import { StringDecoder } from 'node:string_decoder';
import ssh2 from 'ssh2';

const { Client } = ssh2;

/** SHA256:… as `ssh-keygen -lf` prints it. */
export function fingerprint(key) {
  return 'SHA256:' + createHash('sha256').update(key).digest('base64').replace(/=+$/, '');
}

/**
 * One terminal of the page: a WebSocket on one side, an SSH shell on the
 * other. Messages are JSON both ways:
 *   page → gateway  {type: 'data', data} · {type: 'resize', cols, rows} · {type: 'hostkey', accept}
 *   gateway → page  {type: 'status', state} · {type: 'data', data} · {type: 'hostkey', fingerprint}
 *                   {type: 'error', message} · {type: 'closed', code}
 * A host seen for the first time waits for the person to accept its key; a
 * host whose key changed is refused.
 */
export class SshSession {
  #ws;
  #target;
  #config;
  #client;
  #stream = null;
  #size;
  #pendingHostKey = null;
  #closed = false;
  #failed = false;
  #log;

  constructor(ws, target, config, { size, log = () => {}, ClientImpl = Client } = {}) {
    this.#ws = ws;
    this.#target = target;
    this.#config = config;
    this.#size = size;
    this.#log = log;
    this.#client = new ClientImpl();

    ws.on('message', (raw) => this.#onMessage(raw));
    ws.on('close', () => this.close());
    ws.on('error', () => this.close());
  }

  start() {
    const target = this.#target;
    this.#send({ type: 'status', state: 'connecting' });

    this.#client.on('ready', () => this.#openShell());
    this.#client.on('error', (error) => this.#fail(describe(error)));
    this.#client.on('close', () => this.close());
    this.#client.on('keyboard-interactive', (name, instructions, lang, prompts, finish) => {
      // Servers that ask for the password as a prompt rather than with "password" auth.
      finish(prompts.map(() => target.password ?? ''));
    });

    this.#client.connect({
      host: target.host,
      port: target.port ?? 22,
      username: target.username,
      ...(target.auth === 'password' ? { password: target.password, tryKeyboard: true } : { privateKey: target.private_key }),
      readyTimeout: this.#config.readyTimeout,
      keepaliveInterval: 15_000,
      keepaliveCountMax: 4,
      hostVerifier: (key, verify) => this.#verifyHost(key, verify),
    });
  }

  close() {
    if (this.#closed) return;
    this.#closed = true;
    this.#pendingHostKey?.(false);
    try { this.#stream?.end(); } catch {}
    try { this.#client.end(); } catch {}
    this.#send({ type: 'closed' });
    try { this.#ws.close(1000); } catch {}
  }

  #verifyHost(key, verify) {
    const seen = fingerprint(key);
    const known = this.#target.host_key;

    if (known) {
      if (known !== seen) {
        this.#failed = true;
        this.#send({ type: 'error', message: `The host key of ${this.#target.host} changed: expected ${known}, got ${seen}. If that is expected, forget the saved key in the connection and connect again.` });
      }
      return verify(known === seen);
    }

    const timer = setTimeout(() => this.#pendingHostKey?.(false), this.#config.hostKeyTimeout);
    this.#pendingHostKey = (accept) => {
      clearTimeout(timer);
      this.#pendingHostKey = null;
      if (!accept && !this.#closed) {
        this.#failed = true;
        this.#send({ type: 'error', message: 'Host key not accepted.' });
      }
      verify(Boolean(accept));
    };
    this.#send({ type: 'hostkey', fingerprint: seen, host: this.#target.host });
  }

  #openShell() {
    const { cols = 80, rows = 24 } = this.#size ?? {};
    this.#client.shell({ term: 'xterm-256color', cols, rows }, (error, stream) => {
      if (error) return this.#fail(describe(error));
      this.#stream = stream;
      this.#log(`open ${this.#target.username}@${this.#target.host}:${this.#target.port ?? 22} (user ${this.#target.user_id ?? '?'})`);
      this.#send({ type: 'status', state: 'ready' });

      const out = new StringDecoder('utf8');
      const err = new StringDecoder('utf8');
      stream.on('data', (chunk) => this.#data(out.write(chunk)));
      stream.stderr.on('data', (chunk) => this.#data(err.write(chunk)));
      stream.on('close', () => this.close());
    });
  }

  #onMessage(raw) {
    let message;
    try {
      message = JSON.parse(raw.toString());
    } catch {
      return;
    }

    if (message.type === 'data' && typeof message.data === 'string') {
      this.#stream?.write(message.data);
    } else if (message.type === 'resize') {
      const cols = clamp(message.cols, 2, 1000);
      const rows = clamp(message.rows, 1, 500);
      this.#size = { cols, rows };
      this.#stream?.setWindow(rows, cols, 0, 0);
    } else if (message.type === 'hostkey') {
      this.#pendingHostKey?.(message.accept === true);
    }
  }

  #data(text) {
    if (text !== '') this.#send({ type: 'data', data: text });
  }

  /** The first reason wins: a refused host key also ends in a generic handshake error. */
  #fail(message) {
    if (!this.#failed) this.#send({ type: 'error', message });
    this.#failed = true;
    this.close();
  }

  #send(message) {
    if (this.#ws.readyState === 1) this.#ws.send(JSON.stringify(message));
  }
}

function clamp(value, min, max) {
  const n = Math.trunc(Number(value));
  return Number.isFinite(n) ? Math.min(max, Math.max(min, n)) : min;
}

function describe(error) {
  if (error?.level === 'client-authentication') return 'Authentication failed: the server did not accept this key or password.';
  if (error?.code === 'ENOTFOUND') return `Unknown host: ${error.hostname ?? ''}`.trim();
  if (error?.code === 'ECONNREFUSED') return 'Connection refused.';
  if (error?.code === 'ETIMEDOUT' || /timed out/i.test(error?.message ?? '')) return 'The connection timed out.';
  return error?.message || 'The connection failed.';
}
