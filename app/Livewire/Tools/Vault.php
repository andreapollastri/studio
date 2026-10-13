<?php

namespace App\Livewire\Tools;

use App\Livewire\Concerns\SearchesDirectory;
use App\Models\Project;
use App\Models\User;
use App\Models\VaultFolder;
use App\Models\VaultItem;
use App\Vault\Totp;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The password manager: a person's items in their own folders, the items
 * shared with them, and those of the projects they work on (by role).
 * Secrets never sit in the page: reveal() hands one over when it is asked for,
 * to show or to copy. Folders, items, projects and people go by uuid.
 */
#[Layout('layouts::app')]
#[Title('Password manager')]
class Vault extends Component
{
    use SearchesDirectory;

    /** all, folder (with $folder; null: the top level), shared, project (with $project) */
    #[Url]
    public string $view = 'all';

    /** uuid of the folder open now */
    #[Url]
    public ?string $folder = null;

    /** uuid of the project open now */
    #[Url]
    public ?string $project = null;

    public string $search = '';

    /** uuid of the folder being renamed */
    public ?string $folderEditing = null;

    /** uuid of the folder a new one goes in */
    public ?string $folderParent = null;

    public string $folder_name = '';

    /** uuid of the item in the form */
    public ?string $itemEditing = null;

    public string $item_title = '';

    public string $item_url = '';

    public string $item_username = '';

    public string $item_password = '';

    public string $item_notes = '';

    public string $item_totp = '';

    /** uuid of the folder the item goes in */
    public ?string $item_folder = null;

    /** The item in the form is this person's own: they file it in their folders. */
    public bool $itemOwned = true;

    /** uuid of the item whose details or sharing the modals show */
    public ?string $shown = null;

    /** @var list<string> */
    public array $share_roles = [];

    public function mount(): void
    {
        if (! in_array($this->view, ['all', 'folder', 'shared', 'project'], true)) {
            $this->view = 'all';
        }
    }

    /** @return Collection<int, VaultFolder> every folder of this person, for the tree */
    #[Computed]
    public function folders(): Collection
    {
        return auth()->user()->vaultFolders()->orderBy('name')->get();
    }

    /** @return BaseCollection<int, array{project: Project, count: int}> projects with items this person sees */
    #[Computed]
    public function projects(): BaseCollection
    {
        $counts = VaultItem::query()->visibleTo(auth()->user())->whereNotNull('project_id')
            ->selectRaw('project_id, count(*) as aggregate')->groupBy('project_id')->pluck('aggregate', 'project_id');

        return Project::query()->whereIn('id', $counts->keys())->orderBy('name')->get()
            ->map(fn (Project $project) => ['project' => $project, 'count' => (int) $counts[$project->id]]);
    }

    /** @return Collection<int, VaultItem> */
    #[Computed]
    public function items(): Collection
    {
        $user = auth()->user();
        $query = VaultItem::query()->visibleTo($user)->with(['user', 'project', 'folder'])->withCount('sharedWith')->orderBy('title');

        $search = trim($this->search);
        if ($search === '') {
            match ($this->view) {
                'folder' => $query->where('user_id', $user->id)->where('vault_folder_id', $this->folderId($this->folder)),
                'shared' => $query->where('user_id', '!=', $user->id),
                'project' => $query->where('project_id', Project::query()->where('uuid', (string) $this->project)->value('id') ?? 0),
                default => null,
            };
        }

        $items = $query->get();

        if ($search === '') {
            return $items;
        }

        $needle = mb_strtolower($search);

        return $items->filter(fn (VaultItem $item) => str_contains(mb_strtolower($item->title.' '.$item->url.' '.$item->username.' '.$item->project?->name), $needle))->values();
    }

    public function show(string $view, ?string $id = null): void
    {
        $this->view = in_array($view, ['all', 'folder', 'shared', 'project'], true) ? $view : 'all';
        $this->folder = $this->view === 'folder' && $id !== null ? $this->ownFolder($id)->uuid : null;
        $this->project = $this->view === 'project' ? $id : null;
        $this->search = '';
        unset($this->items);
    }

    // ---------------------------------------------------------------- folders

    public function newFolder(?string $parent = null): void
    {
        $this->authorizeCreate();
        $this->folderEditing = null;
        $this->folderParent = $parent !== null ? $this->ownFolder($parent)->uuid : null;
        $this->folder_name = '';
        $this->resetValidation();

        Flux::modal('vault-folder')->show();
    }

    public function renameFolder(string $id): void
    {
        $folder = $this->ownFolder($id);
        $this->folderEditing = $folder->uuid;
        $this->folderParent = $folder->parent?->uuid;
        $this->folder_name = $folder->name;
        $this->resetValidation();

        Flux::modal('vault-folder')->show();
    }

    public function saveFolder(): void
    {
        $this->authorizeCreate();
        $this->validate(['folder_name' => ['required', 'string', 'max:80']]);

        if ($this->folderEditing) {
            $this->ownFolder($this->folderEditing)->update(['name' => $this->folder_name]);
        } else {
            $folder = auth()->user()->vaultFolders()->create(['name' => $this->folder_name, 'parent_id' => $this->folderId($this->folderParent)]);
            $this->show('folder', $folder->uuid);
        }

        unset($this->folders);
        Flux::modal('vault-folder')->close();
    }

    /** The items of the folder and of every folder below it move to the top level. */
    public function deleteFolder(string $id): void
    {
        $folder = $this->ownFolder($id);
        $ids = $folder->subtreeIds();
        $wasOpen = $this->view === 'folder' && in_array($this->folderId($this->folder), $ids, true);

        VaultItem::query()->whereIn('vault_folder_id', $ids)->update(['vault_folder_id' => null]);
        $folder->delete();

        if ($wasOpen) {
            $this->show('folder');
        }
        unset($this->folders, $this->items);
        Flux::toast(text: __('Folder removed; its items are at the top level now.'));
    }

    // ---------------------------------------------------------------- items

    public function newItem(): void
    {
        $this->authorizeCreate();
        $this->reset(['itemEditing', 'item_title', 'item_url', 'item_username', 'item_password', 'item_notes', 'item_totp']);
        $this->item_folder = $this->view === 'folder' ? $this->folder : null;
        $this->itemOwned = true;
        $this->resetValidation();

        Flux::modal('vault-item')->show();
    }

    public function editItem(string $id): void
    {
        $item = $this->visibleItem($id);
        abort_unless($item->canEdit(auth()->user()), 403);

        $this->itemEditing = $item->uuid;
        $this->item_title = $item->title;
        $this->item_url = (string) $item->url;
        $this->item_username = (string) $item->username;
        $this->item_password = (string) $item->password;
        $this->item_notes = (string) $item->notes;
        $this->item_totp = (string) $item->totp_secret;
        $this->itemOwned = $item->isOwnedBy(auth()->user());
        $this->item_folder = $this->itemOwned ? $item->folder?->uuid : null;
        $this->resetValidation();

        Flux::modal('vault-show')->close();
        Flux::modal('vault-item')->show();
    }

    public function saveItem(): void
    {
        $user = auth()->user();
        $existing = $this->itemEditing ? $this->visibleItem($this->itemEditing) : null;
        $existing ? abort_unless($existing->canEdit($user), 403) : $this->authorizeCreate();

        $validated = $this->validate([
            'item_title' => ['required', 'string', 'max:120'],
            'item_url' => ['nullable', 'string', 'max:500'],
            'item_username' => ['nullable', 'string', 'max:500'],
            'item_password' => ['nullable', 'string', 'max:2000'],
            'item_notes' => ['nullable', 'string', 'max:20000'],
            'item_totp' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail): void {
                if (filled($value) && Totp::normalize((string) $value) === null) {
                    $fail(__('Paste the secret (base32) or the otpauth:// link of the authenticator setup.'));
                }
            }],
            'item_folder' => ['nullable', 'string', Rule::exists('vault_folders', 'uuid')->where('user_id', $user->id)],
        ]);

        $attributes = [
            'title' => $validated['item_title'],
            'url' => $validated['item_url'] ?: null,
            'username' => $validated['item_username'] ?: null,
            'password' => $validated['item_password'] ?: null,
            'notes' => $validated['item_notes'] ?: null,
            'totp_secret' => Totp::normalize($validated['item_totp']),
            'updated_by' => $user->id,
        ];
        $folderId = $this->folderId($validated['item_folder']);

        if ($existing) {
            if ($existing->isOwnedBy($user)) {
                $attributes['vault_folder_id'] = $folderId;
            }
            $existing->update($attributes);
        } else {
            $user->vaultItems()->create([...$attributes, 'vault_folder_id' => $folderId]);
        }

        $this->reset(['itemEditing', 'item_title', 'item_url', 'item_username', 'item_password', 'item_notes', 'item_totp']);
        unset($this->items, $this->projects);
        Flux::modal('vault-item')->close();
        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function deleteItem(string $id): void
    {
        $item = $this->visibleItem($id);
        abort_unless($item->canManage(auth()->user()), 403);
        $item->delete();

        unset($this->items, $this->projects);
        Flux::modal('vault-show')->close();
        Flux::toast(text: __('Item removed.'));
    }

    public function moveItem(string $id, ?string $folder): void
    {
        $item = $this->visibleItem($id);
        abort_unless($item->isOwnedBy(auth()->user()), 403);
        $item->update(['vault_folder_id' => $folder !== null ? $this->ownFolder($folder)->id : null]);

        unset($this->items);
        Flux::toast(text: __('Moved.'));
    }

    public function open(string $id): void
    {
        $this->shown = $this->visibleItem($id)->uuid;

        Flux::modal('vault-show')->show();
    }

    /** One secret of an item, to show or to copy: username, password or notes. */
    public function reveal(string $id, string $field): ?string
    {
        abort_unless(in_array($field, ['username', 'password', 'notes'], true), 404);

        return $this->visibleItem($id)->{$field};
    }

    /** @return array{code: string, remaining: int}|null */
    public function totp(string $id): ?array
    {
        return Totp::now($this->visibleItem($id)->totp_secret);
    }

    // ---------------------------------------------------------------- sharing

    public function sharing(string $id): void
    {
        $item = $this->visibleItem($id);
        abort_unless($item->canManage(auth()->user()), 403);

        $this->shown = $item->uuid;
        $this->share_roles = $item->project_roles ?? [];

        Flux::modal('vault-show')->close();
        Flux::modal('vault-sharing')->show();
    }

    public function pickShareProject(?string $id): void
    {
        $item = $this->managedItem();
        $project = $id !== null ? auth()->user()->accessibleProjects()->where('uuid', $id)->first() : null;

        if ($id !== null && $project === null) {
            return;
        }

        $roles = $project === null ? null : ($item->project_id === $project->id ? $item->project_roles : ($this->share_roles ?: ['dev']));
        $item->update(['project_id' => $project?->id, 'project_roles' => $roles]);
        $this->share_roles = $roles ?? [];
        unset($this->items, $this->projects);
    }

    public function updatedShareRoles(): void
    {
        $item = $this->managedItem();
        $roles = array_values(array_intersect(VaultItem::PROJECT_ROLES, $this->share_roles));
        $this->share_roles = $roles;

        if ($item->project_id !== null) {
            $item->update(['project_roles' => $roles]);
        }
        unset($this->items, $this->projects);
    }

    public function addShare(?string $userId): void
    {
        $item = $this->managedItem();
        $user = $userId !== null ? User::query()->where('uuid', $userId)->first() : null;

        if ($user === null || $user->id === $item->user_id) {
            return;
        }

        $item->sharedWith()->syncWithoutDetaching([$user->id => ['can_edit' => false]]);
        unset($this->items);
    }

    public function setShareEdit(string $userId, bool $canEdit): void
    {
        $user = User::query()->where('uuid', $userId)->firstOrFail();
        $this->managedItem()->sharedWith()->updateExistingPivot($user->id, ['can_edit' => $canEdit]);
    }

    public function removeShare(string $userId): void
    {
        $user = User::query()->where('uuid', $userId)->firstOrFail();
        $this->managedItem()->sharedWith()->detach($user->id);
        unset($this->items);
    }

    public function render(): View
    {
        $user = auth()->user();
        $shownItem = $this->shown ? VaultItem::query()->visibleTo($user)->with(['user', 'project', 'editor', 'sharedWith'])->where('uuid', $this->shown)->first() : null;

        return view('livewire.tools.vault', [
            'shownItem' => $shownItem,
            'canCreate' => $user->canChat(),
            'heading' => match ($this->view) {
                'folder' => $this->folder ? ($this->folders()->firstWhere('uuid', $this->folder)->name ?? __('Folder')) : __('My items'),
                'shared' => __('Shared with me'),
                'project' => Project::query()->where('uuid', (string) $this->project)->value('name') ?? __('Project'),
                default => __('All items'),
            },
        ]);
    }

    private function authorizeCreate(): void
    {
        abort_unless(auth()->user()->canChat(), 403);
    }

    private function ownFolder(string $id): VaultFolder
    {
        return auth()->user()->vaultFolders()->where('uuid', $id)->firstOrFail();
    }

    /** The id behind one of this person's folders; null for the top level or a folder that is not theirs. */
    private function folderId(?string $uuid): ?int
    {
        return $uuid === null ? null : auth()->user()->vaultFolders()->where('uuid', $uuid)->value('id');
    }

    private function visibleItem(string $id): VaultItem
    {
        return VaultItem::query()->visibleTo(auth()->user())->where('uuid', $id)->firstOrFail();
    }

    private function managedItem(): VaultItem
    {
        $item = $this->visibleItem((string) $this->shown);
        abort_unless($item->canManage(auth()->user()), 403);

        return $item;
    }
}
