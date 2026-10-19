<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a person told Studio to always allow on one project, from a
 * permission card: a rule of Claude Code's own syntax (Bash(npm run build:*)),
 * handed to every turn of their conversations there as --allowedTools.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $project_id
 * @property string $rule
 * @property-read User $user
 * @property-read Project $project
 */
#[Fillable(['user_id', 'project_id', 'rule'])]
class PermissionRule extends Model
{
    use HasPublicUuid;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
