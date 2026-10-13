<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder of the drive. Tied to a project, it opens to the project's members
 * by role (project_access: role => read|write), and so does everything below
 * it until a folder further down is tied to a project of its own. The owner
 * of the top folder and the administrators always read and write.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $parent_id
 * @property int|null $project_id
 * @property array<string, string>|null $project_access
 * @property string $name
 * @property-read User $user
 * @property-read DriveFolder|null $parent
 * @property-read Project|null $project
 */
#[Fillable(['user_id', 'parent_id', 'project_id', 'project_access', 'name'])]
class DriveFolder extends Model
{
    public const ROLES = ['client', 'pm', 'dev'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['project_access' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<DriveFolder, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(DriveFolder::class, 'parent_id');
    }

    /** @return HasMany<DriveFolder, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(DriveFolder::class, 'parent_id');
    }

    /** @return HasMany<DriveFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(DriveFile::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
