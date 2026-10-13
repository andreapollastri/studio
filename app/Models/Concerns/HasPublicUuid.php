<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * A public, unguessable identifier next to the integer key: links, buttons,
 * pickers and broadcast channels use the uuid, the database keeps its ids.
 */
trait HasPublicUuid
{
    use HasUuids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
