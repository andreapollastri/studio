#!/usr/bin/env node
import { loadConfig } from '../src/config.js';
import { createServer } from '../src/server.js';

const config = loadConfig(process.env);
const server = createServer(config);

server.listen(config.port, config.host, () => {
  console.log(`[bridge] listening on http://${config.host}:${config.port} · workspace ${config.workspaceDir} · claude ${config.claudeBin}`);
});

const shutdown = (signal) => {
  console.log(`[bridge] ${signal}: closing`);
  server.shutdown().then(() => process.exit(0));
};
process.on('SIGINT', () => shutdown('SIGINT'));
process.on('SIGTERM', () => shutdown('SIGTERM'));
