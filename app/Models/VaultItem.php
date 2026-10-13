<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\VaultItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One secret of the password manager. Username, password, notes and the TOTP
 * secret are encrypted with APP_KEY; title and URL stay readable for search.
 *
 * Who sees it: its owner; the people it is shared with, to read or to change;
 * and, when it belongs to a project, the project's members whose role is
 * ticked in project_roles (client, pm, dev) plus every administrator.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int|null $vault_folder_id
 * @property int|null $project_id
 * @property list<string>|null $project_roles
 * @property string $title
 * @property string|null $url
 * @property string|null $username
 * @property string|null $password
 * @property string|null $notes
 * @property string|null $totp_secret
 * @property int|null $updated_by
 * @property-read User $user
 * @property-read VaultFolder|null $folder
 * @property-read Project|null $project
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $sharedWith
 */
#[Fillable(['user_id', 'vault_folder_id', 'project_id', 'project_roles', 'title', 'url', 'username', 'password', 'notes', 'totp_secret', 'updated_by'])]
class VaultItem extends Model
{
    /** @use HasFactory<VaultItemFactory> */
    use HasFactory;

    use HasPublicUuid;

    /** The roles a project item can be opened to. */
    public const PROJECT_ROLES = ['client', 'pm', 'dev'];

    protected $hidden = ['username', 'password', 'notes', 'totp_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_roles' => 'array',
            'username' => 'encrypted',
            'password' => 'encrypted',
            'notes' => 'encrypted',
            'totp_secret' => 'encrypted',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<VaultFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(VaultFolder::class, 'vault_folder_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function sharedWith(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'vault_item_user')->withPivot('can_edit')->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @param  Builder<VaultItem>  $query */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user) {
            $query->where('user_id', $user->id)
                ->orWhereHas('sharedWith', fn (Builder $q) => $q->whereKey($user->id));

            if ($user->isAdmin()) {
                $query->orWhereNotNull('project_id');
            } else {
                foreach (UserRole::projectRoles() as $role) {
                    if (($ids = $user->projectIdsAs($role)) !== []) {
                        $query->orWhere(fn (Builder $q) => $q->whereIn('project_id', $ids)->whereJsonContains('project_roles', $role->value));
                    }
                }
            }
        });
    }

    public function isVisibleTo(User $user): bool
    {
        return static::query()->visibleTo($user)->whereKey($this->id)->exists();
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /** The owner, an administrator on a project item, or someone it is shared with to change. */
    public function canEdit(User $user): bool
    {
        return $this->isOwnedBy($user)
            || ($user->isAdmin() && $this->project_id !== null)
            || $this->sharedWith()->whereKey($user->id)->wherePivot('can_edit', true)->exists();
    }

    /** Project, people and deletion: the owner, or an administrator on a project item. */
    public function canManage(User $user): bool
    {
        return $this->isOwnedBy($user) || ($user->isAdmin() && $this->project_id !== null);
    }

    /**
     * Everyone who sees the item and why, for the sharing table.
     *
     * @return Collection<int, array{user: User, role: UserRole|null, via: string, can_edit: bool}>
     */
    public function audience(): Collection
    {
        $people = [$this->user_id => $this->entry($this->user, null, 'owner', true)];

        $editors = DB::table('vault_item_user')->where('vault_item_id', $this->id)->pluck('can_edit', 'user_id');
        foreach ($this->sharedWith as $user) {
            $people[$user->id] = $this->entry($user, null, 'direct', (bool) ($editors[$user->id] ?? false));
        }

        if ($this->project_id !== null) {
            $roles = $this->project_roles ?? [];
            $members = User::query()
                ->where(fn (Builder $q) => $q
                    ->where('role', UserRole::Admin->value)
                    ->orWhere(fn (Builder $q) => $q->withRoleIn($this->project_id, $roles)))
                ->orderBy('name')
                ->get();

            foreach ($members as $user) {
                $people[$user->id] ??= $this->entry($user, $user->roleIn($this->project), $user->isAdmin() ? 'admin' : 'project', $user->isAdmin());
            }
        }

        return collect(array_values($people));
    }

    /** @return array{user: User, role: UserRole|null, via: string, can_edit: bool} */
    private function entry(User $user, ?UserRole $role, string $via, bool $canEdit): array
    {
        return ['user' => $user, 'role' => $role, 'via' => $via, 'can_edit' => $canEdit];
    }
}
