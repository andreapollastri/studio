<?php

namespace App\Livewire\Tools;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Drive\Access;
use App\Drive\WorkspaceTransfer;
use App\Livewire\Concerns\SearchesDirectory;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\Project;
use App\Models\Workspace;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The drive: folders and files on Studio's own disk. A folder tied to a
 * project opens to its members by role (read, or read and write); the owner
 * of a top folder and the administrators see and change everything below it.
 * From a folder tied to a project, files and folders can be copied or moved
 * into the person's workspace of that project.
 */
#[Layout('layouts::app')]
#[Title('Drive')]
class Drive extends Component
{
    use SearchesDirectory;
    use WithFileUploads;

    /** The folder open now; null: the start of the drive. */
    #[Url]
    public ?int $folder = null;

    /** @var list<int> */
    public array $selectedFolders = [];

    /** @var list<int> */
    public array $selectedFiles = [];

    /** @var array<int, UploadedFile> */
    public array $uploads = [];

    public ?int $folderEditing = null;

    public string $folder_name = '';

    /** file:<id> or folder:<id> being renamed */
    public ?string $renaming = null;

    public string $rename_to = '';

    public ?int $moveTarget = null;

    /** The folder whose project and access the sharing modal edits. */
    public ?int $sharing = null;

    /** @var array<string, string> role => none|read|write */
    public array $share_access = [];

    /** copy or move, into the workspace */
    public string $transfer_mode = 'copy';

    public string $transfer_path = '';

    public bool $transfer_overwrite = false;

    /** @var list<array{name: string, type: string, size: int, mtime: ?string}> */
    public array $transfer_dirs = [];

    public ?string $transfer_error = null;

    private ?Access $accessCache = null;

    public function mount(): void
    {
        if ($this->folder !== null && ! $this->access()->canRead($this->folder)) {
            $this->folder = null;
        }
    }

    private function access(): Access
    {
        return $this->accessCache ??= new Access(auth()->user());
    }

    /** @return Collection<int, DriveFolder> */
    #[Computed]
    public function folders(): Collection
    {
        $query = $this->folder === null
            ? DriveFolder::query()->whereIn('id', $this->access()->roots()->pluck('id'))
            : DriveFolder::query()->where('parent_id', $this->folder);

        return $query->with(['user', 'project'])->orderBy('name')->get()
            ->filter(fn (DriveFolder $f) => $this->access()->canRead($f->id))->values();
    }

    /** @return Collection<int, DriveFile> */
    #[Computed]
    public function files(): Collection
    {
        return DriveFile::query()
            ->when($this->folder === null, fn ($q) => $q->whereNull('drive_folder_id')->where('user_id', auth()->id()), fn ($q) => $q->where('drive_folder_id', $this->folder))
            ->with('user')
            ->orderBy('name')
            ->get();
    }

    public function openFolder(?int $id): void
    {
        $this->folder = $id !== null && $this->access()->canRead($id) ? $id : null;
        $this->reset(['selectedFolders', 'selectedFiles']);
        unset($this->folders, $this->files);
    }

    // ---------------------------------------------------------------- folders

    public function newFolder(): void
    {
        abort_unless($this->access()->canWrite($this->folder), 403);
        $this->folderEditing = null;
        $this->folder_name = '';
        $this->resetValidation();

        Flux::modal('drive-folder')->show();
    }

    public function saveFolder(): void
    {
        abort_unless($this->access()->canWrite($this->folder), 403);
        $this->validate(['folder_name' => ['required', 'string', 'max:120', 'regex:/^[^\/\\\\]+$/', 'not_in:.,..']]);

        if ($this->nameTaken($this->folder_name, $this->folder)) {
            $this->addError('folder_name', __('Something with this name is already here.'));

            return;
        }

        auth()->user()->driveFolders()->create(['name' => trim($this->folder_name), 'parent_id' => $this->folder]);

        unset($this->folders);
        Flux::modal('drive-folder')->close();
    }

    // ---------------------------------------------------------------- files

    public function updatedUploads(): void
    {
        abort_unless($this->access()->canWrite($this->folder), 403);
        $this->validate(['uploads.*' => ['file', 'max:'.((int) config('studio.drive.max_upload_mb', 100) * 1024)]]);

        foreach ($this->uploads as $upload) {
            $name = $this->freeName(Str::limit(str_replace(['/', '\\'], '-', $upload->getClientOriginalName()), 190, ''));
            $stored = $upload->storeAs('drive', Str::uuid()->toString(), 'local');

            DriveFile::query()->create([
                'user_id' => auth()->id(),
                'drive_folder_id' => $this->folder,
                'name' => $name,
                'disk_path' => (string) $stored,
                'size' => (int) $upload->getSize(),
                'mime' => $upload->getMimeType(),
            ]);
        }

        $count = count($this->uploads);
        $this->uploads = [];
        unset($this->files);
        Flux::toast(variant: 'success', text: trans_choice(':count file uploaded.|:count files uploaded.', $count, ['count' => $count]));
    }

    public function startRename(string $kind, int $id): void
    {
        [$name, $parent] = $kind === 'folder'
            ? [$this->folderOrFail($id)->name, $this->folderOrFail($id)->parent_id]
            : [$this->fileOrFail($id)->name, $this->fileOrFail($id)->drive_folder_id];
        abort_unless($kind === 'folder' ? $this->access()->canWrite($id) : $this->access()->canWrite($parent), 403);

        $this->renaming = $kind.':'.$id;
        $this->rename_to = $name;
        $this->resetValidation();

        Flux::modal('drive-rename')->show();
    }

    public function rename(): void
    {
        [$kind, $id] = explode(':', (string) $this->renaming) + [null, null];
        $this->validate(['rename_to' => ['required', 'string', 'max:190', 'regex:/^[^\/\\\\]+$/', 'not_in:.,..']]);
        $name = trim($this->rename_to);

        if ($kind === 'folder') {
            $folder = $this->folderOrFail((int) $id);
            abort_unless($this->access()->canWrite($folder->id), 403);
            if ($name !== $folder->name && $this->nameTaken($name, $folder->parent_id)) {
                $this->addError('rename_to', __('Something with this name is already here.'));

                return;
            }
            $folder->update(['name' => $name]);
        } else {
            $file = $this->fileOrFail((int) $id);
            abort_unless($this->access()->canWriteFile($file), 403);
            if ($name !== $file->name && $this->nameTaken($name, $file->drive_folder_id)) {
                $this->addError('rename_to', __('Something with this name is already here.'));

                return;
            }
            $file->update(['name' => $name]);
        }

        $this->renaming = null;
        unset($this->folders, $this->files);
        Flux::modal('drive-rename')->close();
    }

    public function deleteSelected(): void
    {
        $access = $this->access();
        $removed = 0;

        foreach (DriveFile::query()->whereIn('id', $this->selectedFiles)->get() as $file) {
            if ($access->canWriteFile($file)) {
                $file->delete();
                $removed++;
            }
        }

        foreach ($this->selectedFolders as $id) {
            $target = $access->folder($id);
            // A top folder goes only by the hand of its owner or an administrator.
            if ($target !== null && $access->canWrite($id) && ($target->parent_id !== null || $access->canManage($id))) {
                $this->deleteTree($id);
                $removed++;
            }
        }

        $this->reset(['selectedFolders', 'selectedFiles']);
        $this->accessCache = null;
        unset($this->folders, $this->files);
        Flux::toast(text: trans_choice(':count item removed.|:count items removed.', $removed, ['count' => $removed]));
    }

    public function startMove(): void
    {
        $this->moveTarget = null;
        Flux::modal('drive-move')->show();
    }

    public function moveSelected(): void
    {
        $access = $this->access();
        $target = $this->moveTarget;
        abort_unless($target !== null && $access->canWrite($target), 403);

        foreach (DriveFile::query()->whereIn('id', $this->selectedFiles)->get() as $file) {
            if ($access->canWriteFile($file) && ! $this->nameTaken($file->name, $target)) {
                $file->update(['drive_folder_id' => $target]);
            }
        }

        foreach (DriveFolder::query()->whereIn('id', $this->selectedFolders)->get() as $folder) {
            $movable = $access->canWrite($folder->id) && ($folder->parent_id !== null || $access->canManage($folder->id));
            if ($movable && ! in_array($target, $access->subtree($folder->id), true) && ! $this->nameTaken($folder->name, $target)) {
                $folder->update(['parent_id' => $target]);
            }
        }

        $this->reset(['selectedFolders', 'selectedFiles']);
        $this->accessCache = null;
        unset($this->folders, $this->files);
        Flux::modal('drive-move')->close();
        Flux::toast(text: __('Moved.'));
    }

    // ---------------------------------------------------------------- sharing

    public function share(int $id): void
    {
        abort_unless($this->access()->canManage($id), 403);
        $folder = $this->folderOrFail($id);

        $this->sharing = $folder->id;
        $this->share_access = collect(DriveFolder::ROLES)->mapWithKeys(fn (string $role) => [$role => ($folder->project_access ?? [])[$role] ?? 'none'])->all();

        Flux::modal('drive-sharing')->show();
    }

    public function pickShareProject(?int $id): void
    {
        $folder = $this->managedFolder();

        if ($id !== null && ! auth()->user()->accessibleProjects()->whereKey($id)->exists()) {
            return;
        }

        if ($id !== null && $folder->project_id === null && collect($this->share_access)->every(fn ($level) => $level === 'none')) {
            $this->share_access = ['client' => 'none', 'pm' => 'read', 'dev' => 'write'];
        }

        $folder->update(['project_id' => $id, 'project_access' => $id === null ? null : $this->accessToStore()]);
        $this->accessCache = null;
        unset($this->folders);
    }

    public function updatedShareAccess(): void
    {
        $folder = $this->managedFolder();
        if ($folder->project_id !== null) {
            $folder->update(['project_access' => $this->accessToStore()]);
        }
        $this->accessCache = null;
    }

    // ---------------------------------------------------------------- copy / move into the workspace

    public function startTransfer(string $mode): void
    {
        $workspace = $this->workspace();
        abort_unless($workspace !== null, 403);

        $this->transfer_mode = $mode === 'move' ? 'move' : 'copy';
        $this->transfer_overwrite = false;
        $this->transfer_error = null;
        $this->browse('');

        Flux::modal('drive-transfer')->show();
    }

    public function browse(string $path): void
    {
        $workspace = $this->workspace();
        if ($workspace === null) {
            return;
        }

        $workspace->refresh();
        if (! $workspace->isRunning()) {
            $this->transfer_error = __('Your workspace of this project is not running: start it from the project page.');
            $this->transfer_dirs = [];

            return;
        }

        try {
            $listing = (new BridgeClient($workspace))->listFiles(trim($path, '/'));
            $this->transfer_path = $listing['path'];
            $this->transfer_dirs = array_values(array_filter($listing['entries'], fn (array $entry) => $entry['type'] === 'dir'));
            $this->transfer_error = null;
        } catch (BridgeException $e) {
            $this->transfer_error = $e->getMessage();
        }
    }

    public function transfer(): void
    {
        $workspace = $this->workspace();
        abort_unless($workspace !== null, 403);

        // Nothing ticked: the folder open now, as a whole.
        $folders = $this->selectedFolders;
        $files = $this->selectedFiles;
        if ($folders === [] && $files === [] && $this->folder !== null) {
            $folders = [$this->folder];
        }

        $result = (new WorkspaceTransfer($this->access(), $workspace))
            ->run($folders, $files, $this->transfer_path, $this->transfer_overwrite, $this->transfer_mode === 'move');

        $this->reset(['selectedFolders', 'selectedFiles']);
        $this->accessCache = null;
        if ($this->folder !== null && ! DriveFolder::query()->whereKey($this->folder)->exists()) {
            $this->folder = null;
        }
        unset($this->folders, $this->files);

        if ($result['failed'] !== []) {
            $this->transfer_error = __(':copied copied; these did not make it: :list', ['copied' => $result['copied'], 'list' => implode(' · ', array_slice($result['failed'], 0, 5))]);

            return;
        }

        Flux::modal('drive-transfer')->close();
        Flux::toast(variant: 'success', text: $this->transfer_mode === 'move'
            ? __(':count file(s) moved to :path in your workspace.', ['count' => $result['copied'], 'path' => '/'.$this->transfer_path])
            : __(':count file(s) copied to :path in your workspace.', ['count' => $result['copied'], 'path' => '/'.$this->transfer_path]));
    }

    public function render(): View
    {
        $access = $this->access();
        $current = $this->folder !== null ? $access->folder($this->folder) : null;
        $workspace = $this->workspace();
        $sharingFolder = $this->sharing !== null ? DriveFolder::query()->with('project')->find($this->sharing) : null;

        return view('livewire.tools.drive', [
            'current' => $current,
            'trail' => $this->folder !== null ? $access->trail($this->folder) : [],
            'canWrite' => $access->canWrite($this->folder),
            'projectId' => $access->projectOf($this->folder),
            'projectName' => ($id = $access->projectOf($this->folder)) !== null ? Project::query()->find($id)?->name : null,
            'workspace' => $workspace,
            'sharingFolder' => $sharingFolder,
            'audience' => $sharingFolder !== null ? $access->audience($sharingFolder->id) : collect(),
            'targets' => $access->writableFolders()->except(array_merge(...array_map(fn (int $id) => $access->subtree($id), $this->selectedFolders ?: [0]))),
            'maxUploadMb' => (int) config('studio.drive.max_upload_mb', 100),
            'manageable' => $this->folders()->filter(fn (DriveFolder $f) => $access->canManage($f->id))->pluck('id')->all(),
            'writable' => $this->folders()->filter(fn (DriveFolder $f) => $access->canWrite($f->id))->pluck('id')->all(),
        ]);
    }

    /** The person's workspace of the project the open folder is tied to, when they have the project's file manager. */
    private function workspace(): ?Workspace
    {
        return WorkspaceTransfer::workspaceFor($this->access(), $this->folder);
    }

    private function deleteTree(int $id): void
    {
        $ids = $this->access()->subtree($id);

        DriveFile::query()->whereIn('drive_folder_id', $ids)->get()->each->delete();
        DriveFolder::query()->whereKey($id)->delete();
    }

    /** Names are unique among the folders and files of one folder (at the top: of one person). */
    private function nameTaken(string $name, ?int $parent): bool
    {
        $name = trim($name);

        $folders = DriveFolder::query()->where('parent_id', $parent)->where('name', $name)
            ->when($parent === null, fn ($q) => $q->where('user_id', auth()->id()));
        $files = DriveFile::query()->where('drive_folder_id', $parent)->where('name', $name)
            ->when($parent === null, fn ($q) => $q->where('user_id', auth()->id()));

        return $folders->exists() || $files->exists();
    }

    /** "report.pdf", or "report (2).pdf" when that is taken here. */
    private function freeName(string $name): string
    {
        $name = trim($name) !== '' ? trim($name) : 'file';
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $candidate = $name;

        for ($n = 2; $this->nameTaken($candidate, $this->folder); $n++) {
            $candidate = $base.' ('.$n.')'.($ext !== '' ? '.'.$ext : '');
        }

        return $candidate;
    }

    /** @return array<string, string> */
    private function accessToStore(): array
    {
        return collect(DriveFolder::ROLES)
            ->mapWithKeys(fn (string $role) => [$role => $this->share_access[$role] ?? 'none'])
            ->filter(fn (string $level) => in_array($level, [Access::READ, Access::WRITE], true))
            ->all();
    }

    private function managedFolder(): DriveFolder
    {
        abort_unless($this->sharing !== null && $this->access()->canManage($this->sharing), 403);

        return $this->folderOrFail($this->sharing);
    }

    private function folderOrFail(int $id): DriveFolder
    {
        abort_unless($this->access()->canRead($id), 404);

        return DriveFolder::query()->findOrFail($id);
    }

    private function fileOrFail(int $id): DriveFile
    {
        $file = DriveFile::query()->findOrFail($id);
        abort_unless($this->access()->canReadFile($file), 404);

        return $file;
    }
}
