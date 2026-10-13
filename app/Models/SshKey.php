<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Database\Factories\SshKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of a person's SSH keys. The private half is stored in OpenSSH format,
 * encrypted with APP_KEY, and only ever leaves Studio towards the SSH gateway;
 * the page shows the public half to copy into authorized_keys.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $name
 * @property string $type ed25519, rsa or ecdsa
 * @property int|null $bits
 * @property string $public_key
 * @property string $private_key
 * @property string $fingerprint
 * @property-read User $user
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

    /** @return HasMany<SshConnection, $this> */
    public function connections(): HasMany
    {
        return $this->hasMany(SshConnection::class);
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
