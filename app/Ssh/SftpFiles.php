<?php

namespace App\Ssh;

use App\Files\FileStore;
use App\Files\FileStoreException;
use App\Infrastructure\ConnectorException;
use App\Models\SshConnection;
use App\Models\User;
use Illuminate\Support\Str;
use phpseclib3\Net\SFTP;

/**
 * The files of a server for the file manager of the SSH page, over SFTP with
 * the person's own key or password (RemoteFiles::open: never the owner's, and
 * only on a host whose key was accepted). Paths are relative to a folder of
 * the server, the root, which is the person's home unless they go elsewhere.
 * Nothing here can do more than the same person could in the terminal.
 *
 * zip and unzip run on the server through the same SSH session.
 */
class SftpFiles implements FileStore
{
    /** The file types of SFTP's attributes. */
    private const TYPE_REGULAR = 1;

    private const TYPE_DIRECTORY = 2;

    private const TYPE_SYMLINK = 3;

    private const BINARY_PROBE = 8000;

    public function __construct(
        private readonly SshConnection $connection,
        private readonly User $user,
        private string $root = '',
        private ?SFTP $sftp = null,
        private readonly int $maxBytes = 512 * 1024,
    ) {}

    /** The folder the paths start from: the one given, or the home of the user on the server. */
    public function root(): string
    {
        if ($this->root === '') {
            $home = $this->sftp()->realpath('.');
            $this->root = is_string($home) && $home !== '' ? $home : '/';
        }

        return $this->root;
    }

    /** A folder of the server as typed (absolute, ~ or ~/…), resolved; null when it is not a folder. */
    public function resolveDir(string $path): ?string
    {
        $path = trim($path);
        $path = match (true) {
            $path === '' || $path === '~' => '.',
            str_starts_with($path, '~/') => substr($path, 2),
            default => $path,
        };

        if (str_contains($path, "\0")) {
            return null;
        }

        $real = $this->sftp()->realpath($path);

        return is_string($real) && $this->typeOf($real) === 'dir' ? $real : null;
    }

    /** Where a path of the tree is on the server. */
    public function absolute(string $path): string
    {
        $path = $this->clean($path);
        $root = rtrim($this->root(), '/');

        return $path === '' ? ($root === '' ? '/' : $root) : $root.'/'.$path;
    }

    public function listFiles(string $path = ''): array
    {
        $path = $this->clean($path);
        $abs = $this->absolute($path);
        $sftp = $this->sftp();
        $list = $sftp->rawlist($abs);

        if (! is_array($list)) {
            throw $this->failure($path);
        }

        $entries = [];

        foreach ($list as $name => $attributes) {
            $name = (string) $name;

            if ($name === '.' || $name === '..') {
                continue;
            }

            $attributes = (array) $attributes;

            // a link is what it points to; one that points nowhere is listed as other
            if (($attributes['type'] ?? null) === self::TYPE_SYMLINK) {
                $target = $sftp->stat($abs.'/'.$name);
                $attributes = is_array($target) ? $target : ['type' => 0];
            }

            $type = $this->type($attributes);
            $file = $type === 'file';

            $entries[] = [
                'name' => $name,
                'type' => $type,
                'size' => $file ? (int) ($attributes['size'] ?? 0) : 0,
                'mtime' => $file && isset($attributes['mtime']) ? gmdate('Y-m-d\TH:i:s.000\Z', (int) $attributes['mtime']) : null,
            ];
        }

        $rank = ['dir' => 0, 'file' => 1, 'other' => 2];
        usort($entries, fn (array $a, array $b): int => $rank[$a['type']] <=> $rank[$b['type']] ?: strcasecmp($a['name'], $b['name']));

        return ['path' => $path, 'entries' => $entries];
    }

    public function readFile(string $path): array
    {
        $path = $this->clean($path);
        $stat = $this->regular($path);
        $size = (int) ($stat['size'] ?? 0);

        if ($size > $this->maxBytes) {
            return ['path' => $path, 'size' => $size, 'binary' => false, 'too_large' => true, 'limit' => $this->maxBytes];
        }

        $bytes = $this->sftp()->get($this->absolute($path));

        if (! is_string($bytes)) {
            throw $this->failure($path);
        }

        if (str_contains(substr($bytes, 0, self::BINARY_PROBE), "\0")) {
            return ['path' => $path, 'size' => strlen($bytes), 'binary' => true];
        }

        return ['path' => $path, 'size' => strlen($bytes), 'binary' => false, 'content' => $bytes, 'hash' => sha1($bytes)];
    }

    public function writeFile(string $path, string $content, ?string $hash = null): array
    {
        $path = $this->notTop($path);
        $abs = $this->absolute($path);
        $type = $this->typeOf($abs);

        if ($type === 'dir') {
            throw new FileStoreException(__(':path is a folder.', ['path' => $path]), 409);
        }

        if ($hash !== null && $type !== null) {
            $current = $this->sftp()->get($abs);

            if (is_string($current) && sha1($current) !== $hash) {
                throw new FileStoreException(__('The file changed on the server since it was opened.'), 409);
            }
        }

        // written in place: the file keeps its owner and permissions
        if (! $this->sftp()->put($abs, $content)) {
            throw $this->failure($path);
        }

        return ['path' => $path, 'size' => strlen($content), 'hash' => sha1($content)];
    }

    public function uploadFile(string $path, $stream, bool $overwrite = false): array
    {
        $path = $this->notTop($path);
        $abs = $this->absolute($path);
        $type = $this->typeOf($abs);

        if ($type === 'dir') {
            throw new FileStoreException(__(':path is a folder.', ['path' => $path]), 409);
        }

        if ($type !== null && ! $overwrite) {
            throw new FileStoreException(__(':path already exists.', ['path' => $path]), 409);
        }

        $this->makeParent($abs);

        if (! $this->sftp()->put($abs, $stream)) {
            throw $this->failure($path);
        }

        return ['path' => $path, 'size' => (int) ($this->sftp()->stat($abs)['size'] ?? 0)];
    }

    public function createPath(string $path, string $type = 'file'): array
    {
        $path = $this->notTop($path);
        $abs = $this->absolute($path);

        if ($this->typeOf($abs) !== null) {
            throw new FileStoreException(__('Something with that name already exists.'), 409);
        }

        $done = $type === 'dir' ? $this->sftp()->mkdir($abs) : $this->sftp()->put($abs, '');

        if (! $done) {
            throw $this->failure($path);
        }

        return ['path' => $path];
    }

    public function renamePath(string $from, string $to): array
    {
        $from = $this->notTop($from);
        $to = $this->notTop($to);

        if ($this->typeOf($this->absolute($from)) === null) {
            throw new FileStoreException(__(':path does not exist.', ['path' => $from]), 404);
        }

        if ($this->typeOf($this->absolute($to)) !== null) {
            throw new FileStoreException(__('Something with that name already exists.'), 409);
        }

        if (! $this->sftp()->rename($this->absolute($from), $this->absolute($to))) {
            throw $this->failure($from);
        }

        return ['path' => $to];
    }

    public function deletePath(string $path): array
    {
        $path = $this->notTop($path);
        $abs = $this->absolute($path);

        if ($this->typeOf($abs) === null) {
            throw new FileStoreException(__(':path does not exist.', ['path' => $path]), 404);
        }

        if (! $this->sftp()->delete($abs, true)) {
            throw $this->failure($path);
        }

        return ['path' => $path];
    }

    public function zipPaths(array $paths): array
    {
        $paths = array_map(fn (string $path): string => $this->notTop($path), $paths);

        if ($paths === []) {
            throw new FileStoreException(__('Choose what to compress.'), 400);
        }

        $parent = $this->parentOf($paths[0]);
        $to = $this->free($this->join($parent, (count($paths) === 1 ? basename($paths[0]) : 'Archive').'.zip'));
        $absTo = $this->absolute($to);

        try {
            // ./name: a name that starts with a dash stays a name; zip stores it without the ./
            $this->run('cd '.escapeshellarg($this->absolute($parent)).' && zip -r -q -y '.escapeshellarg($absTo).' '.implode(' ', array_map(fn (string $path): string => escapeshellarg('./'.basename($path)), $paths)), 'zip');
        } catch (FileStoreException $e) {
            $this->sftp()->delete($absTo, false);

            throw $e;
        }

        return ['path' => $to, 'size' => (int) ($this->sftp()->stat($absTo)['size'] ?? 0)];
    }

    public function unzipPath(string $path): array
    {
        $path = $this->notTop($path);
        $this->regular($path);

        $parent = $this->parentOf($path);
        $tmp = $this->absolute($this->join($parent, '.'.basename($path).'.studio-tmp-'.Str::lower(Str::random(8))));

        try {
            $this->run('mkdir '.escapeshellarg($tmp).' && unzip -q '.escapeshellarg($this->absolute($path)).' -d '.escapeshellarg($tmp), 'unzip');

            $top = array_values(array_filter(
                array_map('strval', array_keys((array) $this->sftp()->rawlist($tmp))),
                fn (string $name): bool => ! in_array($name, ['.', '..', '__MACOSX'], true),
            ));

            if (count($top) === 1) {
                $to = $this->free($this->join($parent, $top[0]));
                $moved = $this->sftp()->rename($tmp.'/'.$top[0], $this->absolute($to));
            } else {
                $to = $this->free($this->join($parent, preg_replace('/\.zip$/i', '', basename($path)) ?: 'Archive'));
                $moved = $this->sftp()->rename($tmp, $this->absolute($to));
            }

            if (! $moved) {
                throw $this->failure($to);
            }

            return ['path' => $to];
        } finally {
            if ($this->typeOf($tmp) !== null) {
                $this->sftp()->delete($tmp, true);
            }
        }
    }

    /** The size of a file to download; throws (404) for anything that is not a file. */
    public function fileSize(string $path): int
    {
        return (int) ($this->regular($this->clean($path))['size'] ?? 0);
    }

    /** @param  resource  $to */
    public function download(string $path, $to): void
    {
        if ($this->sftp()->get($this->absolute($path), $to) === false) {
            throw $this->failure($path);
        }
    }

    private function sftp(): SFTP
    {
        if ($this->sftp === null) {
            try {
                $this->sftp = app(RemoteFiles::class)->open($this->connection, $this->user);
            } catch (ConnectorException $e) {
                throw new FileStoreException($e->getMessage(), 502, $e);
            }
        }

        $this->sftp->disableStatCache();

        return $this->sftp;
    }

    /** A command on the server; what it says on failure becomes the error. */
    private function run(string $command, string $tool): void
    {
        $sftp = $this->sftp();
        $output = (string) $sftp->exec($command.' 2>&1');
        $status = $sftp->getExitStatus();

        if ($status === 127) {
            throw new FileStoreException(__(':tool is not installed on :host.', ['tool' => $tool, 'host' => $this->connection->host]), 500);
        }

        if ($status !== 0 && $status !== false) {
            $told = implode(' ', array_slice(explode("\n", trim($output)), -3));

            throw new FileStoreException($told !== '' ? $told : __(':tool failed.', ['tool' => $tool]), 422);
        }
    }

    /** @return array<string, mixed> the attributes of a regular file */
    private function regular(string $path): array
    {
        if ($path === '') {
            throw new FileStoreException(__('That is a folder.'), 400);
        }

        $stat = $this->sftp()->stat($this->absolute($path));

        if (! is_array($stat)) {
            throw new FileStoreException(__(':path does not exist.', ['path' => $path]), 404);
        }

        if ($this->type($stat) !== 'file') {
            throw new FileStoreException($this->type($stat) === 'dir' ? __('That is a folder.') : __('Not a regular file.'), 400);
        }

        return $stat;
    }

    /** 'dir', 'file', 'other', or null when nothing is there. */
    private function typeOf(string $abs): ?string
    {
        $stat = $this->sftp()->stat($abs);

        return is_array($stat) ? $this->type($stat) : null;
    }

    /** @param  array<string, mixed>  $attributes */
    private function type(array $attributes): string
    {
        return match ($attributes['type'] ?? null) {
            self::TYPE_DIRECTORY => 'dir',
            self::TYPE_REGULAR => 'file',
            default => 'other',
        };
    }

    private function makeParent(string $abs): void
    {
        $dir = dirname($abs);

        if ($this->typeOf($dir) === null && ! $this->sftp()->mkdir($dir, -1, true)) {
            throw new FileStoreException(__('Could not create :path.', ['path' => $dir]), 403);
        }
    }

    /** The path itself when nothing is there, otherwise `name 2.ext`, `name 3.ext`… */
    private function free(string $path): string
    {
        $name = basename($path);
        $dot = strrpos($name, '.');
        [$stem, $ext] = $dot !== false && $dot > 0 ? [substr($name, 0, $dot), substr($name, $dot)] : [$name, ''];

        for ($n = 1; $n < 1000; $n++) {
            $candidate = $this->join($this->parentOf($path), $n === 1 ? $name : "{$stem} {$n}{$ext}");

            if ($this->typeOf($this->absolute($candidate)) === null) {
                return $candidate;
            }
        }

        throw new FileStoreException(__('Too many items named :name.', ['name' => $name]), 409);
    }

    /** What SFTP said about the last operation, as a status and a sentence. */
    private function failure(string $path): FileStoreException
    {
        $error = $this->sftp()->getLastSFTPError();
        $shown = $path === '' ? '/' : $path;

        return match (true) {
            str_contains($error, 'NO_SUCH_FILE') => new FileStoreException(__(':path does not exist.', ['path' => $shown]), 404),
            str_contains($error, 'PERMISSION_DENIED') => new FileStoreException(__('Permission denied on :path.', ['path' => $shown]), 403),
            default => new FileStoreException(__('SFTP could not do it on :path: :reason', ['path' => $shown, 'reason' => $error !== '' ? $error : __('no reason given')]), 500),
        };
    }

    /** A path under the root: no "..", no NUL. */
    private function clean(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new FileStoreException(__('Invalid path.'), 400);
        }

        $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $path)), fn (string $s): bool => $s !== '' && $s !== '.'));

        if (in_array('..', $segments, true)) {
            throw new FileStoreException(__('Paths stay under the folder the tree shows.'), 400);
        }

        return implode('/', $segments);
    }

    private function notTop(string $path): string
    {
        $path = $this->clean($path);

        if ($path === '') {
            throw new FileStoreException(__('Choose something inside the folder.'), 400);
        }

        return $path;
    }

    private function parentOf(string $path): string
    {
        $slash = strrpos($path, '/');

        return $slash === false ? '' : substr($path, 0, $slash);
    }

    private function join(string $dir, string $name): string
    {
        return $dir === '' ? $name : $dir.'/'.$name;
    }
}
