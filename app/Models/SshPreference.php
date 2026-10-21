<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one person keeps one SSH connection they see: its group in their list. (Their star
 * is on the connection, or on their own credential for a shared one.)
 *
 * @property int $id
 * @property int $user_id
 * @property int $ssh_connection_id
 * @property int|null $ssh_group_id
 * @property-read SshGroup|null $group
 */
#[Fillable(['user_id', 'ssh_connection_id', 'ssh_group_id'])]
class SshPreference extends Model
{
    /** @return BelongsTo<SshGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(SshGroup::class, 'ssh_group_id');
    }
}
