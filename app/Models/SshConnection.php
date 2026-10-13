<?php

namespace App\Models;

use Database\Factories\SshConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A host a person saved for the SSH terminal, with a key of theirs or a
 * password (encrypted). host_key is the fingerprint accepted on the first
 * connection: a host that shows another one is refused.
 *
 * @property int $id
 * @property int $user_id
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
 * @property-read SshKey|null $key
 */
#[Fillable(['user_id', 'name', 'host', 'port', 'username', 'auth', 'ssh_key_id', 'password', 'host_key', 'favorite', 'last_used_at'])]
class SshConnection extends Model
{
    /** @use HasFactory<SshConnectionFactory> */
    use HasFactory;

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

    /** @return BelongsTo<SshKey, $this> */
    public function key(): BelongsTo
    {
        return $this->belongsTo(SshKey::class, 'ssh_key_id');
    }

    /** user@host, with the port when it is not 22. */
    public function address(): string
    {
        return $this->username.'@'.$this->host.($this->port !== 22 ? ':'.$this->port : '');
    }

    /** Ready to connect: a key that still exists, or a password. */
    public function hasCredential(): bool
    {
        return $this->auth === 'password' ? filled($this->password) : $this->key !== null;
    }

    /**
     * What the gateway needs for one session, secret included.
     *
     * @return array{user_id: int, connection_id: int, name: string, host: string, port: int, username: string, auth: string, private_key?: string, password?: string, host_key: string|null}
     */
    public function forGateway(): array
    {
        return [
            'user_id' => $this->user_id,
            'connection_id' => $this->id,
            'name' => $this->name,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'auth' => $this->auth,
            ...($this->auth === 'password' ? ['password' => (string) $this->password] : ['private_key' => (string) $this->key?->private_key]),
            'host_key' => $this->host_key,
        ];
    }
}
