<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A folder of one person's password manager; folders nest. It may be shared as
 * an item is (with a project, for the roles ticked, and with single people, to
 * view or to edit): everything inside it goes with it, the folders below and the
 * items added later included (App\Vault\FolderAccess).
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int|null $parent_id
 * @property string $name
 * @property int|null $project_id
 * @property list<string>|null $project_roles
 * @property-read User $user
 * @property-read Project|null $project
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $sharedWith
 * @property-read VaultFolder|null $parent
 */
#[Fillable(['user_id', 'parent_id', 'project_id', 'project_roles', 'name'])]
class VaultFolder extends Model
{
    use HasPublicUuid;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['project_roles' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<VaultFolder, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(VaultFolder::class, 'parent_id');
    }

    /** @return HasMany<VaultFolder, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(VaultFolder::class, 'parent_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function sharedWith(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'vault_folder_user')->withPivot('can_edit')->withTimestamps();
    }

    /** Shared by itself: with a project or with people (a folder above it may share it too). */
    public function isShared(): bool
    {
        return $this->project_id !== null || $this->sharedWith()->exists();
    }

    /**
     * The folders above this one, nearest first.
     *
     * @return list<VaultFolder>
     */
    public function ancestors(): array
    {
        $chain = [];
        $parent = $this->parent;

        while ($parent !== null && count($chain) < 50) {
            $chain[] = $parent;
            $parent = $parent->parent;
        }

        return $chain;
    }

    /**
     * Everyone who reaches the folder and why: its owner, the people it (or a folder above
     * it) is shared with, the project members whose role is ticked, the administrators on a
     * project folder.
     *
     * @return Collection<int, array{user: User, role: UserRole|null, via: string, from: string|null, can_edit: bool}>
     */
    public function audience(): Collection
    {
        $people = [$this->user_id => ['user' => $this->user, 'role' => null, 'via' => 'owner', 'from' => null, 'can_edit' => true]];

        foreach ([$this, ...$this->ancestors()] as $folder) {
            $from = $folder->is($this) ? null : $folder->name;

            $edits = DB::table('vault_folder_user')->where('vault_folder_id', $folder->id)->pluck('can_edit', 'user_id');

            foreach ($folder->sharedWith as $user) {
                $edit = (bool) ($edits[$user->id] ?? false);
                if (! isset($people[$user->id]) || ($edit && ! $people[$user->id]['can_edit'] && $people[$user->id]['via'] !== 'owner')) {
                    $people[$user->id] = ['user' => $user, 'role' => null, 'via' => 'direct', 'from' => $from, 'can_edit' => $edit];
                }
            }

            if ($folder->project_id !== null) {
                $members = User::query()
                    ->where(fn (Builder $q) => $q->where('role', UserRole::Admin->value)->orWhere(fn (Builder $q) => $q->withRoleIn($folder->project_id, $folder->project_roles ?? [])))
                    ->orderBy('name')->get();

                foreach ($members as $user) {
                    $people[$user->id] ??= ['user' => $user, 'role' => $user->roleIn($folder->project), 'via' => $user->isAdmin() ? 'admin' : 'project', 'from' => $from, 'can_edit' => $user->isAdmin()];
                }
            }
        }

        return collect(array_values($people));
    }

    /** @return HasMany<VaultItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(VaultItem::class);
    }

    /**
     * This folder and every folder below it.
     *
     * @return list<int>
     */
    public function subtreeIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];

        while ($frontier !== []) {
            $frontier = array_values(array_map(intval(...), VaultFolder::query()->whereIn('parent_id', $frontier)->pluck('id')->all()));
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }
}
