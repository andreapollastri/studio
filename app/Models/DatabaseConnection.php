<?php

namespace App\Models;

use App\Databases\Driver;
use App\Databases\Environment;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\DatabaseConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A database a person reaches from Tools → Databases: MySQL, MariaDB, PostgreSQL, SQLite
 * (a file on an SSH host, or on this server for an administrator), Redis or Valkey. The
 * password is stored encrypted and never reaches a page.
 *
 * Through an SSH connection (ssh_connection_id) Studio opens a tunnel to host:port as seen
 * from that machine: with the owner's credential (tunnel_as owner: the members reach this
 * database through it, never a shell), or each member's own (tunnel_as member).
 *
 * Tied to a project, the project's roles reach it as project_access says (role => read or
 * write) and every administrator of Studio reads and writes it; they use the owner's
 * database credentials without ever seeing them.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int|null $project_id
 * @property array<string, string>|null $project_access
 * @property string $name
 * @property Driver $driver
 * @property Environment $environment
 * @property string|null $host
 * @property int|null $port
 * @property string|null $username
 * @property string|null $password
 * @property string|null $database
 * @property bool $tls
 * @property int|null $ssh_connection_id
 * @property string $tunnel_as owner or member
 * @property Carbon|null $last_used_at
 * @property-read User $user
 * @property-read Project|null $project
 * @property-read SshConnection|null $sshConnection
 */
#[Fillable(['user_id', 'project_id', 'project_access', 'name', 'driver', 'environment', 'host', 'port', 'username', 'password', 'database', 'tls', 'ssh_connection_id', 'tunnel_as', 'last_used_at'])]
class DatabaseConnection extends Model
{
    /** @use HasFactory<DatabaseConnectionFactory> */
    use HasFactory;

    use HasPublicUuid;

    public const ROLES = ['client', 'pm', 'dev'];

    public const READ = 'read';

    public const WRITE = 'write';

    protected $hidden = ['password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => Driver::class,
            'environment' => Environment::class,
            'project_access' => 'array',
            'port' => 'integer',
            'password' => 'encrypted',
            'tls' => 'boolean',
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

    /** @return BelongsTo<SshConnection, $this> */
    public function sshConnection(): BelongsTo
    {
        return $this->belongsTo(SshConnection::class);
    }

    /** @return HasMany<DatabasePreference, $this> */
    public function preferences(): HasMany
    {
        return $this->hasMany(DatabasePreference::class);
    }

    /** @return HasMany<DatabaseQuery, $this> */
    public function queries(): HasMany
    {
        return $this->hasMany(DatabaseQuery::class);
    }

    /** @return HasMany<DatabaseTransfer, $this> */
    public function transfers(): HasMany
    {
        return $this->hasMany(DatabaseTransfer::class);
    }

    /**
     * The person's own connections, every project connection for an administrator, and for
     * a member those of their projects where their role has access.
     *
     * @param  Builder<DatabaseConnection>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user) {
            $query->where('user_id', $user->id);

            if ($user->isAdmin()) {
                $query->orWhereNotNull('project_id');

                return;
            }

            foreach ($user->projects()->get() as $project) {
                $role = ($project->membership->role ?? $user->defaultRole())->value;
                $query->orWhere(fn (Builder $q) => $q
                    ->where('project_id', $project->id)
                    ->whereIn("project_access->{$role}", [self::READ, self::WRITE]));
            }
        });
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /** write, read, or null: what this person may do here. */
    public function accessFor(User $user): ?string
    {
        if ($this->isOwnedBy($user)) {
            return self::WRITE;
        }

        if ($this->project_id === null || $this->project === null) {
            return null;
        }

        if ($user->isAdmin()) {
            return self::WRITE;
        }

        $role = $user->roleIn($this->project);
        $level = $role === null ? null : ($this->project_access ?? [])[$role->value] ?? null;

        return in_array($level, [self::READ, self::WRITE], true) ? $level : null;
    }

    public function canWrite(User $user): bool
    {
        return $this->accessFor($user) === self::WRITE;
    }

    /** host:port, the file, or the database number, for the lists. */
    public function address(): string
    {
        if ($this->driver === Driver::Sqlite) {
            return (string) $this->database;
        }

        $port = $this->port !== null && $this->port !== $this->driver->defaultPort() ? ':'.$this->port : '';
        $user = filled($this->username) ? $this->username.'@' : '';
        $db = filled($this->database) ? '/'.$this->database : '';

        return $user.$this->host.$port.$db;
    }

    /** SQLite on Studio's own disk: a path here and no SSH connection. */
    public function isLocalFile(): bool
    {
        return $this->driver === Driver::Sqlite && $this->ssh_connection_id === null;
    }

    /** The person whose SSH credential opens the tunnel for this person. */
    public function tunnelUser(User $user): User
    {
        return $this->tunnel_as === 'member' ? $user : $this->user;
    }

    /** @return array<string, string> role => none|read|write, for the form */
    public function accessForForm(): array
    {
        return collect(self::ROLES)->mapWithKeys(fn (string $role) => [$role => ($this->project_access ?? [])[$role] ?? 'none'])->all();
    }
}
