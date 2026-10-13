<?php

namespace App\Livewire\Projects;

use App\Drive\Access as DriveAccess;
use App\Models\DriveFolder;
use App\Models\Project;
use App\Models\SshConnection;
use App\Models\VaultItem;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * What a project opens to its members, in one place: the passwords tied to it,
 * its SSH connections and its folders in the drive, each as far as the person's
 * role on the project lets them see. Each opens in its own tool.
 */
#[Layout('layouts::studio')]
class Access extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        abort_unless(auth()->user()->canAccess($project), 403);

        $this->project = $project;
    }

    public function render(): View
    {
        $user = auth()->user();
        $drive = new DriveAccess($user);

        return view('livewire.projects.access', [
            'title' => __(':project · Access', ['project' => $this->project->name]),
            'passwords' => VaultItem::query()->visibleTo($user)->ofProject($this->project->id)->orderBy('title')->get(),
            'connections' => $user->canUseSsh()
                ? SshConnection::query()->visibleTo($user)->where('project_id', $this->project->id)->orderBy('name')->get()
                : collect(),
            'folders' => DriveFolder::query()->where('project_id', $this->project->id)->orderBy('name')->get()
                ->filter(fn (DriveFolder $folder) => $drive->canRead($folder->id))->values(),
            'drive' => $drive,
        ]);
    }
}
