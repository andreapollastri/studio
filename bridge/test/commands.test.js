import test from 'node:test';
import assert from 'node:assert/strict';
import { parseCommand, RunManager, CommandError } from '../src/commands.js';
import { testConfig, waitFor } from './helpers.js';

test('useful commands pass, dangerous or shell-shaped ones do not', () => {
  assert.deepEqual(parseCommand('php artisan larapilot:doctor --human').args, ['artisan', 'larapilot:doctor', '--human']);
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
