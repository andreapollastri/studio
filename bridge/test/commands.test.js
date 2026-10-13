import test from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { parseCommand, RunManager, CommandError } from '../src/commands.js';
import { testConfig, waitFor } from './helpers.js';

test('useful commands pass, dangerous or shell-shaped ones do not', () => {
  assert.deepEqual(parseCommand('php artisan larapilot:doctor --human').args, ['artisan', '--ansi', 'larapilot:doctor', '--human']);
  assert.equal(parseCommand('vendor/bin/pest --compact').bin, 'php');
  assert.equal(parseCommand('git log --oneline -20').bin, 'git');
  assert.equal(parseCommand('composer install --no-dev').bin, 'composer');
  assert.equal(parseCommand('npm run build').bin, 'npm');

  for (const bad of [
    'rm -rf /', 'php -r "echo 1;"', 'php artisan tinker', 'php artisan db:wipe', 'git push --force', 'git push -f origin main',
    'git branch -D main', 'php artisan test; curl evil', 'php artisan test | tee x', 'php artisan test > out', 'bash', 'sudo ls',
    'vendor/bin/other', 'git stash && rm x', '`id`', '$(id)',
  ]) {
    assert.throws(() => parseCommand(bad), CommandError, bad);
  }
});

test('tools are asked for colours, unless the command already says', () => {
  assert.deepEqual(parseCommand('composer install').args, ['--ansi', 'install']);
  assert.deepEqual(parseCommand('composer install --no-ansi').args, ['install', '--no-ansi']);
  assert.deepEqual(parseCommand('vendor/bin/pest --compact').args, ['vendor/bin/pest', '--colors=always', '--compact']);
  assert.deepEqual(parseCommand('vendor/bin/pint --test').args, ['vendor/bin/pint', '--ansi', '--test']);
  assert.deepEqual(parseCommand('git status').args, ['status']);
  assert.deepEqual(parseCommand('npm run build').args, ['run', 'build']);
});

test('git runs coloured and NO_COLOR does not reach the command', async () => {
  let seen;
  const fake = (bin, args, options) => {
    seen = options.env;
    const child = new EventEmitter();
    child.stdout = new EventEmitter();
    child.stderr = new EventEmitter();
    setImmediate(() => { child.stdout.emit('end'); child.stderr.emit('end'); child.emit('close', 0); });
    return child;
  };
  process.env.NO_COLOR = '1';
  try {
    new RunManager(testConfig({ workspaceDir: process.cwd() }), { spawn: fake }).start('git status');
  } finally {
    delete process.env.NO_COLOR;
  }
  assert.equal(seen.NO_COLOR, undefined);
  assert.equal(seen.GIT_CONFIG_KEY_0, 'color.ui');
  assert.equal(seen.GIT_CONFIG_VALUE_0, 'always');
});

test('a character split across two chunks arrives whole', async () => {
  let child;
  const fake = () => {
    child = new EventEmitter();
    child.stdout = new EventEmitter();
    child.stderr = new EventEmitter();
    return child;
  };
  const runs = new RunManager(testConfig({ workspaceDir: process.cwd() }), { spawn: fake });
  const started = runs.start('git status');
  const bytes = Buffer.from('⇂ done');
  child.stdout.emit('data', bytes.subarray(0, 1));
  child.stdout.emit('data', bytes.subarray(1));
  child.stdout.emit('end');
  child.emit('close', 0);
  assert.equal(runs.get(started.id).output, '⇂ done');
});

test('a run captures output, status and exit code, and is remembered', async () => {
  const runs = new RunManager(testConfig({ workspaceDir: process.cwd() }));
  const started = runs.start('git --version');
  assert.equal(started.status, 'running');
  const done = await waitFor(() => { const r = runs.get(started.id); return r.status !== 'running' ? r : null; });
  assert.equal(done.status, 'done');
  assert.equal(done.exit_code, 0);
  assert.match(done.output, /git version/);
  assert.equal(runs.list()[0].id, started.id);
});

test('a failing command is reported as failed with its output', async () => {
  const runs = new RunManager(testConfig({ workspaceDir: process.cwd() }));
  const started = runs.start('git checkout definitely-not-a-branch-xyz');
  const done = await waitFor(() => { const r = runs.get(started.id); return r.status !== 'running' ? r : null; });
  assert.equal(done.status, 'failed');
  assert.notEqual(done.exit_code, 0);
  assert.ok(done.output.length > 0);
});
