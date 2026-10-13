<?php

namespace App\Drive;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\Project;
use App\Models\Workspace;

/**
 * "Copy to" and "Move to" the project: files and whole folders of the drive go
 * into the person's own workspace of the project the folder is tied to,
 * through the bridge, byte for byte. Only someone who has the project's file
 * manager (anyone but a client, on a workspace of their own) gets here; a move
 * removes from the drive only what arrived and what they may write.
 */
final class WorkspaceTransfer
{
    /** @var list<string> */
    private array $failed = [];

    private int $copied = 0;

    private int $removed = 0;

    public function __construct(private readonly Access $access, private readonly Workspace $workspace) {}

    /** The workspace this person's copies go to, for the project a folder is tied to; null: none they may use. */
    public static function workspaceFor(Access $access, ?int $folderId): ?Workspace
    {
        $user = $access->user();
        $projectId = $access->projectOf($folderId);
        $project = $projectId !== null ? Project::query()->find($projectId) : null;

        if ($project === null || ! $user->canChat() || ! $user->canAccess($project)) {
            return null;
        }

        return $project->workspaceFor($user);
    }

    /**
     * @param  list<int>  $folderIds
     * @param  list<int>  $fileIds
     * @return array{copied: int, removed: int, failed: list<string>}
     */
    public function run(array $folderIds, array $fileIds, string $destination, bool $overwrite, bool $move): array
    {
        $client = new BridgeClient($this->workspace);
        $destination = trim($destination, '/');

        foreach (DriveFile::query()->whereIn('id', $fileIds)->get() as $file) {
            if ($this->access->canReadFile($file) && $this->copyFile($client, $file, $destination, $overwrite) && $move && $this->access->canWriteFile($file)) {
                $file->delete();
                $this->removed++;
            }
        }

        foreach (DriveFolder::query()->whereIn('id', $folderIds)->get() as $folder) {
            if ($this->access->canRead($folder->id)) {
                $this->copyFolder($client, $folder, $destination, $overwrite, $move);
            }
        }

        return ['copied' => $this->copied, 'removed' => $this->removed, 'failed' => $this->failed];
    }

    /** True when every file below arrived; a move then removes the folder too. */
    private function copyFolder(BridgeClient $client, DriveFolder $folder, string $parent, bool $overwrite, bool $move): bool
    {
        $path = ltrim($parent.'/'.$folder->name, '/');
        $complete = true;

        try {
            $client->createPath($path, 'dir');
        } catch (BridgeException $e) {
            // Already there is fine: files are merged in.
            if (! str_contains($e->getMessage(), '409')) {
                $this->failed[] = $path.': '.$e->getMessage();

                return false;
            }
        }

        foreach ($folder->files()->orderBy('name')->get() as $file) {
            $ok = $this->copyFile($client, $file, $path, $overwrite);
            $complete = $complete && $ok;
            if ($ok && $move && $this->access->canWrite($folder->id)) {
                $file->delete();
                $this->removed++;
            }
        }

        foreach ($folder->children()->orderBy('name')->get() as $child) {
            if ($this->access->canRead($child->id)) {
                $complete = $this->copyFolder($client, $child, $path, $overwrite, $move) && $complete;
            }
        }

        if ($complete && $move && $this->access->canWrite($folder->id) && ! $folder->files()->exists() && ! $folder->children()->exists()) {
            $folder->delete();
        }

        return $complete;
    }

    private function copyFile(BridgeClient $client, DriveFile $file, string $dir, bool $overwrite): bool
    {
        $path = ltrim($dir.'/'.$file->name, '/');
        $stream = @fopen($file->absolutePath(), 'rb');

        if ($stream === false) {
            $this->failed[] = $path.': '.__('the file is missing from the drive storage');

            return false;
        }

        try {
            $client->uploadFile($path, $stream, $overwrite);
            $this->copied++;

            return true;
        } catch (BridgeException $e) {
            $this->failed[] = $path.': '.$e->getMessage();

            return false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
