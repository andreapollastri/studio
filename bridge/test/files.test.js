import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile, symlink, readFile, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Files, FileError, cleanPath } from '../src/files.js';
import { createServer } from '../src/server.js';
import { testConfig, listen } from './helpers.js';

async function workspace() {
  const root = await mkdtemp(join(tmpdir(), 'studio-files-'));
  await mkdir(join(root, 'app', 'Models'), { recursive: true });
  await mkdir(join(root, '.git', 'objects'), { recursive: true });
  await writeFile(join(root, 'app', 'Models', 'Order.php'), '<?php\n\nclass Order {}\n');
  await writeFile(join(root, 'README.md'), '# Demo\n');
  await writeFile(join(root, 'logo.png'), Buffer.from([0x89, 0x50, 0x4e, 0x47, 0, 1, 2, 3]));
  await writeFile(join(root, '.git', 'HEAD'), 'ref: refs/heads/main\n');
  return root;
}

test('paths are relative, forward-slashed, and never leave the workspace or enter .git', () => {
  assert.equal(cleanPath(''), '');
  assert.equal(cleanPath('/app//Models/./Order.php'), 'app/Models/Order.php');
  assert.equal(cleanPath('app\\Models'), 'app/Models');
  for (const bad of ['../etc/passwd', 'app/../../x', '.git/config', '.git', 'a\0b']) {
    assert.throws(() => cleanPath(bad), FileError, bad);
  }
});

test('listing puts folders first and hides .git', async () => {
  const files = new Files(await workspace());
  const root = await files.list('');
  assert.deepEqual(root.entries.map((e) => `${e.type}:${e.name}`), ['dir:app', 'file:logo.png', 'file:README.md']);
  const models = await files.list('app/Models');
  assert.equal(models.path, 'app/Models');
  assert.equal(models.entries[0].name, 'Order.php');
  assert.ok(models.entries[0].size > 0);
  await assert.rejects(files.list('nope'), (e) => e instanceof FileError && e.status === 404);
});

test('reading gives text and a hash, flags binaries, and refuses oversized files', async () => {
  const root = await workspace();
  const files = new Files(root, { maxBytes: 64 });
  const read = await files.read('app/Models/Order.php');
  assert.equal(read.content, '<?php\n\nclass Order {}\n');
  assert.match(read.hash, /^[0-9a-f]{40}$/);
  assert.equal((await files.read('logo.png')).binary, true);
  await writeFile(join(root, 'big.txt'), 'x'.repeat(100));
  assert.equal((await files.read('big.txt')).too_large, true);
  await assert.rejects(files.read('app'), (e) => e.status === 400);
});

test('writing is atomic, creates missing folders, and refuses to overwrite a file that changed', async () => {
  const root = await workspace();
  const files = new Files(root);
  const before = await files.read('README.md');
  const saved = await files.write('README.md', '# Demo\n\nEdited.\n', before.hash);
  assert.equal(await readFile(join(root, 'README.md'), 'utf8'), '# Demo\n\nEdited.\n');
  assert.notEqual(saved.hash, before.hash);

  await assert.rejects(files.write('README.md', 'stale', before.hash), (e) => e.status === 409);
  await files.write('docs/new/notes.md', 'hello', null);
  assert.equal(await readFile(join(root, 'docs', 'new', 'notes.md'), 'utf8'), 'hello');
  await assert.rejects(files.write('../outside.txt', 'x'), (e) => e.status === 400);
});

test('create, rename and delete work on files and folders', async () => {
  const root = await workspace();
  const files = new Files(root);
  await files.create('app/Services', 'dir');
  await files.create('app/Services/Billing.php', 'file');
  await assert.rejects(files.create('app/Services/Billing.php'), (e) => e.status === 409);
  await files.rename('app/Services/Billing.php', 'app/Services/Invoicing.php');
  assert.ok((await stat(join(root, 'app', 'Services', 'Invoicing.php'))).isFile());
  await assert.rejects(files.rename('app', 'app/inside'), (e) => e.status === 400);
  await files.remove('app/Services');
  await assert.rejects(stat(join(root, 'app', 'Services')));
  await assert.rejects(files.remove(''), (e) => e.status === 400);
});

test('a symlink that points outside the workspace is refused', async () => {
  const root = await workspace();
  const outside = await mkdtemp(join(tmpdir(), 'studio-outside-'));
  await writeFile(join(outside, 'secret.txt'), 'top secret');
  await symlink(outside, join(root, 'link'));
  const files = new Files(root);
  await assert.rejects(files.read('link/secret.txt'), (e) => e.status === 403);
  await assert.rejects(files.list('link'), (e) => e.status === 403);
});

test('the HTTP routes expose the file manager behind the bearer token', async () => {
  const root = await workspace();
  const server = createServer(testConfig({ workspaceDir: root, fileMaxBytes: 1024 }));
  const base = await listen(server);
  const headers = { authorization: 'Bearer bridge-secret', 'content-type': 'application/json' };

  const unauthorized = await fetch(`${base}/files`);
  assert.equal(unauthorized.status, 401);

  const listing = await (await fetch(`${base}/files?path=app`, { headers })).json();
  assert.equal(listing.entries[0].name, 'Models');

  const read = await (await fetch(`${base}/files/read?path=README.md`, { headers })).json();
  assert.equal(read.content, '# Demo\n');

  const put = await fetch(`${base}/files`, { method: 'PUT', headers, body: JSON.stringify({ path: 'README.md', content: '# Changed\n', hash: read.hash }) });
  assert.equal(put.status, 200);
  const stale = await fetch(`${base}/files`, { method: 'PUT', headers, body: JSON.stringify({ path: 'README.md', content: 'again', hash: read.hash }) });
  assert.equal(stale.status, 409);

  const created = await fetch(`${base}/files`, { method: 'POST', headers, body: JSON.stringify({ path: 'notes', type: 'dir' }) });
  assert.equal(created.status, 201);
  const renamed = await fetch(`${base}/files/rename`, { method: 'POST', headers, body: JSON.stringify({ from: 'notes', to: 'docs' }) });
  assert.equal(renamed.status, 200);
  const removed = await fetch(`${base}/files?path=docs`, { method: 'DELETE', headers });
  assert.equal(removed.status, 200);
  const escape = await fetch(`${base}/files?path=../`, { headers });
  assert.equal(escape.status, 400);

  await server.shutdown();
});
