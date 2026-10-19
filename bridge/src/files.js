import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { createReadStream, createWriteStream } from 'node:fs';
import { mkdir, readdir, readFile, realpath, rename, rm, stat, writeFile } from 'node:fs/promises';
import { dirname, join, resolve, sep } from 'node:path';
import { Transform } from 'node:stream';
import { pipeline } from 'node:stream/promises';
import { promisify } from 'node:util';

const run = promisify(execFile);

/**
 * The project file manager: browse, read, write, create, rename (or move),
 * delete, download, zip and unzip inside the workspace directory, and nowhere
 * else. Paths are relative to the workspace, use forward slashes, may not
 * contain `..`, and may not enter `.git`. Symlinks are resolved so a link
 * cannot lead outside the workspace.
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
  constructor(root, { maxBytes = 512 * 1024, unzipMaxBytes = 2 * 1024 * 1024 * 1024 } = {}) {
    this.root = resolve(root);
    this.maxBytes = maxBytes;
    this.unzipMaxBytes = unzipMaxBytes;
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

  /**
   * Any bytes, streamed from a request body into a file (Studio's drive copies
   * files in this way, binary or large). An existing file is replaced only
   * with `overwrite`; a directory never is.
   */
  async upload(input, body, { overwrite = false, maxBytes = 512 * 1024 * 1024 } = {}) {
    const path = cleanPath(input);
    if (path === '') throw new FileError(400, 'a name is required');
    const abs = await this.absolute(path);
    try {
      const info = await stat(abs);
      if (info.isDirectory()) throw new FileError(409, `${path} is a directory`);
      if (!overwrite) throw new FileError(409, `${path} already exists`);
    } catch (error) {
      if (error instanceof FileError) throw error;
      if (error.code !== 'ENOENT') throw this.translate(error, path);
    }

    await mkdir(dirname(abs), { recursive: true });
    const tmp = `${abs}${TMP_SUFFIX}-${process.pid}-${Date.now()}`;
    let size = 0;
    const limit = new Transform({
      transform(chunk, encoding, done) {
        size += chunk.length;
        if (size > maxBytes) return done(new FileError(413, `the file is larger than ${maxBytes} bytes`));
        done(null, chunk);
      },
    });

    try {
      await pipeline(body, limit, createWriteStream(tmp));
      await rename(tmp, abs);
    } catch (error) {
      await rm(tmp, { force: true });
      throw error instanceof FileError ? error : this.translate(error, path);
    }

    return { path, size };
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

  /** A regular file's bytes, streamed (the dashboard's download). */
  async download(input) {
    const path = cleanPath(input);
    if (path === '') throw new FileError(400, 'that is a directory');
    const abs = await this.absolute(path);
    let info;
    try {
      info = await stat(abs);
    } catch (error) {
      throw this.translate(error, path);
    }
    if (info.isDirectory()) throw new FileError(400, 'a folder downloads as a zip: compress it first');
    if (!info.isFile()) throw new FileError(400, 'not a regular file');

    return { path, size: info.size, stream: createReadStream(abs) };
  }

  /**
   * A zip of files and folders of one folder, next to them: `<name>.zip` for one,
   * `Archive.zip` for several, numbered when the name is taken. Symlinks go in as
   * links, so nothing outside the workspace is read into the archive.
   */
  async zip(inputs) {
    const paths = [...new Set((Array.isArray(inputs) ? inputs : [inputs]).map(cleanPath))];
    if (paths.length === 0 || paths.includes('')) throw new FileError(400, 'choose what to compress');
    const parent = parentOf(paths[0]);
    if (paths.some((path) => parentOf(path) !== parent)) throw new FileError(400, 'compress the items of one folder at a time');
    for (const path of paths) {
      try {
        await stat(await this.absolute(path));
      } catch (error) {
        throw this.translate(error, path);
      }
    }

    const to = await this.free(joinPath(parent, `${paths.length === 1 ? nameOf(paths[0]) : 'Archive'}.zip`));
    const absTo = await this.absolute(to);
    const tmp = `${absTo}${TMP_SUFFIX}-${process.pid}-${Date.now()}`;
    try {
      // ./name: a name that starts with a dash stays a name; zip stores it without the ./
      await tool('zip', ['-r', '-q', '-y', tmp, ...paths.map((path) => `./${nameOf(path)}`)], await this.absolute(parent));
      await rename(tmp, absTo);
    } catch (error) {
      await rm(tmp, { force: true });
      throw error instanceof FileError ? error : this.translate(error, to);
    }

    return { path: to, size: (await stat(absTo)).size };
  }

  /**
   * Extract a zip next to it: an archive with one folder (or file) at its top
   * becomes that folder, anything else a folder named after the archive;
   * nothing that exists is overwritten. unzip drops `..` and absolute paths,
   * and an archive that unpacks to more than the limit is refused first.
   */
  async unzip(input) {
    const path = cleanPath(input);
    if (path === '') throw new FileError(400, 'choose a zip');
    const abs = await this.absolute(path);
    try {
      if (!(await stat(abs)).isFile()) throw new FileError(400, 'not a regular file');
    } catch (error) {
      throw this.translate(error, path);
    }

    const totals = await tool('unzip', ['-Zt', abs], dirname(abs), `${path} is not a zip archive`);
    const unpacked = Number(/([0-9]+) bytes uncompressed/.exec(totals)?.[1] ?? NaN);
    if (!Number.isFinite(unpacked)) throw new FileError(422, `${path} is not a zip archive`);
    if (unpacked > this.unzipMaxBytes) throw new FileError(413, `${path} unpacks to ${unpacked} bytes, more than the ${this.unzipMaxBytes} allowed`);

    const parent = parentOf(path);
    const tmp = `${await this.absolute(parent)}${sep}.${nameOf(path)}${TMP_SUFFIX}-${process.pid}-${Date.now()}`;
    try {
      await mkdir(tmp);
      await tool('unzip', ['-q', abs, '-d', tmp], dirname(abs), `${path} could not be extracted`);
      const top = (await readdir(tmp)).filter((name) => name !== '__MACOSX');
      let to;
      // one item at the top, unless that is a .git landing on the workspace's own
      if (top.length === 1 && !(parent === '' && top[0] === '.git')) {
        to = await this.free(joinPath(parent, top[0]));
        await rename(join(tmp, top[0]), await this.absolute(to));
        await rm(tmp, { recursive: true, force: true });
      } else {
        to = await this.free(joinPath(parent, nameOf(path).replace(/\.zip$/i, '') || 'Archive'));
        await rename(tmp, await this.absolute(to));
      }
      return { path: to };
    } catch (error) {
      await rm(tmp, { recursive: true, force: true });
      throw error instanceof FileError ? error : this.translate(error, path);
    }
  }

  /** The path itself when nothing is there, otherwise `name 2.ext`, `name 3.ext`… */
  async free(path) {
    const name = nameOf(path);
    const dot = name.lastIndexOf('.');
    const [stem, ext] = dot > 0 ? [name.slice(0, dot), name.slice(dot)] : [name, ''];
    for (let n = 1; n < 1000; n++) {
      const candidate = joinPath(parentOf(path), n === 1 ? name : `${stem} ${n}${ext}`);
      try {
        await stat(await this.absolute(candidate));
      } catch (error) {
        if (error.code === 'ENOENT') return candidate;
        throw this.translate(error, candidate);
      }
    }
    throw new FileError(409, `too many items named ${name}`);
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

function parentOf(path) {
  const slash = path.lastIndexOf('/');
  return slash === -1 ? '' : path.slice(0, slash);
}

function nameOf(path) {
  return path.slice(path.lastIndexOf('/') + 1);
}

function joinPath(dir, name) {
  return dir === '' ? name : `${dir}/${name}`;
}

/** zip and unzip, without a shell; what they say on failure becomes the error. */
async function tool(bin, args, cwd, message = null) {
  try {
    const { stdout } = await run(bin, args, { cwd, maxBuffer: 1024 * 1024, timeout: 10 * 60 * 1000 });
    return stdout.toString();
  } catch (error) {
    if (error.code === 'ENOENT') throw new FileError(500, `${bin} is not installed in the workspace`);
    const told = (error.stderr || error.stdout || '').toString().trim().split('\n').slice(-3).join(' ');
    throw new FileError(422, message ?? (told || `${bin} failed`));
  }
}

function hashOf(bytes) {
  return createHash('sha1').update(bytes).digest('hex');
}
