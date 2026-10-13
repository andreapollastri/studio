<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of database connections of one person (Clients, Production, Side projects…), with
 * its colour and its place in their list. The connections shared with them go in their
 * groups too: where a connection sits is each person's own preference.
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
class DatabaseGroup extends Model
{
    use HasPublicUuid;

    /** The colours a group may take, as Flux badge colours: the projects' groups' own. */
    public const COLORS = ProjectGroup::COLORS;

    /** The dot of the colour, a Tailwind class. */
    public static function dot(?string $color): string
    {
        return match ($color) {
            'red' => 'bg-red-500', 'orange' => 'bg-orange-500', 'amber' => 'bg-amber-500', 'lime' => 'bg-lime-500',
            'emerald' => 'bg-emerald-500', 'teal' => 'bg-teal-500', 'sky' => 'bg-sky-500', 'blue' => 'bg-blue-500',
            'indigo' => 'bg-indigo-500', 'violet' => 'bg-violet-500', 'pink' => 'bg-pink-500', default => 'bg-zinc-400',
        };
    }

    /** The edge of a connection's card in the group. */
    public static function edge(?string $color): string
    {
        return match ($color) {
            'red' => 'border-s-red-500', 'orange' => 'border-s-orange-500', 'amber' => 'border-s-amber-500', 'lime' => 'border-s-lime-500',
            'emerald' => 'border-s-emerald-500', 'teal' => 'border-s-teal-500', 'sky' => 'border-s-sky-500', 'blue' => 'border-s-blue-500',
            'indigo' => 'border-s-indigo-500', 'violet' => 'border-s-violet-500', 'pink' => 'border-s-pink-500', default => 'border-s-zinc-300 dark:border-s-zinc-600',
        };
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<DatabasePreference, $this> */
    public function preferences(): HasMany
    {
        return $this->hasMany(DatabasePreference::class);
    }
}
