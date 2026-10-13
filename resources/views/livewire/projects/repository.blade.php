<div class="h-full overflow-y-auto">
    <x-slot:header>
        <div class="flex min-w-0 items-center gap-3">
            <a href="{{ route('studio.show', $project) }}" class="truncate text-sm font-medium" wire:navigate>{{ $project->name }}</a>
            <x-studio.project-tabs :project="$project" current="repository" />
        </div>
    </x-slot:header>

    <div class="mx-auto max-w-6xl p-6 max-md:p-4">
        @include('livewire.infrastructure.console')
    </div>
</div>
