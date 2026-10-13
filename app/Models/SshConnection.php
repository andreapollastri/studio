<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Database\Factories\SshConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A host a person saved for the SSH terminal, with a key of theirs or a
 * password (encrypted). host_key is the fingerprint accepted on the first
 * connection: a host that shows another one is refused.
 *
 * Tied to a project, the connection shows up for the project's administrators
 * and developers: host, port and user, never the owner's key or password.
 * They connect with a credential of their own (SshCredential).
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int|null $project_id
 * @property string $name
 * @property string $host
 * @property int $port
 * @property string $username
 * @property string $auth key or password
 * @property int|null $ssh_key_id
 * @property string|null $password
 * @property string|null $host_key
 * @property bool $favorite
 * @property Carbon|null $last_used_at
 * @property-read User $user
 * @property-read Project|null $project
 * @property-read SshKey|null $key
 */
#[Fillable(['user_id', 'project_id', 'name', 'host', 'port', 'username', 'auth', 'ssh_key_id', 'password', 'host_key', 'favorite', 'last_used_at'])]
class SshConnection extends Model
{
    /** @use HasFactory<SshConnectionFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $hidden = ['password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
            'favorite' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<SshKey, $this> */
    public function key(): BelongsTo
    {
        return $this->belongsTo(SshKey::class, 'ssh_key_id');
    }

    /** @return HasMany<SshCredential, $this> */
    public function credentials(): HasMany
    {
        return $this->hasMany(SshCredential::class);
    }

    /**
     * The person's own connections, and those of the projects they work on
     * when they may use SSH at all (administrators: every project).
     *
     * @param  Builder<SshConnection>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user) {
            $query->where('user_id', $user->id);

            if ($user->canUseSsh()) {
                $query->orWhere(fn (Builder $shared) => $user->isAdmin()
                    ? $shared->whereNotNull('project_id')
                    : $shared->whereIn('project_id', $user->projects()->select('projects.id')));
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

    /** user@host, with the port when it is not 22. */
    public function address(): string
    {
        return $this->username.'@'.$this->host.($this->port !== 22 ? ':'.$this->port : '');
    }

    /** The owner's credential is the connection's; anybody else brings their own. */
    public function credentialOf(User $user): ?SshCredential
    {
        return $this->isOwnedBy($user) ? null : $this->credentials()->with('key')->where('user_id', $user->id)->first();
    }

    /** Ready to connect for this person: a password, or a key that still exists and they may still use. */
    public function canConnect(User $user): bool
    {
        if ($this->isOwnedBy($user)) {
            return $this->auth === 'password' ? filled($this->password) : (bool) $this->key?->isUsableBy($user, $this->project_id);
        }

        return (bool) $this->credentialOf($user)?->isUsable();
    }

    /** How this person authenticates, for the list: "password" (the view translates it), the key's label, or null. */
    public function credentialLabel(User $user, ?SshCredential $credential = null): ?string
    {
        if ($this->isOwnedBy($user)) {
            return $this->auth === 'password' ? 'password' : $this->key?->label();
        }

        if ($credential === null || ! $credential->isUsable()) {
            return null;
        }

        return $credential->auth === 'password' ? 'password' : $credential->key?->label();
    }

    /**
     * What the gateway needs for one session of this person, their secret included.
     *
     * @return array{user_id: int, connection_id: int, name: string, host: string, port: int, username: string, auth: string, private_key?: string, password?: string, host_key: string|null}
     */
    public function forGateway(User $user): array
    {
        if ($this->isOwnedBy($user)) {
            [$auth, $password, $key] = [$this->auth, $this->password, $this->key];
        } else {
            // Somebody else's session never falls back to the owner's secret.
            $credential = $this->credentialOf($user);
            [$auth, $password, $key] = [(string) $credential?->auth, $credential?->password, $credential?->key];
        }

        return [
            'user_id' => $user->id,
            'connection_id' => $this->id,
            'name' => $this->name,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'auth' => $auth,
            ...($auth === 'password' ? ['password' => (string) $password] : ['private_key' => (string) $key?->private_key]),
            'host_key' => $this->host_key,
        ];
    }
}
