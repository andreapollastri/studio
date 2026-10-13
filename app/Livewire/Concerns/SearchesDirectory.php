<?php

namespace App\Livewire\Concerns;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The searches behind the pickers of the tools pages (<x-search-picker>): the
 * projects the person works on, and the other people of this Studio.
 */
trait SearchesDirectory
{
    /** @return array<int, array{id: int, label: string, sub: string}> */
    public function searchProjects(string $query = ''): array
    {
        return auth()->user()->accessibleProjects()
            ->when(trim($query) !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', '%'.trim($query).'%')
                ->orWhere('slug', 'like', '%'.trim($query).'%')))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (Project $project) => ['id' => $project->id, 'label' => $project->name, 'sub' => $project->slug])
            ->values()
            ->all();
    }

    /** @return array<int, array{id: int, label: string, sub: string}> */
    public function searchUsers(string $query = ''): array
    {
        return User::query()
            ->whereKeyNot(auth()->id())
            ->when(trim($query) !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', '%'.trim($query).'%')
                ->orWhere('email', 'like', '%'.trim($query).'%')
                ->orWhere('handle', 'like', '%'.trim($query).'%')))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (User $user) => ['id' => $user->id, 'label' => $user->name, 'sub' => $user->email.' · '.$user->role->label()])
            ->values()
            ->all();
    }
}
