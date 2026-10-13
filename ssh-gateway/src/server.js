import http from 'node:http';
import { createRequire } from 'node:module';
import { WebSocketServer } from 'ws';
import { redeem as redeemTicket } from './redeem.js';
import { LoginSession } from './login.js';
import { SshSession } from './session.js';

export const VERSION = createRequire(import.meta.url)('../package.json').version;

/** Close codes the page understands (4000–4999 are free for applications). */
export const CLOSE = { badTicket: 4401, forbiddenOrigin: 4403, tooMany: 4429, studioDown: 4502 };

/**
 * GET /health, and the WebSocket at /ssh/ws?ticket=…&cols=…&rows=… that
 * Caddy forwards from https://studio.<domain>/ssh/ws. The ticket is Studio's
 * permission for this one session; nothing else authenticates the socket.
 * A ticket stands for an SSH connection, or (kind: claude-login) for a
 * person's sign-in to Claude Code in their own home.
 */
export function createGateway(config, { redeem = (ticket) => redeemTicket(config, ticket), ClientImpl, spawn } = {}) {
  const log = config.log ? (message) => console.log(`[ssh-gateway] ${message}`) : () => {};
  const perUser = new Map();
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
    if ((perUser.get(user) ?? 0) >= config.maxPerUser) {
      return refuse(CLOSE.tooMany, `At most ${config.maxPerUser} SSH sessions at a time.`);
    }
    perUser.set(user, (perUser.get(user) ?? 0) + 1);
    ws.once('close', () => {
      const left = (perUser.get(user) ?? 1) - 1;
      if (left > 0) perUser.set(user, left); else perUser.delete(user);
    });

    // Proxies drop idle sockets; a ping now and then keeps a quiet shell open.
    const ping = setInterval(() => { try { ws.ping(); } catch {} }, 30_000);
    ws.once('close', () => clearInterval(ping));

    const size = { cols: Number(url.searchParams.get('cols')) || 80, rows: Number(url.searchParams.get('rows')) || 24 };
    if (target.kind === 'claude-login') {
      new LoginSession(ws, target, config, { size, log, ...(spawn ? { spawn } : {}) }).start();
    } else {
      new SshSession(ws, target, config, { size, log, ...(ClientImpl ? { ClientImpl } : {}) }).start();
    }
  }

  server.on('close', () => wss.close());

  return server;
}
