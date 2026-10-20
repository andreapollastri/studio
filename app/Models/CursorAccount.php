<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Database\Factories\CursorAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One of a person's Cursor subscriptions. It is a sign-in to the unmodified
 * Cursor CLI in a folder of the person's home on the server (`dir`: .cursor
 * for the first, .cursor-<slug> for the others), through Cursor's own
 * `agent login`. Studio never sees the login; it only asks the CLI whether
 * it is signed in.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $name
 * @property string $dir
 * @property bool $signed_in
 * @property Carbon|null $checked_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'name', 'dir', 'signed_in', 'checked_at'])]
class CursorAccount extends Model
{
    /** @use HasFactory<CursorAccountFactory> */
    use HasFactory;

    use HasPublicUuid;

    /** The folder of the first subscription, where the CLI signs in by default. */
    public const DEFAULT_DIR = '.cursor';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signed_in' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A free folder for a new subscription of this person: .cursor while none uses it, then .cursor-<slug>. */
    public static function freeDir(User $user): string
    {
        $taken = $user->cursorAccounts()->pluck('dir')->all();

        if (! in_array(self::DEFAULT_DIR, $taken, true)) {
            return self::DEFAULT_DIR;
        }

        do {
            $dir = self::DEFAULT_DIR.'-'.Str::lower(Str::random(10));
        } while (in_array($dir, $taken, true));

        return $dir;
    }
}
