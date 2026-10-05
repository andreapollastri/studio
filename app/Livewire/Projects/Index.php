<?php

namespace App\Livewire\Projects;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('Projects')]
class Index extends Component
{
    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.projects.index', [
            'projects' => $user->accessibleProjects()->withCount('members')->orderBy('name')->get(),
            'workspaces' => $user->workspaces()->get()->keyBy('project_id'),
        ]);
    }
}
