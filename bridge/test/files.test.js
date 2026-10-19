import test from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { lstat, mkdtemp, mkdir, readdir, writeFile, symlink, readFile, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { promisify } from 'node:util';
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

test('an upload streams any bytes into a new file, replaces one only when asked, and keeps to its limit', async () => {
  const root = await workspace();
  const server = createServer(testConfig({ workspaceDir: root, uploadMaxBytes: 64 }));
  const base = await listen(server);
  const auth = { authorization: 'Bearer bridge-secret', 'content-type': 'application/octet-stream' };
  const bytes = Buffer.from([0, 1, 2, 255, 0x89, 0x50]);

  const created = await fetch(`${base}/files/upload?path=storage/drive/blob.bin`, { method: 'PUT', headers: auth, body: bytes });
  assert.equal(created.status, 201);
  assert.deepEqual(await created.json(), { path: 'storage/drive/blob.bin', size: 6 });
  assert.deepEqual(await readFile(join(root, 'storage', 'drive', 'blob.bin')), bytes);

  const again = await fetch(`${base}/files/upload?path=storage/drive/blob.bin`, { method: 'PUT', headers: auth, body: 'x' });
  assert.equal(again.status, 409);
  const replaced = await fetch(`${base}/files/upload?path=storage/drive/blob.bin&overwrite=1`, { method: 'PUT', headers: auth, body: 'x' });
  assert.equal(replaced.status, 201);
  assert.equal(await readFile(join(root, 'storage', 'drive', 'blob.bin'), 'utf8'), 'x');

  const onDir = await fetch(`${base}/files/upload?path=app&overwrite=1`, { method: 'PUT', headers: auth, body: 'x' });
  assert.equal(onDir.status, 409);
  const tooBig = await fetch(`${base}/files/upload?path=big.bin`, { method: 'PUT', headers: auth, body: Buffer.alloc(65) });
  assert.equal(tooBig.status, 413);
  await assert.rejects(stat(join(root, 'big.bin')));
  const escape = await fetch(`${base}/files/upload?path=../x`, { method: 'PUT', headers: auth, body: 'x' });
  assert.equal(escape.status, 400);

  await server.shutdown();
});

test('moving goes through rename: into another folder, never into itself or onto something', async () => {
  const root = await workspace();
  const files = new Files(root);
  await files.create('docs', 'dir');
  assert.deepEqual(await files.rename('README.md', 'docs/README.md'), { from: 'README.md', to: 'docs/README.md' });
  assert.equal(await readFile(join(root, 'docs', 'README.md'), 'utf8'), '# Demo\n');
  await files.rename('app', 'docs/app');
  assert.ok((await stat(join(root, 'docs', 'app', 'Models', 'Order.php'))).isFile());
  await assert.rejects(files.rename('docs', 'docs/app/docs'), (e) => e.status === 400);
  await assert.rejects(files.rename('logo.png', 'docs/README.md'), (e) => e.status === 409);
});

test('a zip holds files and folders of one folder, takes a free name, and stores links as links', async () => {
  const root = await workspace();
  const files = new Files(root);
  await symlink('/etc/hosts', join(root, 'app', 'hosts'));

  const one = await files.zip(['app']);
  assert.equal(one.path, 'app.zip');
  assert.ok(one.size > 0);
  assert.equal((await files.zip('app')).path, 'app 2.zip');
  const several = await files.zip(['README.md', 'logo.png']);
  assert.equal(several.path, 'Archive.zip');
  await files.create('-dash', 'dir');
  assert.equal((await files.zip(['-dash'])).path, '-dash.zip');

  // the link went in as a link: unzipped elsewhere, it is a link again, not a copy of /etc/hosts
  const out = await mkdtemp(join(tmpdir(), 'studio-unzip-'));
  await promisify(execFile)('unzip', ['-q', join(root, 'app.zip'), '-d', out]);
  assert.ok((await lstat(join(out, 'app', 'hosts'))).isSymbolicLink());
  assert.ok((await stat(join(out, 'app', 'Models', 'Order.php'))).isFile());

  await assert.rejects(files.zip(['README.md', 'app/Models']), (e) => e.status === 400);
  await assert.rejects(files.zip([]), (e) => e.status === 400);
  await assert.rejects(files.zip(['nope']), (e) => e.status === 404);
  await assert.rejects(files.zip(['../x']), (e) => e.status === 400);
});

test('unzip puts one folder at the top in place, anything else in a folder of its own, and overwrites nothing', async () => {
  const root = await workspace();
  const files = new Files(root);
  await files.zip(['app']);
  await files.zip(['README.md', 'logo.png']);

  // app.zip holds app/: it becomes "app 2", since app is there
  assert.deepEqual(await files.unzip('app.zip'), { path: 'app 2' });
  assert.equal(await readFile(join(root, 'app 2', 'Models', 'Order.php'), 'utf8'), '<?php\n\nclass Order {}\n');
  assert.equal(await readFile(join(root, 'app', 'Models', 'Order.php'), 'utf8'), '<?php\n\nclass Order {}\n');
  // two files at the top: a folder named after the archive
  assert.deepEqual(await files.unzip('Archive.zip'), { path: 'Archive' });
  assert.equal(await readFile(join(root, 'Archive', 'README.md'), 'utf8'), '# Demo\n');
  // nothing left behind
  assert.deepEqual((await readdir(root)).filter((name) => name.includes('studio-tmp')), []);

  await assert.rejects(files.unzip('README.md'), (e) => e.status === 422);
  await assert.rejects(files.unzip('app'), (e) => e.status === 400);
  await assert.rejects(new Files(root, { unzipMaxBytes: 4 }).unzip('Archive.zip'), (e) => e.status === 413);
});

test('unzip keeps an archive inside the workspace: no .. and no absolute paths', async () => {
  const root = await workspace();
  const files = new Files(root);
  // an archive whose entry climbs out (zip stores ../ when asked from a deeper folder)
  const outside = await mkdtemp(join(tmpdir(), 'studio-evil-'));
  await mkdir(join(outside, 'a', 'b'), { recursive: true });
  await writeFile(join(outside, 'a', 'escaped.txt'), 'gotcha');
  await promisify(execFile)('zip', ['-q', join(root, 'evil.zip'), '../escaped.txt'], { cwd: join(outside, 'a', 'b') });

  const { path } = await files.unzip('evil.zip');
  assert.equal(path, 'escaped.txt');
  assert.equal(await readFile(join(root, 'escaped.txt'), 'utf8'), 'gotcha');
  await assert.rejects(stat(join(root, '..', 'escaped.txt')));
});

test('the download, zip and unzip routes', async () => {
  const root = await workspace();
  const server = createServer(testConfig({ workspaceDir: root }));
  const base = await listen(server);
  const headers = { authorization: 'Bearer bridge-secret', 'content-type': 'application/json' };

  const download = await fetch(`${base}/files/download?path=logo.png`, { headers });
  assert.equal(download.status, 200);
  assert.equal(download.headers.get('content-type'), 'application/octet-stream');
  assert.deepEqual(Buffer.from(await download.arrayBuffer()), Buffer.from([0x89, 0x50, 0x4e, 0x47, 0, 1, 2, 3]));
  assert.equal((await fetch(`${base}/files/download?path=app`, { headers })).status, 400);
  assert.equal((await fetch(`${base}/files/download?path=nope`, { headers })).status, 404);
  assert.equal((await fetch(`${base}/files/download?path=.git/HEAD`, { headers })).status, 403);

  const zipped = await fetch(`${base}/files/zip`, { method: 'POST', headers, body: JSON.stringify({ paths: ['app'] }) });
  assert.equal(zipped.status, 201);
  assert.equal((await zipped.json()).path, 'app.zip');
  const unzipped = await fetch(`${base}/files/unzip`, { method: 'POST', headers, body: JSON.stringify({ path: 'app.zip' }) });
  assert.equal(unzipped.status, 201);
  assert.deepEqual(await unzipped.json(), { path: 'app 2' });

  await server.shutdown();
});
