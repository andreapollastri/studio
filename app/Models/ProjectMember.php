<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A person on a project, and the role they have there: a developer on one
 * project may be the PM of another and a client on a third. A membership with
 * no role stored (from before roles were per project) counts with the person's
 * default one (User::roleIn).
 *
 * @property int $project_id
 * @property int $user_id
 * @property UserRole|null $role
 */
class ProjectMember extends Pivot
{
    protected $table = 'project_members';

    public $incrementing = true;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => UserRole::class];
    }
}
