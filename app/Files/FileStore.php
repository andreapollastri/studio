<?php

namespace App\Files;

/**
 * What a file manager reads and writes: the workspace through the bridge
 * (BridgeClient), or a server over SFTP (App\Ssh\SftpFiles). Paths are
 * relative to the store's root, with forward slashes; '' is the root itself.
 * Every method throws FileStoreException when it cannot do what it says.
 */
interface FileStore
{
    /**
     * One directory, folders first.
     *
     * @return array{path: string, entries: list<array{name: string, type: string, size: int, mtime: ?string}>}
     */
    public function listFiles(string $path = ''): array;

    /**
     * A file's text with the hash of its bytes; binary or oversized files come back without content.
     *
     * @return array<string, mixed> path, size, binary, too_large, limit, content, hash
     */
    public function readFile(string $path): array;

    /**
     * Write a file; with a hash, nothing is written (409) when the file changed meanwhile.
     *
     * @return array<string, mixed> path, size, hash
     */
    public function writeFile(string $path, string $content, ?string $hash = null): array;

    /**
     * Any bytes into a file: missing folders are created, an existing file is replaced only with $overwrite (409 otherwise).
     *
     * @param  resource  $stream
     * @return array<string, mixed> path, size
     */
    public function uploadFile(string $path, $stream, bool $overwrite = false): array;

    /**
     * @param  'file'|'dir'  $type
     * @return array<string, mixed>
     */
    public function createPath(string $path, string $type = 'file'): array;

    /** @return array<string, mixed> */
    public function renamePath(string $from, string $to): array;

    /** @return array<string, mixed> */
    public function deletePath(string $path): array;

    /**
     * A zip of files and folders of one folder, next to them, under a free name.
     *
     * @param  list<string>  $paths
     * @return array<string, mixed> path, size
     */
    public function zipPaths(array $paths): array;

    /**
     * Extract a zip next to it, into a new folder (or the one folder at its top).
     *
     * @return array<string, mixed> path
     */
    public function unzipPath(string $path): array;
}
