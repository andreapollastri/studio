import http from 'node:http';
import { StringDecoder } from 'node:string_decoder';

/**
 * The Terminal tab of a workspace: a shell the workspace's own bridge runs as
 * the workspace user, on a real terminal (bridge/src/shell.js). Studio's ticket
 * says which bridge, with its token and the sandbox, if any; the gateway opens
 * GET /shell there, upgraded to a socket of newline-delimited JSON, and relays
 * it both ways with the messages of the SSH session: data and resize in;
 * status, data, exit, error and closed out.
 */
export class WorkspaceShellSession {
  #ws;
  #target;
  #size;
  #log;
  #socket = null;
  #closed = false;
  #buffer = '';

  constructor(ws, target, { size, log = () => {} } = {}) {
    this.#ws = ws;
    this.#target = target;
    this.#size = size ?? { cols: 80, rows: 24 };
    this.#log = log;

    ws.on('message', (raw) => this.#onMessage(raw));
    ws.on('close', () => this.close());
    ws.on('error', () => this.close());
  }

  start() {
    let url;
    try {
      url = new URL('/shell', String(this.#target.bridge_url ?? ''));
    } catch {
      return this.#fail('This workspace has no bridge to open a terminal on.');
    }
    // the bridges listen on the loopback only; a ticket pointing anywhere else is not followed
    if (url.protocol !== 'http:' || !['127.0.0.1', 'localhost', '[::1]'].includes(url.hostname)) {
      return this.#fail('This workspace has no bridge to open a terminal on.');
    }
    url.searchParams.set('cols', String(clamp(this.#size.cols, 20, 500)));
    url.searchParams.set('rows', String(clamp(this.#size.rows, 5, 200)));

    const req = http.request(url, {
      headers: {
        connection: 'Upgrade',
        upgrade: 'studio-shell',
        authorization: `Bearer ${this.#target.bridge_token ?? ''}`,
        ...(this.#target.sandbox ? { 'x-studio-sandbox': String(this.#target.sandbox) } : {}),
      },
      timeout: 10_000,
    });
    req.on('upgrade', (res, socket) => this.#attach(socket));
    req.on('response', (res) => {
      let body = '';
      res.setEncoding('utf8');
      res.on('data', (chunk) => { body += chunk; });
      res.on('end', () => this.#fail(`The workspace refused the terminal (${res.statusCode}): ${body.trim().slice(0, 200) || 'no reason given'}`));
    });
    req.on('timeout', () => req.destroy(new Error('the workspace bridge did not answer')));
    req.on('error', (error) => this.#fail(`The workspace bridge cannot be reached: ${error.message}. Is the workspace running?`));
    req.end();
  }

  close() {
    if (this.#closed) return;
    this.#closed = true;
    try { this.#socket?.end(); } catch {}
    this.#send({ type: 'closed' });
    try { this.#ws.close(1000); } catch {}
  }

  #attach(socket) {
    if (this.#closed) return socket.destroy();
    this.#socket = socket;
    this.#log(`workspace shell for user ${this.#target.user_id ?? '?'}${this.#target.sandbox ? ` in sandbox ${this.#target.sandbox}` : ''}`);
    this.#send({ type: 'status', state: 'ready' });

    const text = new StringDecoder('utf8');
    socket.on('data', (chunk) => {
      this.#buffer += text.write(chunk);
      let index;
      while ((index = this.#buffer.indexOf('\n')) !== -1) {
        const line = this.#buffer.slice(0, index);
        this.#buffer = this.#buffer.slice(index + 1);
        let message;
        try { message = JSON.parse(line); } catch { continue; }
        if (message.type === 'data' && typeof message.data === 'string') this.#send({ type: 'data', data: message.data });
        else if (message.type === 'exit') this.#send({ type: 'exit', code: message.code ?? null });
      }
    });
    socket.on('close', () => this.close());
    socket.on('error', () => this.close());
  }

  #onMessage(raw) {
    let message;
    try {
      message = JSON.parse(raw.toString());
    } catch {
      return;
    }
    if (!this.#socket || this.#socket.destroyed) return;
    if (message.type === 'data' && typeof message.data === 'string') {
      this.#socket.write(`${JSON.stringify({ type: 'data', data: message.data })}\n`);
    } else if (message.type === 'resize') {
      this.#socket.write(`${JSON.stringify({ type: 'resize', cols: clamp(message.cols, 20, 500), rows: clamp(message.rows, 5, 200) })}\n`);
    }
  }

  #fail(message) {
    this.#send({ type: 'error', message });
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
