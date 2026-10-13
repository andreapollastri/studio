{{-- The project at a glance: every resource attached to it, its backups, what its chats get. --}}
<div class="space-y-6">
    <div>
        <flux:heading size="lg">{{ __('Overview') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Everything :project runs on, read now with the team\'s accounts: open one for its details and actions.', ['project' => $project->name]) }}</flux:text>
    </div>

    @if ($resources->isNotEmpty())
        <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-3">
            @foreach ($resources as $item)
                <livewire:projects.resource-card :resource="$item->uuid" :key="'card-'.$item->uuid" />
            @endforeach
        </div>
    @elseif (auth()->user()->can('manage', $project))
        <div class="rounded-xl border border-dashed border-zinc-300 p-6 dark:border-zinc-700">
            <flux:heading>{{ __('Bring the project\'s infrastructure here') }}</flux:heading>
            <flux:text class="mt-1 max-w-2xl">{{ __('Attach the Cipi app it is deployed on (releases, rollbacks, logs, .env, disk, the anonymized database and the agent\'s MCP server for the chats), its Cloudflare zone, its Boogle project, its Hackly target, its Johnny bucket, its servers.') }}</flux:text>
            <flux:button class="mt-4" size="sm" variant="primary" icon="link" wire:click="startAttach">{{ __('Attach') }}</flux:button>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- What the chats get --}}
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex items-center gap-2">
                <flux:icon name="cpu-chip" class="size-5 text-zinc-400" />
                <flux:heading size="sm">{{ __('In your chats') }}</flux:heading>
            </div>
            @if ($mcp === [])
                <flux:text class="mt-2 text-xs">{{ __('No MCP server comes with what is attached. A Cipi app with the Cipi agent brings its own.') }}</flux:text>
            @else
                <flux:text class="mt-2 text-xs">{{ __('The agent of your chats on this project gets these MCP servers, with no setup:') }}</flux:text>
                <div class="mt-2 flex flex-wrap gap-1">
                    @foreach ($mcp as $name)<flux:badge size="sm" color="violet" icon="puzzle-piece">{{ $name }}</flux:badge>@endforeach
                </div>
            @endif
        </div>

        {{-- SSH --}}
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex items-center gap-2">
                <flux:icon name="command-line" class="size-5 text-zinc-400" />
                <flux:heading size="sm">{{ __('Remote terminal') }}</flux:heading>
            </div>
            <flux:text class="mt-2 text-xs">
                {{ $sshCount > 0
                    ? trans_choice(':count SSH connection of the project opens in the workspace\'s terminal (Remote) and on the SSH page.|:count SSH connections of the project open in the workspace\'s terminal (Remote) and on the SSH page.', $sshCount)
                    : __('No SSH connection tied to the project yet: add one on the SSH page, or from a Cipi app\'s Agent & access.') }}
            </flux:text>
            <div class="mt-3 flex flex-wrap gap-2">
                <flux:button size="xs" icon="chat-bubble-left-right" :href="route('studio.show', $project)" wire:navigate>{{ __('Workspace') }}</flux:button>
                @can('ssh')<flux:button size="xs" variant="ghost" icon="command-line" :href="route('ssh')" wire:navigate>{{ __('SSH page') }}</flux:button>@endcan
            </div>
        </div>

        {{-- Backups, for the administrators --}}
        @if (auth()->user()->isAdmin())
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <flux:icon name="archive-box-arrow-down" class="size-5 text-zinc-400" />
                        <flux:heading size="sm">{{ __('Backups') }}</flux:heading>
                    </div>
                    <flux:button size="xs" variant="ghost" icon:trailing="arrow-right" :href="route('projects.backups', $project)" wire:navigate>{{ __('Open') }}</flux:button>
                </div>
                @forelse ($backups as $job)
                    <div class="mt-2 flex items-center gap-2 text-xs">
                        <flux:badge size="sm" :color="\App\Models\BackupRun::statusColor($job->latestRun->status ?? 'queued')">{{ $job->latestRun ? __($job->latestRun->status) : __('never') }}</flux:badge>
                        <span class="min-w-0 flex-1 truncate">{{ $job->name }}</span>
                        <span class="shrink-0 text-zinc-500">{{ $job->nextRunAt()?->diffForHumans() ?? '' }}</span>
                    </div>
                @empty
                    <flux:text class="mt-2 text-xs">{{ __('No backup yet: the site\'s folder over SSH, its bucket, copied to S3 on a schedule, with so many daily, weekly and monthly snapshots kept.') }}</flux:text>
                @endforelse
            </div>
        @endif
    </div>
</div>
