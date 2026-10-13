import { chmodSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { BridgeError } from './errors.js';

/**
 * The agent's own browser: Playwright MCP (a headless Chromium driven over
 * stdio) registered as the MCP server `browser` of every conversation that
 * Studio gives a preview to look at.
 *
 * Studio sends, with each turn, the address of the preview and the pass cookie
 * of that host (the one its gatekeeper hands a signed-in person): the browser
 * starts with it in its storage state, so it sees the preview as the person
 * does, through the same access rules, and nothing else of Studio's.
 *
 * The browser is the server's, installed by Studio's installer: BRIDGE_BROWSER_MCP
 * is the command (`playwright-mcp`), unset means no browser in this workspace.
 */
export const BROWSER_SERVER = 'browser';

/** What the agent may do with the browser without asking, in every mode: look, never act. */
export const BROWSER_READ_TOOLS = [
  'browser_navigate',
  'browser_navigate_back',
  'browser_navigate_forward',
  'browser_reload',
  'browser_snapshot',
  'browser_take_screenshot',
  'browser_console_messages',
  'browser_network_requests',
  'browser_tabs',
  'browser_wait_for',
  'browser_resize',
  'browser_close',
].map((tool) => `mcp__${BROWSER_SERVER}__${tool}`);

/**
 * @returns {{url: string, host: string, cookies: Array<object>}|null}
 */
export function normalizeBrowser(input) {
  if (input === undefined || input === null) return null;
  if (typeof input !== 'object' || Array.isArray(input)) throw new BridgeError(422, 'browser must be an object');

  let url;
  try {
    url = new URL(String(input.url ?? ''));
  } catch {
    throw new BridgeError(422, 'browser.url must be an http(s) address');
  }
  if (!['http:', 'https:'].includes(url.protocol)) throw new BridgeError(422, 'browser.url must be an http(s) address');

  const cookies = [];
  for (const cookie of Array.isArray(input.cookies) ? input.cookies : []) {
    if (!cookie || typeof cookie.name !== 'string' || typeof cookie.value !== 'string' || !/^[A-Za-z0-9_.-]{1,64}$/.test(cookie.name)) {
      throw new BridgeError(422, 'browser.cookies: each needs a name and a value');
    }
    // a cookie only for the preview's own host: the browser must not carry it anywhere else
    cookies.push({
      name: cookie.name,
      value: cookie.value,
      domain: url.hostname,
      path: '/',
      expires: Number.isFinite(cookie.expires) ? cookie.expires : -1,
      httpOnly: true,
      secure: url.protocol === 'https:',
      sameSite: 'Lax',
    });
  }

  return { url: url.origin + (url.pathname === '/' ? '' : url.pathname), host: url.hostname, cookies };
}

/**
 * The files Playwright MCP starts from, private to the workspace user, and the
 * stdio server entry of --mcp-config. The storage state is written again on
 * every turn (a fresh pass), the process reads it when it next opens the browser.
 */
export function browserServer(key, browser, config) {
  const safe = String(key).replace(/[^A-Za-z0-9_-]/g, '_');
  const dir = join(tmpdir(), `studio-browser-${safe}`);
  mkdirSync(dir, { recursive: true, mode: 0o700 });
  chmodSync(dir, 0o700);

  const state = join(dir, 'storage-state.json');
  writeFileSync(state, JSON.stringify({ cookies: browser.cookies, origins: [] }), { mode: 0o600 });
  chmodSync(state, 0o600);

  const launchOptions = { headless: true };
  if (config.browserExecutable) launchOptions.executablePath = config.browserExecutable;
  if (config.browserNoSandbox) launchOptions.chromiumSandbox = false;
  const settings = join(dir, 'config.json');
  writeFileSync(settings, JSON.stringify({ browser: { browserName: 'chromium', isolated: true, launchOptions } }), { mode: 0o600 });

  const outputDir = join(dir, 'output');
  mkdirSync(outputDir, { recursive: true, mode: 0o700 });

  const env = {};
  if (process.env.PLAYWRIGHT_BROWSERS_PATH) env.PLAYWRIGHT_BROWSERS_PATH = process.env.PLAYWRIGHT_BROWSERS_PATH;

  return {
    type: 'stdio',
    command: config.browserCmd,
    args: [
      '--config', settings,
      '--headless',
      '--isolated',
      '--storage-state', state,
      '--output-dir', outputDir,
      '--viewport-size', config.browserViewport || '1280x800',
      '--image-responses', 'allow',
      // the browser goes with the agent's idle process anyway; this frees it sooner between turns
      '--idle-timeout', String(10 * 60 * 1000),
    ],
    env,
  };
}

export function removeBrowserFiles(key) {
  const safe = String(key).replace(/[^A-Za-z0-9_-]/g, '_');
  rmSync(join(tmpdir(), `studio-browser-${safe}`), { recursive: true, force: true });
}
