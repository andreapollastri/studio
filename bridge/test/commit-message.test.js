import test from 'node:test';
import assert from 'node:assert/strict';
import { execFile, spawn as spawnReal } from 'node:child_process';
import { mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { promisify } from 'node:util';
import { clean, suggestCommitMessage } from '../src/commit-message.js';

const git = (dir, ...args) => promisify(execFile)('git', ['-c', 'user.name=t', '-c', 'user.email=t@t', ...args], { cwd: dir });

async function repo() {
  const dir = await mkdtemp(join(tmpdir(), 'studio-msg-'));
  await git(dir, 'init', '-q', '-b', 'main');
  await writeFile(join(dir, 'Order.php'), '<?php\nclass Order {}\n');
  await git(dir, 'add', '-A');
  await git(dir, 'commit', '-q', '-m', 'Add the Order model');
  await writeFile(join(dir, 'Order.php'), '<?php\nclass Order { public function scopeBetween() {} }\n');
  await writeFile(join(dir, 'OrderFilterTest.php'), '<?php\n// the date range filter\n');
  return dir;
}

/** A stand-in for `claude -p`: keeps what it was given, answers with a fenced message. */
function fakeClaude(seen, answer = '```\nAdd a date range filter to orders\n\nThe back office lists orders between two dates.\n```') {
  return (bin, args, options) => {
    seen.push({ bin, args, env: options.env, cwd: options.cwd });
    const child = spawnReal(process.execPath, ['-e', `let s='';process.stdin.on('data',d=>s+=d);process.stdin.on('end',()=>{require('fs').writeFileSync(${JSON.stringify(join(tmpdir(), 'studio-msg-prompt'))}, s);process.stdout.write(${JSON.stringify(answer)})})`]);
    return child;
  };
}

test('Claude is given the changes and the house style, on a small model, and its message comes back clean', async () => {
  const dir = await repo();
  const seen = [];
  const { readFile } = await import('node:fs/promises');

  const result = await suggestCommitMessage(dir, { all: true, auth: 'api_key', api_key: 'sk-ant-api03-test', model: 'claude-haiku-4-5' }, { claudeBin: 'claude' }, { spawn: fakeClaude(seen) });

  assert.equal(result.message, 'Add a date range filter to orders\n\nThe back office lists orders between two dates.');
  assert.deepEqual(seen[0].args, ['-p', '--output-format', 'text', '--model', 'claude-haiku-4-5', '--permission-mode', 'plan', '--max-turns', '2']);
  assert.equal(seen[0].env.ANTHROPIC_API_KEY, 'sk-ant-api03-test');
  const prompt = await readFile(join(tmpdir(), 'studio-msg-prompt'), 'utf8');
  assert.match(prompt, /Recent commits:\nAdd the Order model/);
  assert.match(prompt, /scopeBetween/);
  assert.match(prompt, /OrderFilterTest\.php/);
});

test('staged only, nothing staged: nothing to describe; an odd model name falls back to the small one', async () => {
  const dir = await repo();
  await assert.rejects(suggestCommitMessage(dir, { all: false }, { claudeBin: 'claude' }, { spawn: fakeClaude([]) }), /nothing is staged/);

  await git(dir, 'add', 'Order.php');
  const seen = [];
  await suggestCommitMessage(dir, { all: false, model: 'gpt; rm -rf /' }, { claudeBin: 'claude' }, { spawn: fakeClaude(seen, 'Add the between scope') });
  assert.equal(seen[0].args[seen[0].args.indexOf('--model') + 1], 'claude-haiku-4-5');
});

test('a failing Claude says why; the message is cleaned of quotes and labels', async () => {
  const dir = await repo();
  const failing = () => spawnReal(process.execPath, ['-e', "process.stderr.write('Invalid API key');process.exit(1)"]);
  await assert.rejects(suggestCommitMessage(dir, {}, { claudeBin: 'claude' }, { spawn: failing }), /could not write the message: Invalid API key/);

  assert.equal(clean('"Fix the badge colour"'), 'Fix the badge colour');
  assert.equal(clean('Commit message: Fix the badge colour'), 'Fix the badge colour');
  assert.equal(clean('```text\nFix it\n```'), 'Fix it');
});
