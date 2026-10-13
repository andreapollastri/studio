<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\SshKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An SSH key: one of a person's, or, without a person, one of the
 * installation's (a system key), which administrators manage in System and
 * share with projects. The private half is stored in OpenSSH format,
 * encrypted with APP_KEY, and only ever leaves Studio towards the SSH gateway;
 * the pages show the public half to copy into authorized_keys.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id null: a system key
 * @property string $name
 * @property string $type ed25519, rsa or ecdsa
 * @property int|null $bits
 * @property string $public_key
 * @property string $private_key
 * @property string $fingerprint
 * @property-read User|null $user
 * @property-read Collection<int, Project> $projects
 */
#[Fillable(['user_id', 'name', 'type', 'bits', 'public_key', 'private_key', 'fingerprint'])]
class SshKey extends Model
{
    /** @use HasFactory<SshKeyFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $hidden = ['private_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'bits' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The projects a system key is shared with; pivot developers: their
     * developers may use it too, not only the administrators.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withPivot('developers')->withTimestamps();
    }

    /** @return HasMany<SshConnection, $this> */
    public function connections(): HasMany
    {
        return $this->hasMany(SshConnection::class);
    }

    /** @param  Builder<SshKey>  $query */
    public function scopeSystem(Builder $query): void
    {
        $query->whereNull('user_id');
    }

    /**
     * The keys a person may authenticate with on a connection of this project
     * (null: a personal one): their own, every system key for an administrator,
     * and for a developer the system keys shared with the developers of a
     * project they work on.
     *
     * @param  Builder<SshKey>  $query
     */
    public function scopeUsableBy(Builder $query, User $user, ?int $projectId): void
    {
        $query->where(function (Builder $query) use ($user, $projectId) {
            $query->where('user_id', $user->id);

            if ($user->isAdmin()) {
                $query->orWhereNull('user_id');
            } elseif ($projectId !== null && in_array($projectId, $user->projectIdsAs(UserRole::Dev), true)) {
                $query->orWhere(fn (Builder $shared) => $shared
                    ->whereNull('user_id')
                    ->whereHas('projects', fn (Builder $p) => $p->whereKey($projectId)->where('project_ssh_key.developers', true)));
            }
        });
    }

    /**
     * The system keys a person sees on the SSH page: every one for an
     * administrator, those shared with the developers of their projects for a
     * developer.
     *
     * @param  Builder<SshKey>  $query
     */
    public function scopeSharedWith(Builder $query, User $user): void
    {
        $query->whereNull('user_id');

        if (! $user->isAdmin()) {
            $query->whereHas('projects', fn (Builder $p) => $p
                ->where('project_ssh_key.developers', true)
                ->whereIn('projects.id', $user->projectIdsAs(UserRole::Dev)));
        }
    }

    public function isSystem(): bool
    {
        return $this->user_id === null;
    }

    /** Still usable by this person on a connection of this project: sharing can be taken back. */
    public function isUsableBy(User $user, ?int $projectId): bool
    {
        if (! $this->isSystem()) {
            return $this->user_id === $user->id;
        }

        return static::query()->usableBy($user, $projectId)->whereKey($this->id)->exists();
    }

    public function label(): string
    {
        return match ($this->type) {
            'ed25519' => 'Ed25519',
            'rsa' => 'RSA '.$this->bits,
            default => 'ECDSA '.$this->bits,
        };
    }
}
