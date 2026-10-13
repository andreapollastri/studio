<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something one person ran in the SQL editor or the Redis console of a connection: their
 * history (the newest few hundred are kept), or a query they saved under a title.
 *
 * @property int $id
 * @property string $uuid
 * @property int $database_connection_id
 * @property int $user_id
 * @property string|null $database
 * @property string $sql
 * @property string|null $title
 * @property bool $saved
 * @property string $status ok or error
 * @property int|null $duration_ms
 * @property string|null $error
 * @property-read DatabaseConnection $connection
 * @property-read User $user
 */
#[Fillable(['database_connection_id', 'user_id', 'database', 'sql', 'title', 'saved', 'status', 'duration_ms', 'error'])]
class DatabaseQuery extends Model
{
    use HasPublicUuid;

    public const KEEP = 300;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'saved' => 'boolean',
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<DatabaseConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(DatabaseConnection::class, 'database_connection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Write down one run, and forget the oldest of this person's history on this connection. */
    public static function record(DatabaseConnection $connection, User $user, ?string $database, string $sql, ?string $error, int $ms): void
    {
        static::query()->create([
            'database_connection_id' => $connection->id,
            'user_id' => $user->id,
            'database' => $database,
            'sql' => mb_substr($sql, 0, 60000),
            'status' => $error === null ? 'ok' : 'error',
            'duration_ms' => $ms,
            'error' => $error === null ? null : mb_substr($error, 0, 2000),
        ]);

        $old = static::query()->where('database_connection_id', $connection->id)->where('user_id', $user->id)->where('saved', false)
            ->orderByDesc('id')->skip(self::KEEP)->take(100)->pluck('id');

        if ($old->isNotEmpty()) {
            static::query()->whereKey($old->all())->delete();
        }
    }
}
