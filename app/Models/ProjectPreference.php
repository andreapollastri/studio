<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one person chose for one project: the group it sits in on their list,
 * and the Claude and git accounts they work on it with, when not the group's.
 *
 * @property int $id
 * @property int $user_id
 * @property int $project_id
 * @property int|null $project_group_id
 * @property int|null $claude_account_id
 * @property int|null $git_account_id
 * @property-read User $user
 * @property-read Project $project
 * @property-read ProjectGroup|null $group
 * @property-read ClaudeAccount|null $claudeAccount
 * @property-read ConnectorAccount|null $gitAccount
 */
#[Fillable(['user_id', 'project_id', 'project_group_id', 'claude_account_id', 'git_account_id'])]
class ProjectPreference extends Model
{
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

    /** @return BelongsTo<ProjectGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProjectGroup::class, 'project_group_id');
    }

    /** @return BelongsTo<ClaudeAccount, $this> */
    public function claudeAccount(): BelongsTo
    {
        return $this->belongsTo(ClaudeAccount::class);
    }

    /** @return BelongsTo<ConnectorAccount, $this> */
    public function gitAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class, 'git_account_id');
    }
}
