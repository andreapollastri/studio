<?php

namespace App\Drive;

use App\Enums\UserRole;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who may do what in the drive, for one person, computed over the whole tree
 * at once (the drive is small enough to hold its folders in memory):
 *
 *   - administrators read and write everywhere;
 *   - the owner of a top folder reads and writes everything below it;
 *   - a folder tied to a project gives that project's members the access
 *     ticked for their role (client, pm, dev: read or write), down to the next
 *     folder tied to a project of its own.
 */
final class Access
{
    public const READ = 'read';

    public const WRITE = 'write';

    /** @var Collection<int, DriveFolder> */
    private Collection $folders;

    /** @var array<int, string|null> */
    private array $levels = [];

    /** @var array<int, string> project id => this person's role there */
    private array $memberOf;

    public function __construct(private readonly User $user)
    {
        $this->folders = DriveFolder::query()->get(['id', 'uuid', 'user_id', 'parent_id', 'project_id', 'project_access', 'name'])->keyBy('id');
        $this->memberOf = $user->projects()->get()->mapWithKeys(fn (Project $project) => [
            $project->id => ($project->membership->role ?? $user->defaultRole())->value,
        ])->all();
    }

    public function user(): User
    {
        return $this->user;
    }

    /** read, write, or null: the folder is not this person's to see. */
    public function level(?int $folderId): ?string
    {
        if ($folderId === null) {
            return self::WRITE;
        }

        if (array_key_exists($folderId, $this->levels)) {
            return $this->levels[$folderId];
        }

        return $this->levels[$folderId] = $this->compute($folderId);
    }

    public function canRead(?int $folderId): bool
    {
        return $this->level($folderId) !== null;
    }

    public function canWrite(?int $folderId): bool
    {
        if ($folderId === null) {
            return $this->user->canChat();
        }

        return $this->level($folderId) === self::WRITE;
    }

    /** The project ties, settings and moving to the top: the owner of the top folder, or an administrator. */
    public function canManage(int $folderId): bool
    {
        return $this->user->isAdmin() || $this->root($folderId)?->user_id === $this->user->id;
    }

    public function canReadFile(DriveFile $file): bool
    {
        return $file->drive_folder_id === null
            ? $file->user_id === $this->user->id || $this->user->isAdmin()
            : $this->canRead($file->drive_folder_id);
    }

    public function canWriteFile(DriveFile $file): bool
    {
        return $file->drive_folder_id === null
            ? $file->user_id === $this->user->id || $this->user->isAdmin()
            : $this->canWrite($file->drive_folder_id);
    }

    /** The project whose access applies here: the nearest folder up the tree tied to one. */
    public function projectOf(?int $folderId): ?int
    {
        $folder = $folderId !== null ? $this->folders->get($folderId) : null;

        while ($folder !== null) {
            if ($folder->project_id !== null) {
                return $folder->project_id;
            }
            $folder = $folder->parent_id !== null ? $this->folders->get($folder->parent_id) : null;
        }

        return null;
    }

    /**
     * The folders this person sees whose parent they do not: where their drive starts.
     *
     * @return Collection<int, DriveFolder>
     */
    public function roots(): Collection
    {
        return $this->folders
            ->filter(fn (DriveFolder $f) => $this->canRead($f->id) && ($f->parent_id === null || ! $this->canRead($f->parent_id)))
            ->sortBy(fn (DriveFolder $f) => mb_strtolower($f->name))
            ->values();
    }

    /**
     * From the first folder this person sees down to this one.
     *
     * @return list<DriveFolder>
     */
    public function trail(int $folderId): array
    {
        $trail = [];
        $folder = $this->folders->get($folderId);

        while ($folder !== null && $this->canRead($folder->id)) {
            array_unshift($trail, $folder);
            $folder = $folder->parent_id !== null ? $this->folders->get($folder->parent_id) : null;
        }

        return $trail;
    }

    /**
     * This folder and every folder below it.
     *
     * @return list<int>
     */
    public function subtree(int $folderId): array
    {
        $ids = [$folderId];
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($this->folders as $folder) {
                if ($folder->parent_id === $ids[$i]) {
                    $ids[] = $folder->id;
                }
            }
        }

        return $ids;
    }

    /**
     * Folders this person may put things in, with their path, for "move to".
     *
     * @return Collection<int, string> id => path
     */
    public function writableFolders(): Collection
    {
        return $this->folders
            ->filter(fn (DriveFolder $f) => $this->canWrite($f->id))
            ->mapWithKeys(fn (DriveFolder $f) => [$f->id => implode(' / ', array_map(fn (DriveFolder $t) => $t->name, $this->trail($f->id)))])
            ->sort();
    }

    /**
     * Everyone who sees a folder and how, for the sharing table.
     *
     * @return Collection<int, array{user: User, role?: UserRole, level: string, via: string}>
     */
    public function audience(int $folderId): Collection
    {
        $root = $this->root($folderId);
        $projectId = $this->projectOf($folderId);
        $source = $projectId !== null ? $this->nearestTied($folderId) : null;
        $people = collect();

        if ($root !== null) {
            $people->put($root->user_id, ['user' => $root->user, 'level' => self::WRITE, 'via' => 'owner']);
        }

        foreach (User::query()->where('role', 'admin')->orderBy('name')->get() as $admin) {
            if (! $people->has($admin->id)) {
                $people->put($admin->id, ['user' => $admin, 'level' => self::WRITE, 'via' => 'admin']);
            }
        }

        if ($source !== null) {
            $members = Project::query()->find($projectId)?->members()->orderBy('name')->get() ?? collect();
            foreach ($members as $member) {
                $role = $member->membership->role ?? $member->defaultRole();
                $level = ($source->project_access ?? [])[$role->value] ?? null;
                if ($level !== null && ! $people->has($member->id)) {
                    $people->put($member->id, ['user' => $member, 'role' => $role, 'level' => $level, 'via' => 'project']);
                }
            }
        }

        return $people->values();
    }

    public function folder(int $folderId): ?DriveFolder
    {
        return $this->folders->get($folderId);
    }

    /** The id behind a folder's uuid, when this person may see the folder. */
    public function idOf(?string $uuid): ?int
    {
        if ($uuid === null) {
            return null;
        }

        $folder = $this->folders->firstWhere('uuid', $uuid);

        return $folder !== null && $this->canRead($folder->id) ? $folder->id : null;
    }

    private function compute(int $folderId): ?string
    {
        if ($this->user->isAdmin()) {
            return $this->folders->has($folderId) ? self::WRITE : null;
        }

        $root = $this->root($folderId);
        if ($root === null) {
            return null;
        }
        if ($root->user_id === $this->user->id) {
            return self::WRITE;
        }

        $tied = $this->nearestTied($folderId);
        if ($tied === null || ! isset($this->memberOf[(int) $tied->project_id])) {
            return null;
        }

        $level = ($tied->project_access ?? [])[$this->memberOf[(int) $tied->project_id]] ?? null;

        return in_array($level, [self::READ, self::WRITE], true) ? $level : null;
    }

    private function nearestTied(int $folderId): ?DriveFolder
    {
        $folder = $this->folders->get($folderId);

        while ($folder !== null && $folder->project_id === null) {
            $folder = $folder->parent_id !== null ? $this->folders->get($folder->parent_id) : null;
        }

        return $folder;
    }

    private function root(int $folderId): ?DriveFolder
    {
        $folder = $this->folders->get($folderId);
        $guard = 0;

        while ($folder !== null && $folder->parent_id !== null && $guard++ < 1000) {
            $folder = $this->folders->get($folder->parent_id);
        }

        return $folder;
    }
}
