import http from 'node:http';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

export const here = dirname(fileURLToPath(import.meta.url));
export const fakeClaude = join(here, 'fake-claude.js');

/** Spawn the fake CLI through node so the test does not rely on the exec bit. */
export const spawnFake = (bin, args, options) => spawn(process.execPath, [bin, ...args], options);

export function testConfig(overrides = {}) {
  return {
    host: '127.0.0.1',
    port: 0,
    token: 'bridge-secret',
    workspaceDir: here,
    claudeBin: fakeClaude,
    idleTtl: 60,
    flushInterval: 10,
    maxBodyBytes: 1024 * 1024,
    fileMaxBytes: 512 * 1024,
    log: false,
    ...overrides,
  };
}

/** A tiny Studio: collects every batch the bridge posts. */
export function startCollector() {
  const posts = [];
  const server = http.createServer((req, res) => {
    let body = '';
    req.on('data', (c) => (body += c));
    req.on('end', () => {
      posts.push({ headers: req.headers, body: JSON.parse(body) });
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end('{"ok":true}');
    });
  });
  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address();
      resolve({
        url: `http://127.0.0.1:${port}/api/bridge/events`,
        posts,
        events: () => posts.flatMap((p) => p.body.events),
        close: () => new Promise((r) => server.close(r)),
      });
    });
  });
}

export async function waitFor(predicate, { timeout = 5000, step = 15 } = {}) {
  const start = Date.now();
  while (Date.now() - start < timeout) {
    const value = await predicate();
    if (value) return value;
    await new Promise((r) => setTimeout(r, step));
  }
  throw new Error('waitFor timed out');
}

export function listen(server) {
  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(`http://127.0.0.1:${server.address().port}`)));
}
