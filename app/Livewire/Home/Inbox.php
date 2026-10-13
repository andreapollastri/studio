<?php

namespace App\Livewire\Home;

use App\Enums\SiteStatus;
use App\Enums\WorkspaceStatus;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\ProjectPreference;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * What waits for the person now, across every project: conversations that ask
 * them something, failed or finished since they last looked; the ones working;
 * workspaces and sites that failed; what waits for them on each git account
 * (loaded apart, each from its host); and their projects, most recent first.
 * New people get the three steps that make Studio work for them.
 */
#[Layout('layouts::app')]
#[Title('Home')]
class Inbox extends Component
{
    public function render(): View
    {
        $user = auth()->user();
        $projectIds = $user->accessibleProjects()->pluck('id');

        $conversations = Conversation::query()
            ->with('workspace.project')
            ->where('user_id', $user->id)
            ->whereHas('workspace', fn ($q) => $q->whereIn('project_id', $projectIds))
            ->latest('last_activity_at')
            ->limit(60)
            ->get();

        $byNeed = $conversations->groupBy(fn (Conversation $c) => $c->signal());

        $workspaces = $user->workspaces()->with('project')->whereIn('project_id', $projectIds)->get();
        $failedSites = Project::query()->whereIn('id', $projectIds)->where('site_status', SiteStatus::Failed->value)->orderBy('name')->get();

        // most recently worked on first, then the others by name
        $recent = $workspaces->sortByDesc(fn (Workspace $w) => $w->last_seen_at->timestamp ?? 0)->pluck('project_id')->all();
        $projects = $user->accessibleProjects()->orderBy('name')->get()
            ->sortBy(fn (Project $p) => ($i = array_search($p->id, $recent, true)) === false ? 1000 : $i)->values();
        $groups = ProjectPreference::query()->with('group')->where('user_id', $user->id)->get()->keyBy('project_id');

        return view('livewire.home.inbox', [
            'user' => $user,
            'asking' => $byNeed->get('asking', collect())->merge($byNeed->get('failed', collect())),
            'done' => $byNeed->get('done', collect()),
            'working' => $byNeed->get('working', collect()),
            'failedWorkspaces' => $workspaces->filter(fn (Workspace $w) => $w->status === WorkspaceStatus::Failed)->values(),
            'failedSites' => $failedSites,
            'gitAccounts' => $user->gitAccounts()->orderBy('connector')->orderBy('name')->get(),
            'projects' => $projects->take(9),
            'projectCount' => $projects->count(),
            'groups' => $groups,
            'setup' => array_filter([
                'git' => ! $user->hasGit(),
                'claude' => $user->canChat() && ! $user->hasClaude(),
                'projects' => $projects->isEmpty(),
            ]),
        ]);
    }
}
