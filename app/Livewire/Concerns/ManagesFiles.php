<?php

namespace App\Livewire\Concerns;

use App\Files\FileStore;
use App\Files\FileStoreException;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The file manager shared by the project's Files tab and the SSH page: a lazy
 * tree read from a FileStore, and the server half of the editor. The editor
 * itself (Monaco, the editor of VS Code) runs in the browser and owns the open
 * tabs; this hands it a file with the hash of its bytes and writes the text
 * back only while that hash still matches what is on disk.
 *
 * Files and whole folders upload from the browser (Livewire's temporary
 * uploads, then streamed on to the store), rows move by drag and drop or
 * through "Move to…", and zips are made and extracted next to the item.
 * The component also uses WithFileUploads and renders livewire.files.manager.
 */
trait ManagesFiles
{
    /** Files one upload may bring, a folder's included: more go as a zip, extracted here. */
    public const MAX_UPLOAD_FILES = 1000;

    /** @var array<string, list<array{name: string, type: string, size: int, mtime: ?string}>> directories read so far, keyed by path ('' is the root) */
    public array $dirs = [];

    /** @var list<string> */
    public array $expanded = [];

    public ?string $error = null;

    /** @var array{path: string, type: string}|null the folder and kind of the entry being named */
    public ?array $creating = null;

    public ?string $renaming = null;

    public string $name = '';

    /** @var array{path: string, size: int, binary: bool, limit: int}|null the last file that could not be opened */
    public ?array $unopenable = null;

    /** What the last upload, move, zip or extraction did, for a line above the tree. */
    public ?string $notice = null;

    /** @var list<TemporaryUploadedFile> the batch the browser is uploading */
    public array $uploads = [];

    /** The folder the batch goes into. */
    #[Locked]
    public string $uploadDir = '';

    /** @var list<string> where each file of the batch goes under that folder, in order (a folder's files keep their place) */
    #[Locked]
    public array $uploadPaths = [];

    /** @var list<array{path: string, file: TemporaryUploadedFile}> uploads that would replace a file: replaced or dropped on request */
    #[Locked]
    public array $clashes = [];

    /** The row being moved through "Move to…", and the folder the dialog shows. */
    public ?string $moving = null;

    public string $moveTo = '';

    /** @var list<string> the folders of */
    public array $moveDirs = [];

    /** Where the files are; throws FileStoreException when it cannot be reached. */
    abstract protected function files(): FileStore;

    /** The link that downloads one file of the tree. */
    abstract public function downloadUrl(string $path): string;

    /** The name of the top of the tree, in the move dialog. */
    abstract public function rootLabel(): string;

    /** Why the files cannot be read right now, or null. */
    protected function unavailable(): ?string
    {
        return null;
    }

    /** A folder at the top that is never touched (the workspace's .git), or null. */
    protected function reservedTop(): ?string
    {
        return null;
    }

    /** The move dialog of this file manager: one per instance, there may be several on a page. */
    public function moveModal(): string
    {
        return 'files-move-'.$this->getId();
    }

    /** What keeps this editor's tabs apart from another editor's on the same page. */
    public function editorScope(): string
    {
        return '';
    }

    public function load(string $path): void
    {
        $this->error = $this->unavailable();

        if ($this->error !== null) {
            return;
        }

        try {
            $this->dirs[$path] = $this->files()->listFiles($path)['entries'];
        } catch (FileStoreException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function toggle(string $path): void
    {
        if (in_array($path, $this->expanded, true)) {
            $this->expanded = array_values(array_diff($this->expanded, [$path]));

            return;
        }

        if (! isset($this->dirs[$path])) {
            $this->load($path);
        }

        if (isset($this->dirs[$path])) {
            $this->expanded[] = $path;
        }
    }

    public function refresh(): void
    {
        foreach (array_keys($this->dirs) as $path) {
            $this->load((string) $path);
        }
    }

    /** Hand a file to the editor; a binary or oversized file is described instead. */
    public function open(string $path): void
    {
        try {
            $file = $this->files()->readFile($path);
        } catch (FileStoreException $e) {
            $this->error = $e->getMessage();

            return;
        }

        if (($file['binary'] ?? false) || ($file['too_large'] ?? false)) {
            $this->unopenable = [
                'path' => $path,
                'size' => (int) ($file['size'] ?? 0),
                'binary' => (bool) ($file['binary'] ?? false),
                'limit' => (int) ($file['limit'] ?? 0),
            ];

            return;
        }

        $this->unopenable = null;
        $this->error = null;
        $this->dispatch('files-open', path: $path, content: (string) ($file['content'] ?? ''), hash: (string) ($file['hash'] ?? ''))->self();
    }

    /**
     * Save what the editor holds. The text travels base64-encoded so that no
     * request middleware trims it on the way.
     *
     * @return array{ok: bool, hash?: string, error?: string}
     */
    public function save(string $path, string $contentBase64, ?string $hash = null): array
    {
        $content = base64_decode($contentBase64, true);

        if ($content === false) {
            return ['ok' => false, 'error' => __('The editor sent content Studio could not decode.')];
        }

        try {
            $saved = $this->files()->writeFile($path, $content, $hash === '' ? null : $hash);
        } catch (FileStoreException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $parent = $this->parentOf($path);

        if (isset($this->dirs[$parent])) {
            $this->load($parent);
        }

        return ['ok' => true, 'hash' => (string) ($saved['hash'] ?? '')];
    }

    public function startCreating(string $dir, string $type): void
    {
        $this->renaming = null;
        $this->name = '';
        $this->creating = ['path' => $dir, 'type' => $type === 'dir' ? 'dir' : 'file'];

        if ($dir !== '' && ! in_array($dir, $this->expanded, true)) {
            $this->toggle($dir);
        }
    }

    public function startRenaming(string $path): void
    {
        $this->creating = null;
        $this->renaming = $path;
        $this->name = basename($path);
    }

    public function cancel(): void
    {
        $this->creating = null;
        $this->renaming = null;
        $this->name = '';
    }

    public function create(): void
    {
        if ($this->creating === null) {
            return;
        }

        $name = $this->validName();

        if ($name === null) {
            return;
        }

        $dir = $this->creating['path'];
        $type = $this->creating['type'];
        $path = ltrim($dir.'/'.$name, '/');

        try {
            $this->files()->createPath($path, $type === 'dir' ? 'dir' : 'file');
        } catch (FileStoreException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->cancel();
        $this->load($dir);

        if ($type === 'file') {
            $this->open($path);
        }
    }

    public function rename(): void
    {
        if ($this->renaming === null) {
            return;
        }

        $name = $this->validName();

        if ($name === null) {
            return;
        }

        $from = $this->renaming;
        $dir = $this->parentOf($from);
        $to = ltrim($dir.'/'.$name, '/');

        if ($to !== $from) {
            try {
                $this->files()->renamePath($from, $to);
            } catch (FileStoreException $e) {
                $this->error = $e->getMessage();

                return;
            }

            $this->forget($from);
            $this->dispatch('files-renamed', oldPath: $from, newPath: $to)->self();
        }

        $this->cancel();
        $this->load($dir);
    }

    public function delete(string $path): void
    {
        try {
            $this->files()->deletePath($path);
        } catch (FileStoreException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->forget($path);
        $this->dispatch('files-deleted', path: $path)->self();
        $this->load($this->parentOf($path));
    }

    // ---------------------------------------------------------------- upload

    /**
     * Before a batch: the folder it goes into and each file's place under it.
     * False (and the reason in $error) when the batch cannot go.
     *
     * @param  list<string>  $paths
     */
    public function prepareUpload(string $dir, array $paths): bool
    {
        $this->notice = null;
        $clean = [];

        foreach ($paths as $path) {
            $path = $this->cleanRelative((string) $path);

            if ($path === null) {
                $this->error = $this->reservedTop() === null
                    ? __('Some of those names cannot be uploaded: no "..".')
                    : __('Some of those names cannot be uploaded: no "..", no :reserved at the top.', ['reserved' => $this->reservedTop()]);

                return false;
            }

            $clean[] = $path;
        }

        if (count($clean) > self::MAX_UPLOAD_FILES) {
            $this->error = __('That is :count files, more than :max in one upload: zip them, upload the zip and extract it here.', ['count' => count($clean), 'max' => self::MAX_UPLOAD_FILES]);

            return false;
        }

        $this->uploadDir = $this->cleanRelative($dir) ?? '';
        $this->uploadPaths = $clean;
        $this->error = null;

        return true;
    }

    /** A batch arrived on Studio: each file goes on to the store; one already there waits for "Replace". */
    public function updatedUploads(): void
    {
        $this->validate(['uploads.*' => ['file', 'max:'.((int) config('studio.drive.max_upload_mb', 512) * 1024)]]);

        $store = $this->files();
        $sent = 0;
        $failed = [];

        foreach ($this->uploads as $i => $upload) {
            $relative = $this->uploadPaths[$i] ?? $this->cleanRelative($upload->getClientOriginalName()) ?? 'upload';
            $path = ltrim($this->uploadDir.'/'.$relative, '/');

            match ($this->send($store, $upload, $path, false)) {
                true => $sent++,
                409 => $this->clashes[] = ['path' => $path, 'file' => $upload],
                default => $failed[] = $path,
            };
        }

        $this->uploads = [];
        $this->afterUpload($sent, $failed);
    }

    public function replaceClashes(): void
    {
        $store = $this->files();
        $sent = 0;
        $failed = [];

        foreach ($this->clashes as $clash) {
            $this->send($store, $clash['file'], $clash['path'], true) === true ? $sent++ : $failed[] = $clash['path'];
        }

        $this->clashes = [];
        $this->afterUpload($sent, $failed);
    }

    public function skipClashes(): void
    {
        foreach ($this->clashes as $clash) {
            $clash['file']->delete();
        }

        $this->clashes = [];
    }

    // ---------------------------------------------------------------- move

    public function startMoving(string $path): void
    {
        $this->cancel();
        $this->moving = $path;
        $this->browseMove($this->parentOf($path));
        Flux::modal($this->moveModal())->show();
    }

    /** The move dialog opens a folder: its folders, less the one being moved. */
    public function browseMove(string $dir): void
    {
        if ($this->moving === null) {
            return;
        }

        try {
            $entries = $this->files()->listFiles($dir)['entries'];
        } catch (FileStoreException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->moveTo = $dir;
        $this->moveDirs = array_values(array_filter(
            array_map(fn (array $entry): string => ltrim($dir.'/'.$entry['name'], '/'), array_filter($entries, fn (array $entry): bool => $entry['type'] === 'dir')),
            fn (string $path): bool => $path !== $this->moving,
        ));
    }

    public function move(): void
    {
        if ($this->moving !== null && $this->moveInto($this->moving, $this->moveTo)) {
            $this->moving = null;
            Flux::modal($this->moveModal())->close();
        }
    }

    /** A row dropped on a folder of the tree ('' is the top). */
    public function moveInto(string $from, string $dir): bool
    {
        $to = ltrim($dir.'/'.basename($from), '/');

        if ($to === $from || $this->parentOf($from) === $dir) {
            return true;
        }

        if (str_starts_with($dir.'/', $from.'/')) {
            $this->error = __('A folder cannot go into itself.');

            return false;
        }

        try {
            $this->files()->renamePath($from, $to);
        } catch (FileStoreException $e) {
            $this->error = $e->getCode() === 409
                ? __('There is already a :name in /:dir.', ['name' => basename($from), 'dir' => $dir])
                : $e->getMessage();

            return false;
        }

        $this->forget($from);
        $this->dispatch('files-renamed', oldPath: $from, newPath: $to)->self();
        $this->reload($this->parentOf($from), $dir);
        $this->error = null;
        $this->notice = __('Moved :name to /:dir.', ['name' => basename($from), 'dir' => $dir]);

        return true;
    }

    // ---------------------------------------------------------------- zip

    public function compress(string $path): void
    {
        try {
            $zip = $this->files()->zipPaths([$path]);
        } catch (FileStoreException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->reload($this->parentOf($path));
        $this->error = null;
        $this->notice = __('Compressed into :name.', ['name' => basename((string) ($zip['path'] ?? ''))]);
    }

    public function extract(string $path): void
    {
        try {
            $out = $this->files()->unzipPath($path);
        } catch (FileStoreException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->reload($this->parentOf($path));
        $this->error = null;
        $this->notice = __('Extracted into :name.', ['name' => basename((string) ($out['path'] ?? ''))]);
    }

    /** true when it arrived, otherwise the store's status (409: already there) or 0. */
    private function send(FileStore $store, TemporaryUploadedFile $upload, string $path, bool $overwrite): bool|int
    {
        // Livewire's temporary uploads are on Studio's own disk; one cleaned up meanwhile is gone
        $stream = @fopen($upload->getRealPath(), 'rb');

        if ($stream === false) {
            return 0;
        }

        try {
            $store->uploadFile($path, $stream, $overwrite);
            $upload->delete();

            return true;
        } catch (FileStoreException $e) {
            if ($e->getCode() !== 409) {
                $upload->delete();
            }

            return $e->getCode() === 409 ? 409 : 0;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** @param  list<string>  $failed */
    private function afterUpload(int $sent, array $failed): void
    {
        $this->refresh();
        $this->notice = $sent > 0 ? trans_choice(':count file uploaded.|:count files uploaded.', $sent, ['count' => $sent]) : null;
        $this->error = null;

        if ($failed !== []) {
            $this->error = __('Not uploaded: :paths', ['paths' => implode(', ', array_slice($failed, 0, 5)).(count($failed) > 5 ? '…' : '')]);
        }
    }

    /** Read again the folders the tree has open among these. */
    private function reload(string ...$dirs): void
    {
        foreach (array_unique($dirs) as $dir) {
            if (isset($this->dirs[$dir])) {
                $this->load($dir);
            }
        }
    }

    /** A path under a folder of the tree, or null when it would climb out or into the reserved folder. */
    private function cleanRelative(string $path): ?string
    {
        $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $path)), fn (string $s): bool => $s !== '' && $s !== '.'));
        $reserved = $this->reservedTop();

        if (in_array('..', $segments, true) || ($reserved !== null && ($segments[0] ?? '') === $reserved) || str_contains($path, "\0")) {
            return null;
        }

        return implode('/', $segments);
    }

    /** Drop what the tree knows about a path and everything under it. */
    private function forget(string $path): void
    {
        $under = fn (string $candidate): bool => $candidate === $path || str_starts_with($candidate, $path.'/');

        $this->dirs = array_filter($this->dirs, fn (string $key): bool => ! $under($key), ARRAY_FILTER_USE_KEY);
        $this->expanded = array_values(array_filter($this->expanded, fn (string $key): bool => ! $under($key)));
    }

    private function validName(): ?string
    {
        $name = trim($this->name);

        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\')) {
            $this->error = __('Use a plain name, without slashes.');

            return null;
        }

        return $name;
    }

    private function parentOf(string $path): string
    {
        $slash = strrpos($path, '/');

        return $slash === false ? '' : substr($path, 0, $slash);
    }
}
