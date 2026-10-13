<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of projects of one person (Personal, Open source, Work, Clients…):
 * how they order their list, and which Claude account and git account the
 * projects in it use unless a project says otherwise. A git account applies
 * only to the projects whose repository lives on its host.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $name
 * @property string $color
 * @property int $position
 * @property int|null $claude_account_id
 * @property int|null $git_account_id
 * @property-read User $user
 * @property-read ClaudeAccount|null $claudeAccount
 * @property-read ConnectorAccount|null $gitAccount
 */
#[Fillable(['user_id', 'name', 'color', 'position', 'claude_account_id', 'git_account_id'])]
class ProjectGroup extends Model
{
    use HasPublicUuid;

    /** The colours a group may take, as Flux badge colours. */
    public const COLORS = ['zinc', 'red', 'orange', 'amber', 'lime', 'emerald', 'teal', 'sky', 'blue', 'indigo', 'violet', 'pink'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    /** @return HasMany<ProjectPreference, $this> */
    public function preferences(): HasMany
    {
        return $this->hasMany(ProjectPreference::class);
    }
}
