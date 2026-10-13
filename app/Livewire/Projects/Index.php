<?php

namespace App\Livewire\Projects;

use App\Accounts\Accounts;
use App\Jobs\SyncWorkspaceCredentials;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\ProjectPreference;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The person's projects, in groups of their own (Personal, Open source, Work,
 * Clients…). A group sets the Claude account and the git account its
 * projects use; a project may pick others. Each card says which accounts it
 * runs on and why, so nobody commits to a client's repository as themselves
 * by accident.
 */
#[Layout('layouts::app')]
#[Title('Projects')]
class Index extends Component
{
    #[Url(except: '')]
    public string $search = '';

    // ---- The group in the dialog: null uuid for a new one

    public ?string $groupUuid = null;

    public string $group_name = '';

    public string $group_color = 'zinc';

    /** uuid of a Claude account, '' for the person's default */
    public string $group_claude = '';

    /** uuid of a git account, '' for the account on each repository's host */
    public string $group_git = '';

    // ---- What one project runs on

    public ?string $accountsProject = null;

    /** uuid of a Claude account, '' for the group's or the default */
    public string $project_claude = '';

    /** uuid of a git account, '' for the group's or the one on the repository's host */
    public string $project_git = '';

    public function newGroup(): void
    {
        $this->reset('groupUuid', 'group_name', 'group_claude', 'group_git');
        $this->group_color = ProjectGroup::COLORS[auth()->user()->projectGroups()->count() % count(ProjectGroup::COLORS)];
        $this->resetValidation();

        Flux::modal('project-group')->show();
    }

    public function editGroup(string $uuid): void
    {
        $group = $this->group($uuid);

        $this->groupUuid = $group->uuid;
        $this->group_name = $group->name;
        $this->group_color = $group->color;
        $this->group_claude = (string) $group->claudeAccount?->uuid;
        $this->group_git = (string) $group->gitAccount?->uuid;
        $this->resetValidation();

        Flux::modal('project-group')->show();
    }

    public function saveGroup(): void
    {
        $user = auth()->user();
        $group = $this->groupUuid ? $this->group($this->groupUuid) : null;

        $this->validate([
            'group_name' => ['required', 'string', 'max:60', Rule::unique('project_groups', 'name')->where('user_id', $user->id)->ignore($group?->id)],
            'group_color' => ['required', Rule::in(ProjectGroup::COLORS)],
            'group_claude' => ['nullable', Rule::in(['', ...$user->claudeAccounts()->pluck('uuid')->all()])],
            'group_git' => ['nullable', Rule::in(['', ...$user->gitAccounts()->pluck('uuid')->all()])],
        ]);

        $values = [
            'name' => trim($this->group_name),
            'color' => $this->group_color,
            'claude_account_id' => $this->group_claude !== '' ? $user->claudeAccounts()->where('uuid', $this->group_claude)->value('id') : null,
            'git_account_id' => $this->group_git !== '' ? $user->gitAccounts()->where('uuid', $this->group_git)->value('id') : null,
        ];

        if ($group) {
            $group->update($values);
            $this->syncGit($this->projectIds($group));
        } else {
            $user->projectGroups()->create([...$values, 'position' => (int) $user->projectGroups()->max('position') + 1]);
        }

        Flux::modal('project-group')->close();
        Flux::toast(variant: 'success', text: $group ? __('Group saved.') : __('Group :group created: move projects into it from their menu.', ['group' => $values['name']]));
    }

    /** The projects of a deleted group go back to the others, with their own choices. */
    public function deleteGroup(string $uuid): void
    {
        $group = $this->group($uuid);
        $projects = $this->projectIds($group);
        $group->delete();
        $this->syncGit($projects);

        Flux::toast(text: __('Group :group deleted; its projects are in Other projects.', ['group' => $group->name]));
    }

    public function moveGroup(string $uuid, int $step): void
    {
        $groups = auth()->user()->projectGroups()->get()->values();
        $index = $groups->search(fn (ProjectGroup $g) => $g->uuid === $uuid);

        if ($index === false || ! $groups->has($index + $step)) {
            return;
        }

        $order = $groups->all();
        [$order[$index], $order[$index + $step]] = [$order[$index + $step], $order[$index]];

        foreach (array_values($order) as $position => $group) {
            $group->forceFill(['position' => $position + 1])->save();
        }
    }

    /** Put a project in a group, or none (''): it takes that group's accounts unless it has its own. */
    public function moveProject(string $project, string $group = ''): void
    {
        $user = auth()->user();
        $project = $this->project($project);
        $target = $group !== '' ? $this->group($group) : null;

        Accounts::choose($user, $project, ['project_group_id' => $target?->id]);
        $this->syncGit([$project->id]);

        Flux::toast(text: $target ? __(':project is in :group.', ['project' => $project->name, 'group' => $target->name]) : __(':project is in Other projects.', ['project' => $project->name]));
    }

    public function editAccounts(string $project): void
    {
        $project = $this->project($project);
        $preference = Accounts::preference(auth()->user(), $project);

        $this->accountsProject = $project->uuid;
        $this->project_claude = (string) $preference?->claudeAccount?->uuid;
        $this->project_git = (string) $preference?->gitAccount?->uuid;
        $this->resetValidation();

        Flux::modal('project-accounts')->show();
    }

    public function saveAccounts(): void
    {
        $user = auth()->user();
        $project = $this->project((string) $this->accountsProject);

        $this->validate([
            'project_claude' => ['nullable', Rule::in(['', ...$user->claudeAccounts()->pluck('uuid')->all()])],
            // only an account on the host of the repository can push to it
            'project_git' => ['nullable', Rule::in(['', ...Accounts::gitAccountsFor($user, $project)->pluck('uuid')->all()])],
        ]);

        Accounts::choose($user, $project, [
            'claude_account_id' => $this->project_claude !== '' ? $user->claudeAccounts()->where('uuid', $this->project_claude)->value('id') : null,
            'git_account_id' => $this->project_git !== '' ? $user->gitAccounts()->where('uuid', $this->project_git)->value('id') : null,
        ]);
        $restarts = $this->syncGit([$project->id]);

        Flux::modal('project-accounts')->close();
        Flux::toast(variant: 'success', text: $restarts
            ? __('Saved. Your workspace of :project restarts with the new git account: a running conversation picks up where it was.', ['project' => $project->name])
            : __('Saved.'));
    }

    /**
     * Give the workspaces of these projects the git account that is theirs now; only those whose
     * account moved restart. Whether any will, for the page to say so.
     *
     * @param  list<int>  $projectIds
     */
    private function syncGit(array $projectIds): bool
    {
        $user = auth()->user();

        $moves = $user->workspaces()->with('project')->where('driver', 'native')->whereNotNull('path')->whereIn('project_id', $projectIds)->get()
            ->contains(fn ($workspace) => Accounts::git($user, $workspace->project)?->account->id !== $workspace->git_account_id);

        if ($moves) {
            SyncWorkspaceCredentials::dispatch($user, false, $projectIds);
        }

        return $moves;
    }

    /** @return list<int> */
    private function projectIds(ProjectGroup $group): array
    {
        return array_values($group->preferences()->get()->map(fn (ProjectPreference $preference) => $preference->project_id)->all());
    }

    private function group(string $uuid): ProjectGroup
    {
        return auth()->user()->projectGroups()->where('uuid', $uuid)->firstOrFail();
    }

    private function project(string $uuid): Project
    {
        return auth()->user()->accessibleProjects()->where('uuid', $uuid)->firstOrFail();
    }

    public function render(): View
    {
        $user = auth()->user();
        $projects = $user->accessibleProjects()->withCount('members')->orderBy('name')
            ->when($this->search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')->orWhere('repo_url', 'like', '%'.$this->search.'%')))
            ->get();
        $preferences = ProjectPreference::query()->with(['group.claudeAccount', 'group.gitAccount', 'claudeAccount', 'gitAccount'])
            ->where('user_id', $user->id)->get()->keyBy('project_id');
        $groups = $user->projectGroups()->with(['claudeAccount', 'gitAccount'])->get();

        // what each project runs on, and why
        $runs = $projects->mapWithKeys(fn (Project $project) => [$project->id => [
            'claude' => Accounts::claude($user, $project, $preferences->get($project->id)),
            'git' => Accounts::git($user, $project, $preferences->get($project->id)),
        ]]);

        /** @var Collection<int|string, Collection<int, Project>> $byGroup */
        $byGroup = $projects->groupBy(fn (Project $project) => $preferences->get($project->id)->project_group_id ?? 0);
        $accountsProject = $this->accountsProject ? $projects->firstWhere('uuid', $this->accountsProject) ?? $this->project($this->accountsProject) : null;

        return view('livewire.projects.index', [
            'projects' => $projects,
            'groups' => $groups,
            'byGroup' => $byGroup,
            'runs' => $runs,
            'workspaces' => $user->workspaces()->get()->keyBy('project_id'),
            'claudeAccounts' => $user->claudeAccounts()->orderBy('id')->get(),
            'gitAccounts' => $user->gitAccounts()->orderBy('name')->get(),
            'accountsFor' => $accountsProject,
            'accountsGitOptions' => $accountsProject ? Accounts::gitAccountsFor($user, $accountsProject) : collect(),
            'accountsInherited' => $accountsProject ? $this->inherited($accountsProject, $preferences->get($accountsProject->id)) : null,
        ]);
    }

    /**
     * What the project would run on without a choice of its own: for the "automatic" options.
     *
     * @return array{claude: string, git: string}
     */
    private function inherited(Project $project, ?ProjectPreference $preference): array
    {
        $user = auth()->user();
        $bare = $preference ? (clone $preference)->setRelation('claudeAccount', null)->setRelation('gitAccount', null) : null;
        $claude = Accounts::claude($user, $project, $bare ?? new ProjectPreference);
        $git = Accounts::git($user, $project, $bare ?? new ProjectPreference);

        return [
            'claude' => $claude ? $claude->account->name.' — '.$claude->reason() : __('none yet'),
            'git' => $git ? $git->account->name.' — '.$git->reason() : __('no account on this host'),
        ];
    }
}
