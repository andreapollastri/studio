import http from 'node:http';
import { createRequire } from 'node:module';
import { WebSocketServer } from 'ws';
import { redeem as redeemTicket } from './redeem.js';
import { LoginSession } from './login.js';
import { SshSession } from './session.js';
import { WorkspaceShellSession } from './workspace-shell.js';
import { SiteShellSession } from './site-shell.js';

export const VERSION = createRequire(import.meta.url)('../package.json').version;

/** Close codes the page understands (4000–4999 are free for applications). */
export const CLOSE = { badTicket: 4401, forbiddenOrigin: 4403, takenOver: 4409, gone: 4410, tooMany: 4429, studioDown: 4502 };

/**
 * GET /health, and the WebSocket at /ssh/ws?ticket=…&cols=…&rows=… that
 * Caddy forwards from https://studio.<domain>/ssh/ws. The ticket is Studio's
 * permission for this one session; nothing else authenticates the socket.
 * A ticket stands for an SSH connection, (kind: claude-login) for a person's
 * sign-in to Claude Code in their own home, (kind: cursor-login) for their
 * sign-in to Cursor in that same home, or (kind: workspace-shell) for the
 * shell of a workspace, which its bridge runs, or (kind: site-shell) for a shell on a project's own
 * site, as its user, through the helper.
 *
 * A shell of an SSH connection outlives its page (SshSession): with ?resume=<token>
 * and a fresh ticket of the same person for the same connection, a page takes back
 * the session it left. The ticket still has to be redeemed: the person must still be
 * signed in to Studio and allowed on the connection.
 */
export function createGateway(config, { redeem = (ticket) => redeemTicket(config, ticket), ClientImpl, spawn } = {}) {
  const log = config.log ? (message) => console.log(`[ssh-gateway] ${message}`) : () => {};
  // SSH sessions per person, the ones waiting for their page included; and those by their resume token
  const perUser = new Map();
  const resumable = new Map();
  const hold = (user) => {
    perUser.set(user, (perUser.get(user) ?? 0) + 1);
    let held = true;
    return () => {
      if (!held) return;
      held = false;
      const left = (perUser.get(user) ?? 1) - 1;
      if (left > 0) perUser.set(user, left); else perUser.delete(user);
    };
  };
  const wss = new WebSocketServer({ noServer: true, maxPayload: 1024 * 1024 });

  const server = http.createServer((req, res) => {
    if (req.method === 'GET' && new URL(req.url, 'http://gateway').pathname === '/health') {
      res.writeHead(200, { 'content-type': 'application/json' });
      return res.end(JSON.stringify({ ok: true, version: VERSION, sessions: [...perUser.values()].reduce((a, b) => a + b, 0) }));
    }
    res.writeHead(404, { 'content-type': 'application/json' });
    res.end(JSON.stringify({ error: 'not found' }));
  });

  server.on('upgrade', (req, socket, head) => {
    const url = new URL(req.url, 'http://gateway');
    if (url.pathname.replace(/\/+$/, '') !== '/ssh/ws') {
      socket.end('HTTP/1.1 404 Not Found\r\n\r\n');
      return;
    }
    wss.handleUpgrade(req, socket, head, (ws) => accept(ws, req, url));
  });

  async function accept(ws, req, url) {
    const refuse = (code, message) => {
      ws.send(JSON.stringify({ type: 'error', message }));
      ws.close(code, message.slice(0, 120));
    };

    if (config.origin && req.headers.origin !== config.origin) {
      return refuse(CLOSE.forbiddenOrigin, 'This page may not open SSH sessions.');
    }

    let target;
    try {
      target = await redeem(url.searchParams.get('ticket') ?? '');
    } catch (error) {
      log(`redeem failed: ${error.message}`);
      return refuse(CLOSE.studioDown, 'Studio could not be reached to check the session.');
    }
    if (!target) return refuse(CLOSE.badTicket, 'The session ticket is unknown or already used: open the connection again.');

    const user = String(target.user_id ?? 'unknown');
    const size = { cols: Number(url.searchParams.get('cols')) || 80, rows: Number(url.searchParams.get('rows')) || 24 };

    // Proxies drop idle sockets; a ping now and then keeps a quiet shell open.
    const ping = setInterval(() => { try { ws.ping(); } catch {} }, 30_000);
    ws.once('close', () => clearInterval(ping));

    // a session left by its page: only its own person, on the same connection, takes it back
    const resume = url.searchParams.get('resume');
    if (resume) {
      const parked = resumable.get(resume);
      if (!parked || target.kind || parked.userId !== user || String(parked.connectionId) !== String(target.connection_id)) {
        return refuse(CLOSE.gone, 'The session ended while you were away: open the connection again.');
      }
      return parked.attach(ws, size);
    }

    if ((perUser.get(user) ?? 0) >= config.maxPerUser) {
      return refuse(CLOSE.tooMany, `At most ${config.maxPerUser} SSH sessions at a time.`);
    }
    const release = hold(user);

    if (target.kind) ws.once('close', release);
    if (target.kind === 'claude-login' || target.kind === 'cursor-login') {
      new LoginSession(ws, target, config, { size, log, ...(spawn ? { spawn } : {}) }).start();
    } else if (target.kind === 'workspace-shell') {
      new WorkspaceShellSession(ws, target, { size, log }).start();
    } else if (target.kind === 'site-shell') {
      new SiteShellSession(ws, target, config, { size, log, ...(spawn ? { spawn } : {}) }).start();
    } else {
      new SshSession(ws, target, config, {
        size,
        log,
        ...(ClientImpl ? { ClientImpl } : {}),
        onResumable: (session) => resumable.set(session.resume, session),
        onEnd: (session) => {
          if (session.resume) resumable.delete(session.resume);
          release();
        },
      }).start();
    }
  }

  server.on('close', () => {
    wss.close();
    // a session waiting for its page has nobody to wait for any more
    for (const session of [...resumable.values()]) session.close();
  });

  return server;
}
