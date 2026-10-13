#!/usr/bin/env node
import { loadConfig } from '../src/config.js';
import { createGateway, VERSION } from '../src/server.js';

const config = loadConfig();
const server = createGateway(config);

server.listen(config.port, config.host, () => {
  console.log(`[ssh-gateway] ${VERSION} on ${config.host}:${config.port}, tickets redeemed at ${config.redeemUrl}`);
});

for (const signal of ['SIGTERM', 'SIGINT']) {
  // Open shells keep their sockets: give them a moment, then go.
  process.on(signal, () => {
    server.close(() => process.exit(0));
    setTimeout(() => process.exit(0), 2000).unref();
  });
}
