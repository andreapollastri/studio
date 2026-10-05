<?php

namespace App\Livewire\Studio;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The project file manager of the side pane: a lazy tree of the workspace read
 * through the bridge, and the server half of the editor. The editor itself
 * (Monaco, the editor of VS Code) runs in the browser and owns the open tabs;
 * this component hands it a file with the hash of its bytes and writes the
 * text back only while that hash still matches what is on disk.
 */
class Files extends Component
{
    public Workspace $workspace;

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

    public function mount(Workspace $workspace): void
    {
        $this->workspace = $workspace;

        abort_unless($this->allowed(), 403);

        $this->load('');
    }

    public function load(string $path): void
    {
        $this->workspace->refresh();

        if (! $this->workspace->isRunning()) {
            $this->error = __('The workspace is not running.');

            return;
        }

        try {
            $this->dirs[$path] = (new BridgeClient($this->workspace))->listFiles($path)['entries'];
            $this->error = null;
        } catch (BridgeException $e) {
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
            $file = (new BridgeClient($this->workspace))->readFile($path);
        } catch (BridgeException $e) {
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
        $this->dispatch('files-open', path: $path, content: (string) ($file['content'] ?? ''), hash: (string) ($file['hash'] ?? ''));
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
            $saved = (new BridgeClient($this->workspace))->writeFile($path, $content, $hash === '' ? null : $hash);
        } catch (BridgeException $e) {
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
            (new BridgeClient($this->workspace))->createPath($path, $type === 'dir' ? 'dir' : 'file');
        } catch (BridgeException $e) {
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
                (new BridgeClient($this->workspace))->renamePath($from, $to);
            } catch (BridgeException $e) {
                $this->error = $e->getMessage();

                return;
            }

            $this->forget($from);
            $this->dispatch('files-renamed', oldPath: $from, newPath: $to);
        }

        $this->cancel();
        $this->load($dir);
    }

    public function delete(string $path): void
    {
        try {
            (new BridgeClient($this->workspace))->deletePath($path);
        } catch (BridgeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->forget($path);
        $this->dispatch('files-deleted', path: $path);
        $this->load($this->parentOf($path));
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

    private function allowed(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->canChat() && ($this->workspace->user_id === $user->id || $user->isAdmin());
    }

    public function render(): View
    {
        return view('livewire.studio.files');
    }
}
