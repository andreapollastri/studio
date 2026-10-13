<div class="h-full overflow-y-auto">
    <x-slot:header>
        <x-studio.project-title :project="$project" />
    </x-slot:header>

    <x-slot:subnav>
        <x-studio.project-tabs :project="$project" current="repository" />
    </x-slot:subnav>

    <div class="mx-auto max-w-6xl p-6 max-md:p-4">
        @include('livewire.infrastructure.console')
    </div>
</div>
