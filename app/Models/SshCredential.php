<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one person brings to a connection someone else shared through a
 * project: their own key or password (never the owner's), and their own star.
 *
 * @property int $id
 * @property int $ssh_connection_id
 * @property int $user_id
 * @property string|null $auth key or password; null: nothing set yet
 * @property int|null $ssh_key_id
 * @property string|null $password
 * @property bool $favorite
 * @property-read SshConnection $connection
 * @property-read User $user
 * @property-read SshKey|null $key
 */
#[Fillable(['ssh_connection_id', 'user_id', 'auth', 'ssh_key_id', 'password', 'favorite'])]
class SshCredential extends Model
{
    protected $hidden = ['password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'favorite' => 'boolean',
        ];
    }

    /** @return BelongsTo<SshConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(SshConnection::class, 'ssh_connection_id');
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

    public function isUsable(): bool
    {
        return match ($this->auth) {
            'key' => $this->key !== null,
            'password' => filled($this->password),
            default => false,
        };
    }
}
