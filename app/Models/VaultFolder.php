<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder of one person's password manager; folders nest. Folders are for
 * putting one's own items in order: sharing happens item by item.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int|null $parent_id
 * @property string $name
 * @property-read User $user
 * @property-read VaultFolder|null $parent
 */
#[Fillable(['user_id', 'parent_id', 'name'])]
class VaultFolder extends Model
{
    use HasPublicUuid;

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
