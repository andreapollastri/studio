<?php

namespace App\Vault;

use App\Models\Project;
use App\Models\User;
use App\Models\VaultFolder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which folders of other people's password managers one person reaches, and how,
 * computed over every folder at once (they are few): a folder shared with them,
 * to view or to edit; a folder tied to a project where their role is ticked, to
 * view; every project folder, to edit, for an administrator. Whatever reaches a
 * folder reaches the folders inside it, at the higher of the levels that meet.
 * A person's own folders are theirs, not shared with them: they are left out.
 */
final class FolderAccess
{
    public const READ = 'read';

    public const WRITE = 'write';

    /** @var array<int, string> folder id => read|write */
    private array $levels = [];

    /** @var Collection<int, VaultFolder> */
    private Collection $folders;

    public function __construct(private readonly User $user)
    {
        $this->folders = VaultFolder::query()->get(['id', 'uuid', 'user_id', 'parent_id', 'project_id', 'project_roles', 'name'])->keyBy('id');

        $direct = DB::table('vault_folder_user')->where('user_id', $user->id)->pluck('can_edit', 'vault_folder_id');
        $roles = $user->isAdmin() ? [] : $user->projects()->get()
            ->mapWithKeys(fn (Project $project) => [$project->id => ($project->membership->role ?? $user->defaultRole())->value])->all();

        /** @var array<int, list<int>> $children parent id => the ids of its folders */
        $children = [];
        foreach ($this->folders as $folder) {
            $children[(int) $folder->parent_id][] = $folder->id;
        }

        foreach ($this->folders as $folder) {
            if ($folder->user_id === $user->id) {
                continue;
            }

            $level = null;

            if ($direct->has($folder->id)) {
                $level = $direct[$folder->id] ? self::WRITE : self::READ;
            }

            if ($folder->project_id !== null) {
                if ($user->isAdmin()) {
                    $level = self::WRITE;
                } elseif (isset($roles[$folder->project_id]) && in_array($roles[$folder->project_id], $folder->project_roles ?? [], true)) {
                    $level ??= self::READ;
                }
            }

            if ($level !== null) {
                $this->grant($folder->id, $level, $children);
            }
        }
    }

    public static function for(User $user): self
    {
        return new self($user);
    }

    /** @param  array<int, list<int>>  $children */
    private function grant(int $id, string $level, array $children): void
    {
        if (($this->levels[$id] ?? null) === self::WRITE || ($this->levels[$id] ?? null) === $level) {
            return;
        }

        $this->levels[$id] = $level;

        foreach ($children[$id] ?? [] as $child) {
            $this->grant($child, $level, $children);
        }
    }

    /** read, write, or null: the folder is not shared with this person (or is their own). */
    public function level(?int $folderId): ?string
    {
        return $folderId === null ? null : ($this->levels[$folderId] ?? null);
    }

    public function canRead(?int $folderId): bool
    {
        return $this->level($folderId) !== null;
    }

    public function canWrite(?int $folderId): bool
    {
        return $this->level($folderId) === self::WRITE;
    }

    /** @return list<int> every folder of someone else this person reaches */
    public function readableIds(): array
    {
        return array_keys($this->levels);
    }

    /**
     * The folders this person reaches, for their sidebar: each with its place in the tree,
     * a root being one whose parent they do not reach.
     *
     * @return Collection<int, VaultFolder>
     */
    public function folders(): Collection
    {
        return $this->folders->only($this->readableIds())->sortBy('name')->values();
    }

    public function isRoot(VaultFolder $folder): bool
    {
        return $folder->parent_id === null || ! $this->canRead($folder->parent_id);
    }

    public function folder(int $id): ?VaultFolder
    {
        return $this->folders->get($id);
    }

    /**
     * The folders, anybody's, that a project reaches: those tied to it and every folder inside them.
     *
     * @return list<int>
     */
    public static function projectFolderIds(int $projectId): array
    {
        $tied = VaultFolder::query()->where('project_id', $projectId)->get();

        return array_values(array_unique(array_merge([], ...$tied->map(fn (VaultFolder $folder) => $folder->subtreeIds())->all())));
    }
}
