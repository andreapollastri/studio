import { spawn as spawnProcess } from 'node:child_process';
import { BridgeError } from './errors.js';
import { gitChangesForMessage } from './git.js';
import { claudeEnv, normalizeAccount } from './session.js';

const MODEL = /^claude-[a-z0-9.-]{1,60}$/;

/**
 * A commit message for what is about to be committed, written by Claude: a Claude Code of its own
 * (not the conversation's), one quick turn on a small model, plan mode and no tools, with the
 * person's Claude account as for their turns. The Changes tab puts it in the message field to edit.
 */
export async function suggestCommitMessage(workspaceDir, body, config, { spawn = spawnProcess } = {}) {
  const account = normalizeAccount(body);
  if (body.auth != null && !['subscription', 'api_key'].includes(body.auth)) throw new BridgeError(422, 'unknown auth');
  const model = MODEL.test(String(body.model ?? '')) ? body.model : 'claude-haiku-4-5';

  const changes = await gitChangesForMessage(workspaceDir, { all: body.all !== false });
  if (changes.diff.trim() === '' && changes.untracked.length === 0) {
    throw new BridgeError(409, body.all === false ? 'nothing is staged to describe' : 'there are no changes to describe');
  }

  const prompt = [
    "Write the git commit message for the changes below. Follow the style of this repository's recent commits when there are some.",
    'First line: what the change does, in the imperative, under 72 characters, no period at the end.',
    'Then, only when the change needs it, a blank line and a short body that says why, wrapped at 72 columns; a list when it holds several separate changes.',
    'Reply with the message alone: no quotes, no code fences, nothing before or after it. Do not use any tool: everything you need is here.',
    '',
    changes.recent ? `Recent commits:\n${changes.recent}\n` : '',
    `Changes (${body.all === false ? 'staged' : 'everything not committed'}):`,
    changes.stat,
    '',
    changes.diff,
  ].join('\n');

  const args = ['-p', '--output-format', 'text', '--model', model, '--permission-mode', 'plan', '--max-turns', '2'];
  const env = claudeEnv(process.env, body.auth ?? undefined, { apiKey: account.apiKey, claudeDir: account.claudeDir });
  const output = await runOnce(spawn, config.claudeBin, args, { cwd: workspaceDir, env, input: prompt, timeoutMs: config.commitMessageTimeout ?? 120_000 });

  const message = clean(output);
  if (message === '') throw new BridgeError(502, 'Claude gave no message: try again, or write it yourself.');
  return { message, model };
}

/** The text alone: no fences, no quotes around it, no "Commit message:" in front. */
export function clean(text) {
  let message = String(text ?? '').trim();
  message = message.replace(/^```[a-z]*\n([\s\S]*?)\n```$/i, '$1').trim();
  message = message.replace(/^(commit message|message)\s*:\s*/i, '').trim();
  if (/^["'`].*["'`]$/s.test(message)) message = message.slice(1, -1).trim();
  return message;
}

function runOnce(spawn, bin, args, { cwd, env, input, timeoutMs }) {
  return new Promise((resolve, reject) => {
    const child = spawn(bin, args, { cwd, env, stdio: ['pipe', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';
    const timer = setTimeout(() => {
      child.kill('SIGTERM');
      reject(new BridgeError(504, 'Claude took too long to write the message: try again, or write it yourself.'));
    }, timeoutMs);
    child.stdout.setEncoding('utf8');
    child.stderr.setEncoding('utf8');
    child.stdout.on('data', (chunk) => { stdout += chunk; });
    child.stderr.on('data', (chunk) => { stderr += chunk; });
    child.on('error', (error) => {
      clearTimeout(timer);
      reject(new BridgeError(502, error.code === 'ENOENT' ? 'Claude Code is not installed in the workspace.' : error.message));
    });
    child.on('close', (code) => {
      clearTimeout(timer);
      if (code === 0) return resolve(stdout);
      const said = (stderr || stdout).trim().split('\n').slice(-3).join(' ');
      reject(new BridgeError(502, `Claude could not write the message${said ? `: ${said}` : ''}.`));
    });
    child.stdin.on('error', () => {});
    child.stdin.end(input);
  });
}
