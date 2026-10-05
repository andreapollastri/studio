// Screenshot jobs over the DevTools protocol. Node 24: global WebSocket, no deps.
// Usage: node shots.mjs <jobs.json> <outDir>
import { spawn } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const [, , jobsFile, outDir] = process.argv;
const jobs = JSON.parse(await readFile(jobsFile, 'utf8'));
await mkdir(outDir, { recursive: true });

const port = 9333;
const profile = join(tmpdir(), `studio-shots-${Date.now()}`);
const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, '--no-first-run', '--hide-scrollbars', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
await new Promise((r) => setTimeout(r, 1200));

const targets = await (await fetch(`http://127.0.0.1:${port}/json`)).json();
const page = targets.find((t) => t.type === 'page');
const ws = new WebSocket(page.webSocketDebuggerUrl);
await new Promise((r) => (ws.onopen = r));

let id = 0;
const pending = new Map();
const listeners = [];
ws.onmessage = (m) => {
  const msg = JSON.parse(m.data);
  if (msg.id && pending.has(msg.id)) { pending.get(msg.id)(msg); pending.delete(msg.id); }
  else if (msg.method) listeners.forEach((l) => l(msg));
};
const send = (method, params = {}) => new Promise((resolve) => { const i = ++id; pending.set(i, resolve); ws.send(JSON.stringify({ id: i, method, params })); });
const evaluate = async (expression) => (await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })).result?.result?.value;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const waitLoad = () => new Promise((resolve) => { const l = (msg) => { if (msg.method === 'Page.loadEventFired') { listeners.splice(listeners.indexOf(l), 1); resolve(); } }; listeners.push(l); });

await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');

for (const job of jobs) {
  const width = job.width ?? 1440, height = job.height ?? 900;
  await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: job.scale ?? 2, mobile: !!job.mobile });
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: job.dark ? 'dark' : 'light' }] });
  if (job.url) { const loaded = waitLoad(); await send('Page.navigate', { url: job.url }); await loaded; }
  if (job.login) {
    await evaluate(`(() => { document.querySelector('input[name=email]').value = ${JSON.stringify(job.login.email)}; document.querySelector('input[name=password]').value = ${JSON.stringify(job.login.password)}; })()`);
    const loaded = waitLoad();
    await evaluate(`document.querySelector('form[action$="/login"]').submit()`);
    await loaded;
  }
  if (job.eval) { const v = await evaluate(job.eval); if (job.name === "overflow-probe") console.log("PROBE:\n" + v); }
  await sleep(job.wait ?? 600);
  if (job.name) {
    let clip = undefined;
    if (job.clipTo) { const top = await evaluate(`(() => { const el = document.querySelector(${JSON.stringify(job.clipTo)}); const r = el.getBoundingClientRect(); return Math.max(0, Math.round(r.top + window.scrollY) - 90); })()`); clip = { x: 0, y: top, width, height, scale: 1 }; }
    const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: !!job.full || !!clip, ...(clip ? { clip } : {}) });
    await writeFile(join(outDir, `${job.name}.png`), Buffer.from(shot.result.data, 'base64'));
    const overflow = await evaluate('document.documentElement.scrollWidth > document.documentElement.clientWidth');
    console.log(`${job.name}.png ${width}x${height}${job.dark ? ' dark' : ''}${overflow ? '  (horizontal overflow!)' : ''}`);
  }
}

ws.close();
chrome.kill();
