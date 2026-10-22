import { createHash, randomBytes } from 'node:crypto';
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
 *                   {type: 'close'}: the person closed the session, it ends
 *   gateway → page  {type: 'status', state} · {type: 'data', data} · {type: 'hostkey', fingerprint}
 *                   {type: 'session', resume}: the token that takes the session back
 *                   {type: 'replay', data}: what the shell wrote, when a page takes it back
 *                   {type: 'error', message} · {type: 'closed', code}
 * A host seen for the first time waits for the person to accept its key; a
 * host whose key changed is refused.
 *
 * A shell outlives its page: when the WebSocket goes without a 'close' (the
 * person went to another page of Studio, reloaded, closed the tab), the
 * session waits config.linger ms for a page to come back with its resume
 * token, keeping the last config.replayChars of what the shell wrote. The
 * page that comes back takes the session over, the one before it is let go.
 */
export class SshSession {
  #ws = null;
  #target;
  #config;
  #client;
  #stream = null;
  #size;
  #pendingHostKey = null;
  #closed = false;
  #failed = false;
  #log;
  #onEnd;
  #onResumable;
  #replay = '';
  #parkTimer = null;
  /** The token a page shows to take the session back; set once the shell runs. */
  resume = null;

  constructor(ws, target, config, { size, log = () => {}, ClientImpl = Client, onEnd = () => {}, onResumable = () => {} } = {}) {
    this.#target = target;
    this.#config = config;
    this.#size = size;
    this.#log = log;
    this.#onEnd = onEnd;
    this.#onResumable = onResumable;
    this.#client = new ClientImpl();
    this.#bind(ws);
  }

  get userId() {
    return String(this.#target.user_id ?? 'unknown');
  }

  get connectionId() {
    return this.#target.connection_id ?? null;
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

  /** A page came back with the resume token: it gets what the shell wrote, then the shell itself. */
  attach(ws, size) {
    // the page before goes: what it still sends, and its closing, no longer count (#bind)
    const previous = this.#ws;
    this.#ws = null;
    if (previous) {
      try { previous.close(4409, 'The session was opened on another page.'); } catch {}
    }
    clearTimeout(this.#parkTimer);
    this.#parkTimer = null;
    this.#bind(ws);

    if (size) this.#resize(size.cols, size.rows);
    this.#send({ type: 'status', state: 'ready' });
    this.#send({ type: 'session', resume: this.resume });
    this.#send({ type: 'replay', data: this.#replay });
    this.#log(`resumed ${this.#target.username}@${this.#target.host} (user ${this.userId})`);
  }

  close() {
    if (this.#closed) return;
    this.#closed = true;
    clearTimeout(this.#parkTimer);
    this.#pendingHostKey?.(false);
    try { this.#stream?.end(); } catch {}
    try { this.#client.end(); } catch {}
    this.#send({ type: 'closed' });
    try { this.#ws?.close(1000); } catch {}
    this.#onEnd(this);
  }

  #bind(ws) {
    this.#ws = ws;
    ws.on('message', (raw) => { if (this.#ws === ws) this.#onMessage(raw); });
    ws.on('close', () => this.#gone(ws));
    ws.on('error', () => {});
  }

  /** The page went: a running shell waits for it, anything else ends. */
  #gone(ws) {
    if (this.#ws !== ws) return;
    this.#ws = null;
    if (this.#closed) return;
    if (!this.#stream || this.resume === null || !(this.#config.linger > 0)) return this.close();

    this.#log(`parked ${this.#target.username}@${this.#target.host} (user ${this.userId}) for ${Math.round(this.#config.linger / 1000)} s`);
    this.#parkTimer = setTimeout(() => {
      this.#log(`ended ${this.#target.username}@${this.#target.host} (user ${this.userId}): nobody came back`);
      this.close();
    }, this.#config.linger);
    this.#parkTimer.unref?.();
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
      this.#log(`open ${this.#target.username}@${this.#target.host}:${this.#target.port ?? 22} (user ${this.userId})`);
      this.#send({ type: 'status', state: 'ready' });

      // the page keeps the token: with it, the shell outlives the page
      if (this.#config.linger > 0) {
        this.resume = randomBytes(24).toString('base64url');
        this.#onResumable(this);
        this.#send({ type: 'session', resume: this.resume });
      }

      const out = new StringDecoder('utf8');
      const err = new StringDecoder('utf8');
      stream.on('data', (chunk) => this.#data(out.write(chunk)));
      stream.stderr.on('data', (chunk) => this.#data(err.write(chunk)));
      stream.on('close', () => this.close());
      // the page left while the shell opened
      if (this.#ws === null) this.#gone(null);
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
      this.#resize(message.cols, message.rows);
    } else if (message.type === 'hostkey') {
      this.#pendingHostKey?.(message.accept === true);
    } else if (message.type === 'close') {
      this.close();
    }
  }

  #resize(cols, rows) {
    this.#size = { cols: clamp(cols, 2, 1000), rows: clamp(rows, 1, 500) };
    this.#stream?.setWindow(this.#size.rows, this.#size.cols, 0, 0);
  }

  #data(text) {
    if (text === '') return;
    this.#keep(text);
    this.#send({ type: 'data', data: text });
  }

  /** The last replayChars of the output, cut at a line so that a replay does not start inside an escape sequence. */
  #keep(text) {
    const max = this.#config.replayChars ?? 0;
    if (!(max > 0)) return;
    this.#replay += text;
    if (this.#replay.length > max) {
      const tail = this.#replay.slice(-max);
      const line = tail.indexOf('\n');
      this.#replay = line === -1 ? tail : tail.slice(line + 1);
    }
  }

  /** The first reason wins: a refused host key also ends in a generic handshake error. */
  #fail(message) {
    if (!this.#failed) this.#send({ type: 'error', message });
    this.#failed = true;
    this.close();
  }

  #send(message) {
    if (this.#ws?.readyState === 1) this.#ws.send(JSON.stringify(message));
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
