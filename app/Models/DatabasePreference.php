<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one person chose for one connection they see: its group in their list, their star.
 *
 * @property int $id
 * @property int $user_id
 * @property int $database_connection_id
 * @property int|null $database_group_id
 * @property bool $favorite
 * @property-read DatabaseGroup|null $group
 */
#[Fillable(['user_id', 'database_connection_id', 'database_group_id', 'favorite'])]
class DatabasePreference extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['favorite' => 'boolean'];
    }

    /** @return BelongsTo<DatabaseGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(DatabaseGroup::class, 'database_group_id');
    }
}
