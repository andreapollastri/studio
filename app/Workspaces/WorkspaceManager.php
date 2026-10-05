<?php

namespace App\Workspaces;

use App\Enums\WorkspaceStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use InvalidArgumentException;

final class WorkspaceManager
{
    public function driver(?string $name = null): WorkspaceDriver
    {
        $name ??= (string) config('studio.driver', 'local');

        return match ($name) {
            'local' => app(LocalDriver::class),
            'native' => app(NativeDriver::class),
            default => throw new InvalidArgumentException("Unknown workspace driver [{$name}]."),
        };
    }

    public function driverFor(Workspace $workspace): WorkspaceDriver
    {
        return $this->driver($workspace->driver ?: null);
    }

    /** The one workspace this person has on this project, created on first use. */
    public function forUser(Project $project, User $user): Workspace
    {
        return Workspace::query()->firstOrCreate(
            ['project_id' => $project->id, 'user_id' => $user->id],
            ['driver' => (string) config('studio.driver', 'local'), 'status' => WorkspaceStatus::New],
        );
    }
}
