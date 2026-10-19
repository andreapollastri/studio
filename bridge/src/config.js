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
    flushInterval: Number(env.BRIDGE_FLUSH_MS || 100),
    maxBodyBytes: Number(env.BRIDGE_MAX_BODY || 1024 * 1024),
    // Project terminal: seconds a command may run, bytes of output kept, runs remembered.
    runTimeout: Number(env.BRIDGE_RUN_TIMEOUT || 600),
    runOutputLimit: Number(env.BRIDGE_RUN_OUTPUT || 200 * 1024),
    runHistory: Number(env.BRIDGE_RUN_HISTORY || 20),
    // File manager: largest file (bytes) that can be opened or saved from the dashboard.
    fileMaxBytes: Number(env.BRIDGE_FILE_MAX || 512 * 1024),
    // Largest file Studio's drive may copy into the workspace.
    uploadMaxBytes: Number(env.BRIDGE_UPLOAD_MAX || 512 * 1024 * 1024),
    // The Terminal tab: the shell (the user's login shell by default) and how many may be open.
    shell: env.BRIDGE_SHELL || null,
    maxShells: Number(env.BRIDGE_MAX_SHELLS || 8),
    // Largest a zip may unpack to, all its files together.
    unzipMaxBytes: Number(env.BRIDGE_UNZIP_MAX || 2 * 1024 * 1024 * 1024),
    // The agent's browser (Playwright MCP): the command, unset for none; optionally Chromium's path,
    // its sandbox off (only where the kernel refuses it), and the viewport of its screenshots.
    browserCmd: env.BRIDGE_BROWSER_MCP || null,
    browserExecutable: env.BRIDGE_BROWSER_EXECUTABLE || null,
    browserNoSandbox: env.BRIDGE_BROWSER_NO_SANDBOX === '1',
    browserViewport: env.BRIDGE_BROWSER_VIEWPORT || '1280x800',
    // Where the sandboxes' worktrees go: by default <parent>/.sandboxes/<workspace>.
    sandboxRoot: env.BRIDGE_SANDBOX_ROOT || null,
    // Studio's own subagents (code-reviewer, browser-qa) and its paragraph of context.
    studioAgents: env.BRIDGE_STUDIO_AGENTS !== '0',
    log: env.BRIDGE_LOG !== '0',
  };
}
