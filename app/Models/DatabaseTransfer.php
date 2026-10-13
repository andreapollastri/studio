<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * An export or an import of a connection, run on the databases queue: what it covers (the
 * database, the tables or a query, the format's options), its live log, and the file, on
 * the local disk under database-transfers/, an export kept for a few days to download or
 * save to the drive.
 *
 * @property int $id
 * @property string $uuid
 * @property int $database_connection_id
 * @property int|null $user_id
 * @property string $kind export or import
 * @property string $format sql, csv or json
 * @property array<string, mixed> $scope
 * @property string $status queued, running, success or failed
 * @property string|null $file_path
 * @property string|null $file_name
 * @property int|null $bytes
 * @property int|null $rows
 * @property string|null $output
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property-read DatabaseConnection $connection
 * @property-read User|null $user
 */
#[Fillable(['database_connection_id', 'user_id', 'kind', 'format', 'scope', 'status', 'file_path', 'file_name', 'bytes', 'rows', 'output', 'error', 'started_at', 'finished_at', 'expires_at'])]
class DatabaseTransfer extends Model
{
    use HasPublicUuid;

    public const DIR = 'database-transfers';

    protected static function booted(): void
    {
        static::deleted(function (DatabaseTransfer $transfer) {
            if ($transfer->file_path !== null) {
                Storage::disk('local')->delete($transfer->file_path);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'bytes' => 'integer',
            'rows' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
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

    public function isFinished(): bool
    {
        return in_array($this->status, ['success', 'failed'], true);
    }

    /** An export whose file is still here to download. */
    public function hasFile(): bool
    {
        return $this->kind === 'export' && $this->status === 'success' && $this->file_path !== null && Storage::disk('local')->exists($this->file_path);
    }

    public function absolutePath(): string
    {
        return Storage::disk('local')->path((string) $this->file_path);
    }

    /** What it covers, in a few words for the list. */
    public function summary(): string
    {
        $scope = $this->scope;

        if (isset($scope['query'])) {
            return __('query result');
        }

        $tables = (array) ($scope['tables'] ?? []);

        if (isset($scope['table'])) {
            return (string) $scope['table'];
        }

        return $tables === [] ? (string) ($scope['database'] ?? __('database')) : implode(', ', array_slice($tables, 0, 4)).(count($tables) > 4 ? ' +'.(count($tables) - 4) : '');
    }

    public function appendOutput(string $text): void
    {
        $this->output = mb_substr(($this->output ?? '').$text, -200000);
    }

    public function log(string $line): void
    {
        $this->appendOutput('['.now()->format('H:i:s').'] '.$line."\n");
        $this->save();
    }
}
