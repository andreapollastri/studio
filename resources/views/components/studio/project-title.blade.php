@props(['project'])

{{-- The project in the header of its pages: its name, its key when another project has the same name, and what the page adds (the workspace's status). --}}
<div class="flex min-w-0 items-center gap-2 max-md:gap-1.5">
    <a href="{{ route('studio.show', $project) }}" class="min-w-0 truncate text-sm font-medium" wire:navigate>{{ $project->name }}</a>
    @if (\App\Models\Project::query()->where('name', $project->name)->whereKeyNot($project->id)->exists())
        <span class="shrink-0 font-mono text-xs text-zinc-500 max-sm:hidden">{{ $project->slug }}</span>
    @endif
    {{ $slot }}
</div>
