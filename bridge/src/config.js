import { resolve } from 'node:path';

/**
 * Everything the bridge needs comes from the environment. Studio tells it,
 * per turn, where to call back and with which token, so nothing about Studio
 * is configured here beyond the bearer token Studio must present.
 */
export function loadConfig(env = process.env) {
  const token = env.BRIDGE_TOKEN;
  if (!token) throw new Error('BRIDGE_TOKEN is required');

  return {
    // Studio talks to the bridge over the loopback; another address is an explicit choice.
    host: env.BRIDGE_HOST || '127.0.0.1',
    port: Number(env.BRIDGE_PORT || 4455),
    token,
    workspaceDir: resolve(env.WORKSPACE_DIR || process.cwd()),
    claudeBin: env.CLAUDE_BIN || 'claude',
    // Seconds a finished session keeps its process alive for the next turn.
    idleTtl: Number(env.BRIDGE_IDLE_TTL || 600),
    // Milliseconds to coalesce text deltas before posting them to Studio.
    flushInterval: Number(env.BRIDGE_FLUSH_MS || 200),
    maxBodyBytes: Number(env.BRIDGE_MAX_BODY || 1024 * 1024),
    // Project terminal: seconds a command may run, bytes of output kept, runs remembered.
    runTimeout: Number(env.BRIDGE_RUN_TIMEOUT || 600),
    runOutputLimit: Number(env.BRIDGE_RUN_OUTPUT || 200 * 1024),
    runHistory: Number(env.BRIDGE_RUN_HISTORY || 20),
    // File manager: largest file (bytes) that can be opened or saved from the dashboard.
    fileMaxBytes: Number(env.BRIDGE_FILE_MAX || 512 * 1024),
    log: env.BRIDGE_LOG !== '0',
  };
}
