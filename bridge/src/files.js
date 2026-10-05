import { createHash } from 'node:crypto';
import { mkdir, readdir, readFile, realpath, rename, rm, stat, writeFile } from 'node:fs/promises';
import { dirname, join, resolve, sep } from 'node:path';

/**
 * The project file manager: browse, read, write, create, rename and delete
 * inside the workspace directory, and nowhere else. Paths are relative to
 * the workspace, use forward slashes, may not contain `..`, and may not enter
 * `.git`. Symlinks are resolved so a link cannot lead outside the workspace.
 */
export class FileError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

const BINARY_PROBE = 8000;
const TMP_SUFFIX = '.studio-tmp';

/** Normalise a workspace-relative path; '' is the workspace itself. */
export function cleanPath(input) {
  const raw = String(input ?? '').replace(/\\/g, '/').trim();
  if (raw.includes('\0')) throw new FileError(400, 'invalid path');
  const segments = raw.split('/').filter((s) => s !== '' && s !== '.');
  if (segments.some((s) => s === '..')) throw new FileError(400, 'paths may not leave the workspace');
  if (segments[0] === '.git') throw new FileError(403, 'the .git directory is not browsable');
  if (segments.some((s) => s.endsWith(TMP_SUFFIX))) throw new FileError(400, 'reserved name');
  return segments.join('/');
}

export class Files {
  constructor(root, { maxBytes = 512 * 1024 } = {}) {
    this.root = resolve(root);
    this.maxBytes = maxBytes;
  }

  /** Directory listing: folders first, then files, `.git` left out. */
  async list(input) {
    const path = cleanPath(input);
    const abs = await this.absolute(path);
    let dirents;
    try {
      dirents = await readdir(abs, { withFileTypes: true });
    } catch (error) {
      throw this.translate(error, path);
    }

    const entries = [];
    for (const dirent of dirents) {
      if (dirent.name === '.git') continue;
      const entry = { name: dirent.name, type: dirent.isDirectory() ? 'dir' : dirent.isFile() ? 'file' : 'other', size: 0, mtime: null };
      if (dirent.isSymbolicLink()) {
        try {
          const target = await stat(join(abs, dirent.name));
          entry.type = target.isDirectory() ? 'dir' : target.isFile() ? 'file' : 'other';
        } catch {
          entry.type = 'other';
        }
      }
      if (entry.type === 'file') {
        try {
          const info = await stat(join(abs, dirent.name));
          entry.size = info.size;
          entry.mtime = info.mtime.toISOString();
        } catch {
          /* listed without details */
        }
      }
      entries.push(entry);
    }

    const rank = { dir: 0, file: 1, other: 2 };
    entries.sort((a, b) => rank[a.type] - rank[b.type] || a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));

    return { path, entries };
  }

  /** A file's text and the hash of its bytes; binary or oversized files come back without content. */
  async read(input) {
    const path = cleanPath(input);
    if (path === '') throw new FileError(400, 'that is a directory');
    const abs = await this.absolute(path);
    let info;
    try {
      info = await stat(abs);
    } catch (error) {
      throw this.translate(error, path);
    }
    if (info.isDirectory()) throw new FileError(400, 'that is a directory');
    if (!info.isFile()) throw new FileError(400, 'not a regular file');
    if (info.size > this.maxBytes) return { path, size: info.size, binary: false, too_large: true, limit: this.maxBytes };

    const bytes = await readFile(abs);
    if (bytes.subarray(0, BINARY_PROBE).includes(0)) return { path, size: bytes.length, binary: true };

    return { path, size: bytes.length, binary: false, content: bytes.toString('utf8'), hash: hashOf(bytes) };
  }

  /**
   * Write text to a file, creating it if needed. When `expectedHash` is given
   * and the file on disk no longer matches it, nothing is written (409).
   */
  async write(input, content, expectedHash = null) {
    const path = cleanPath(input);
    if (path === '') throw new FileError(400, 'that is a directory');
    if (typeof content !== 'string') throw new FileError(400, 'content must be a string');
    const bytes = Buffer.from(content, 'utf8');
    if (bytes.length > this.maxBytes) throw new FileError(413, `the file is larger than ${this.maxBytes} bytes`);

    const abs = await this.absolute(path);
    try {
      const info = await stat(abs);
      if (info.isDirectory()) throw new FileError(400, 'that is a directory');
      if (expectedHash && hashOf(await readFile(abs)) !== expectedHash) {
        throw new FileError(409, 'the file changed on disk since it was opened');
      }
    } catch (error) {
      if (error instanceof FileError) throw error;
      if (error.code !== 'ENOENT') throw this.translate(error, path);
    }

    await mkdir(dirname(abs), { recursive: true });
    const tmp = `${abs}${TMP_SUFFIX}-${process.pid}`;
    await writeFile(tmp, bytes);
    await rename(tmp, abs);

    return { path, size: bytes.length, hash: hashOf(bytes) };
  }

  /** A new empty file or a new directory; refuses to overwrite. */
  async create(input, type = 'file') {
    const path = cleanPath(input);
    if (path === '') throw new FileError(400, 'a name is required');
    if (!['file', 'dir'].includes(type)) throw new FileError(400, 'type must be file or dir');
    const abs = await this.absolute(path);
    try {
      await stat(abs);
      throw new FileError(409, 'something with that name already exists');
    } catch (error) {
      if (error instanceof FileError) throw error;
      if (error.code !== 'ENOENT') throw this.translate(error, path);
    }

    if (type === 'dir') {
      await mkdir(abs, { recursive: true });
    } else {
      await mkdir(dirname(abs), { recursive: true });
      await writeFile(abs, '', { flag: 'wx' });
    }

    return { path, type };
  }

  async rename(fromInput, toInput) {
    const from = cleanPath(fromInput);
    const to = cleanPath(toInput);
    if (from === '' || to === '') throw new FileError(400, 'a name is required');
    if (from === to) return { from, to };
    if (to.startsWith(`${from}/`)) throw new FileError(400, 'cannot move a folder into itself');
    const absFrom = await this.absolute(from);
    const absTo = await this.absolute(to);
    try {
      await stat(absFrom);
    } catch (error) {
      throw this.translate(error, from);
    }
    try {
      await stat(absTo);
      throw new FileError(409, 'something with that name already exists');
    } catch (error) {
      if (error instanceof FileError) throw error;
      if (error.code !== 'ENOENT') throw this.translate(error, to);
    }

    await mkdir(dirname(absTo), { recursive: true });
    await rename(absFrom, absTo);

    return { from, to };
  }

  /** Delete a file, or a directory with everything in it. */
  async remove(input) {
    const path = cleanPath(input);
    if (path === '') throw new FileError(400, 'the workspace itself cannot be deleted');
    const abs = await this.absolute(path);
    try {
      await stat(abs);
    } catch (error) {
      throw this.translate(error, path);
    }
    await rm(abs, { recursive: true, force: true });

    return { path };
  }

  /** The absolute path, after checking that no symlink along it leaves the workspace. */
  async absolute(path) {
    const abs = path === '' ? this.root : join(this.root, ...path.split('/'));
    const rootReal = await realpath(this.root);
    let probe = abs;
    for (;;) {
      try {
        const real = await realpath(probe);
        if (real !== rootReal && !real.startsWith(rootReal + sep)) throw new FileError(403, 'paths may not leave the workspace');
        break;
      } catch (error) {
        if (error instanceof FileError) throw error;
        if (error.code !== 'ENOENT') throw error;
        const parent = dirname(probe);
        if (parent === probe) break;
        probe = parent;
      }
    }
    return abs;
  }

  translate(error, path) {
    if (error instanceof FileError) return error;
    if (error.code === 'ENOENT') return new FileError(404, `${path || '/'} does not exist`);
    if (error.code === 'ENOTDIR') return new FileError(400, `${path} is not a directory`);
    if (error.code === 'EACCES' || error.code === 'EPERM') return new FileError(403, `${path} is not accessible`);
    return new FileError(500, error.message || 'file operation failed');
  }
}

function hashOf(bytes) {
  return createHash('sha1').update(bytes).digest('hex');
}
