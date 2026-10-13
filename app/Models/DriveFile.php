<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file of the drive. Its bytes live on the local disk under drive/ with a
 * random name; the name people see is only in the database. A file outside
 * any folder is its uploader's alone.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int|null $drive_folder_id
 * @property string $name
 * @property string $disk_path
 * @property int $size
 * @property string|null $mime
 * @property-read User $user
 * @property-read DriveFolder|null $folder
 */
#[Fillable(['user_id', 'drive_folder_id', 'name', 'disk_path', 'size', 'mime'])]
class DriveFile extends Model
{
    use HasPublicUuid;

    protected static function booted(): void
    {
        static::deleted(fn (DriveFile $file) => Storage::disk('local')->delete($file->disk_path));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<DriveFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(DriveFolder::class, 'drive_folder_id');
    }

    /** Images and PDFs open in the browser; everything else downloads. */
    public function isPreviewable(): bool
    {
        return in_array($this->mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain'], true);
    }

    public function absolutePath(): string
    {
        return Storage::disk('local')->path($this->disk_path);
    }
}
