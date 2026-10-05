<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Projects') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Every project opens your workspace: a chat with Claude, the preview of your branch, the backlog.') }}</flux:text>
        </div>
        @if (auth()->user()->isAdmin())
            <flux:button :href="route('admin.projects')" icon="plus" variant="primary" wire:navigate>{{ __('Manage projects') }}</flux:button>
        @endif
    </div>

    @if ($projects->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
            <flux:heading>{{ __('No projects yet') }}</flux:heading>
            <flux:text class="mt-2">
                @if (auth()->user()->isAdmin())
                    {{ __('Create the first project from Administration and add the members.') }}
                @else
                    {{ __('Ask an administrator to add you to a project.') }}
                @endif
            </flux:text>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($projects as $project)
                @php($workspace = $workspaces->get($project->id))
                <a href="{{ route('studio.show', $project) }}" wire:navigate class="group flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-5 transition hover:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-500">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <flux:heading class="truncate">{{ $project->name }}</flux:heading>
                            <flux:text class="truncate text-xs">{{ parse_url($project->repo_url, PHP_URL_HOST) ?: $project->repo_url }} · {{ $project->default_branch }}</flux:text>
                        </div>
                        @if ($workspace)
                            <flux:badge size="sm" :color="$workspace->isRunning() ? 'green' : ($workspace->status->isTransitional() ? 'yellow' : 'zinc')">{{ $workspace->status->label() }}</flux:badge>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                        <span>PHP {{ $project->php_version }}</span>
                        <span>·</span>
                        <span>{{ $project->db_engine }}</span>
                        <span>·</span>
                        <span>{{ trans_choice(':count member|:count members', $project->members_count) }}</span>
                        <span>·</span>
                        <span class="font-mono">{{ $project->siteHost() }}</span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
