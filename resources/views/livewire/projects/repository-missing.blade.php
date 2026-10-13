<div class="h-full overflow-y-auto">
    <x-slot:header>
        <div class="flex min-w-0 items-center gap-3">
            <a href="{{ route('studio.show', $project) }}" class="truncate text-sm font-medium" wire:navigate>{{ $project->name }}</a>
            <x-studio.project-tabs :project="$project" current="repository" />
        </div>
    </x-slot:header>

    <div class="mx-auto flex max-w-xl flex-col items-center gap-4 p-10 text-center">
        <flux:icon.code-bracket-square class="size-8 text-zinc-400" />
        @if ($problem === 'account')
            <flux:heading size="lg">{{ __('Add your account on :host', ['host' => parse_url($project->repo_url, PHP_URL_HOST) ?: $project->repo_url]) }}</flux:heading>
            <flux:text>{{ __('The repository opens here with your own account on its host: the pull requests, issues, branches, pipelines and releases, and what you may do with them there. The same account clones and commits in your workspace.') }}</flux:text>
            <flux:button variant="primary" icon="plus" :href="route('connections.edit', ['tab' => 'git'])" wire:navigate>{{ __('Add a git account') }}</flux:button>
        @elseif ($problem === 'repository')
            <flux:heading size="lg">{{ __('Studio does not know this repository\'s host') }}</flux:heading>
            <flux:text>{{ __('The address :url is not on a git host Studio reads. An administrator can correct it in the project\'s Settings.', ['url' => $project->repo_url]) }}</flux:text>
        @else
            <flux:heading size="lg">{{ __('The repository could not be read') }}</flux:heading>
            <flux:text>{{ $reason }}</flux:text>
            <flux:text class="text-xs">{{ __('Check that your token reaches this repository, in Settings → Accounts.') }}</flux:text>
            <flux:button icon="arrow-path" :href="route('projects.repository', $project)" wire:navigate>{{ __('Try again') }}</flux:button>
        @endif
    </div>
</div>
