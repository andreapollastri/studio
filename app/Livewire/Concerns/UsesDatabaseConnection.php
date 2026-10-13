<?php

namespace App\Livewire\Concerns;

use App\Databases\Connector;
use App\Databases\DatabaseException;
use App\Jobs\RunDatabaseTransfer;
use App\Models\DatabaseConnection;
use App\Models\DatabaseTransfer;
use App\Models\DriveFile;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * What the SQL client and the Redis browser share: the connection, checked again on every
 * request (a role or a share may have changed), what this person may do on it, the lock
 * of a production connection, and the imports and exports with their files.
 *
 * @property DatabaseConnection $connection
 */
trait UsesDatabaseConnection
{
    protected function guard(): void
    {
        abort_if($this->connection->accessFor(auth()->user()) === null, 404);
    }

    public function hydrateUsesDatabaseConnection(): void
    {
        $this->connection->refresh();
        $this->guard();
    }

    protected function connector(): Connector
    {
        return app(Connector::class);
    }

    public function canWrite(): bool
    {
        return $this->connection->canWrite(auth()->user());
    }

    /** A production connection opens with its changes locked, until the person unlocks them for this session. */
    public function isLocked(): bool
    {
        return $this->connection->environment->isProduction() && ! session()->get($this->lockKey(), false);
    }

    public function toggleLock(): void
    {
        session()->put($this->lockKey(), $this->isLocked());
        Log::info('database changes '.($this->isLocked() ? 'locked' : 'unlocked'), ['user' => auth()->user()->email, 'connection' => $this->connection->name]);
    }

    private function lockKey(): string
    {
        return 'databases.unlocked.'.$this->connection->id;
    }

    /** Refuse a change this person may not make now, saying why. */
    protected function ensureWritable(): void
    {
        if (! $this->canWrite()) {
            throw new DatabaseException(__('You may only read this connection.'));
        }

        if ($this->isLocked()) {
            throw new DatabaseException(__('This is a production database: unlock changes first (the lock at the top).'));
        }
    }

    protected function fail(\Throwable $e): void
    {
        Flux::toast(variant: 'danger', text: DatabaseException::from($e)->getMessage());
    }

    /** @return Collection<int, DatabaseTransfer> */
    public function transfersList(): Collection
    {
        $user = auth()->user();

        return $this->connection->transfers()->with('user')
            ->when(! $this->connection->isOwnedBy($user) && ! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->latest('id')->limit(30)->get();
    }

    /** @param  array<string, mixed>  $scope */
    protected function queueTransfer(string $kind, string $format, array $scope, ?UploadedFile $file = null, ?DriveFile $driveFile = null): DatabaseTransfer
    {
        $transfer = DatabaseTransfer::query()->create([
            'database_connection_id' => $this->connection->id,
            'user_id' => auth()->id(),
            'kind' => $kind,
            'format' => $format,
            'scope' => $scope,
        ]);

        if ($file !== null || $driveFile !== null) {
            $original = $file !== null ? $file->getClientOriginalName() : $driveFile->name;
            $name = Str::limit(str_replace(['/', '\\'], '-', $original), 180, '');
            $relative = DatabaseTransfer::DIR.'/'.$transfer->uuid.'/'.$name;
            if ($file !== null) {
                $file->storeAs(dirname($relative), $name, 'local');
            } else {
                File::ensureDirectoryExists(dirname(Storage::disk('local')->path($relative)));
                File::copy($driveFile->absolutePath(), Storage::disk('local')->path($relative));
            }
            $transfer->forceFill(['file_path' => $relative, 'file_name' => $name, 'bytes' => (int) Storage::disk('local')->size($relative)])->save();
        }

        Log::info('database transfer queued', ['user' => auth()->user()->email, 'connection' => $this->connection->name, 'kind' => $kind, 'format' => $format]);
        RunDatabaseTransfer::dispatch($transfer->id);

        return $transfer->refresh();
    }

    public function deleteTransfer(string $id): void
    {
        $transfer = $this->transfer($id);
        abort_if($transfer->status === 'running', 422);
        Storage::disk('local')->deleteDirectory(DatabaseTransfer::DIR.'/'.$transfer->uuid);
        $transfer->delete();

        Flux::toast(text: __('Removed.'));
    }

    /** An export's file, copied to the top of the person's drive. */
    public function saveToDrive(string $id): void
    {
        $transfer = $this->transfer($id);
        abort_unless($transfer->hasFile(), 404);

        $stored = 'drive/'.Str::uuid()->toString();
        Storage::disk('local')->copy((string) $transfer->file_path, $stored);

        DriveFile::query()->create([
            'user_id' => auth()->id(),
            'drive_folder_id' => null,
            'name' => (string) $transfer->file_name,
            'disk_path' => $stored,
            'size' => (int) $transfer->bytes,
            'mime' => str_ends_with((string) $transfer->file_name, '.gz') ? 'application/gzip' : (str_ends_with((string) $transfer->file_name, '.zip') ? 'application/zip' : 'text/plain'),
        ]);

        Flux::toast(variant: 'success', text: __(':name is in your drive.', ['name' => $transfer->file_name]));
    }

    /**
     * Files of the person's drive that could be imported.
     *
     * @param  list<string>  $extensions
     * @return Collection<int, DriveFile>
     */
    public function driveFiles(array $extensions): Collection
    {
        return DriveFile::query()->where('user_id', auth()->id())
            ->where(function ($q) use ($extensions) {
                foreach ($extensions as $extension) {
                    $q->orWhere('name', 'like', '%.'.$extension);
                }
            })
            ->latest('id')->limit(50)->get();
    }

    private function transfer(string $id): DatabaseTransfer
    {
        return $this->transfersList()->firstWhere('uuid', $id) ?? abort(404);
    }
}
