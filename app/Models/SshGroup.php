<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of SSH connections of one person (Clients, Production…), with its colour and its
 * place in their list, as the databases' groups. The connections shared with them go in their
 * groups too: where a connection sits is each person's own preference (SshPreference).
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $name
 * @property string $color
 * @property int $position
 * @property-read User $user
 */
#[Fillable(['user_id', 'name', 'color', 'position'])]
class SshGroup extends Model
{
    use HasPublicUuid;

    /** The colours a group may take: the databases' groups' own, and their dots (DatabaseGroup::dot). */
    public const COLORS = DatabaseGroup::COLORS;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<SshPreference, $this> */
    public function preferences(): HasMany
    {
        return $this->hasMany(SshPreference::class);
    }
}
