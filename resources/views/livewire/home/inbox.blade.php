<div class="flex h-full w-full flex-1 flex-col gap-8 max-sm:gap-6" @if ($working->isNotEmpty() || $asking->isNotEmpty()) wire:poll.10s.visible @else wire:poll.60s.visible @endif>
    <div>
        <flux:heading size="xl">{{ __('Hi :name', ['name' => \Illuminate\Support\Str::before($user->name, ' ')]) }}</flux:heading>
        <flux:text class="mt-1">
            @if ($asking->isEmpty() && $failedWorkspaces->isEmpty() && $failedSites->isEmpty())
                {{ __('Nothing is waiting for you in Studio right now.') }}
            @else
                {{ __('What waits for you, across every project.') }}
            @endif
        </flux:text>
    </div>

    {{-- First steps --}}
    @if ($setup !== [])
        <section class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Get started') }}</flux:heading>
            <ol class="mt-3 grid gap-3 md:grid-cols-3">
                @foreach ([
                    'git' => ['code-bracket-square', __('Add your git account'), __('Personal, the company\'s, a client\'s: on any host. Your workspaces commit with it.'), route('connections.edit', ['tab' => 'git'])],
                    'claude' => ['sparkles', __('Connect Claude'), __('Your Pro, Max or Team plan, or an API key: the chat runs on it.'), route('connections.edit')],
                    'projects' => ['folder', __('Get on a project'), $user->isAdmin() ? __('Create the first project and put the team on it.') : __('An administrator puts you on the projects you work on.'), $user->isAdmin() ? route('admin.projects') : null],
                ] as $step => [$icon, $label, $text, $href])
                    @php($todo = isset($setup[$step]))
                    <li @class(['flex gap-3 rounded-lg p-3', 'bg-zinc-50 dark:bg-zinc-800/60' => $todo, 'opacity-60' => ! $todo])>
                        <flux:icon :name="$todo ? $icon : 'check-circle'" @class(['mt-0.5 size-5 shrink-0', 'text-zinc-400' => $todo, 'text-green-600' => ! $todo]) />
                        <div class="min-w-0">
                            @if ($todo && $href)
                                <a href="{{ $href }}" wire:navigate class="font-medium hover:underline">{{ $label }}</a>
                            @else
                                <span class="font-medium">{{ $label }}</span>
                            @endif
                            <flux:text class="text-xs">{{ $text }}</flux:text>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    <div class="grid gap-8 lg:grid-cols-3">
        {{-- Conversations --}}
        <div class="space-y-8 lg:col-span-2">
            @foreach ([
                ['list' => $asking, 'title' => __('Waiting for you'), 'empty' => __('No conversation asks you anything.'), 'always' => true],
                ['list' => $done, 'title' => __('Finished, not opened yet'), 'empty' => null, 'always' => false],
                ['list' => $working, 'title' => __('Working now'), 'empty' => null, 'always' => false],
            ] as $block)
                @if ($block['always'] || $block['list']->isNotEmpty())
                    <section class="space-y-2">
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">{{ $block['title'] }}</flux:heading>
                            @if ($block['list']->isNotEmpty())<flux:badge size="sm">{{ $block['list']->count() }}</flux:badge>@endif
                        </div>
                        @if ($block['list']->isEmpty())
                            <flux:text class="text-sm">{{ $block['empty'] }}</flux:text>
                        @else
                            <div class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                                @foreach ($block['list'] as $conversation)
                                    @php($project = $conversation->workspace->project)
                                    <a href="{{ route('studio.conversation', ['project' => $project, 'conversation' => $conversation]) }}" wire:navigate class="flex items-center gap-3 px-4 py-2.5 hover:bg-zinc-50 dark:hover:bg-zinc-800/60" wire:key="conversation-{{ $conversation->uuid }}">
                                        <x-studio.conversation-dot :conversation="$conversation" />
                                        <div class="min-w-0 flex-1">
                                            <div class="truncate text-sm font-medium">{{ $conversation->title ?: __('New conversation') }}</div>
                                            <div class="truncate text-xs text-zinc-500">{{ $project->name }} · {{ $conversation->signalLabel() }}@if ($conversation->sandbox) · {{ __('sandbox') }}@endif</div>
                                        </div>
                                        <span class="shrink-0 text-xs text-zinc-500">{{ $conversation->last_activity_at?->diffForHumans(short: true) }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </section>
                @endif
            @endforeach
        </div>

        <div class="space-y-8">
            {{-- What failed --}}
            @if ($failedWorkspaces->isNotEmpty() || $failedSites->isNotEmpty())
                <section class="space-y-2">
                    <flux:heading size="lg">{{ __('Needs attention') }}</flux:heading>
                    <div class="space-y-2">
                        @foreach ($failedWorkspaces as $workspace)
                            <a href="{{ route('studio.show', $workspace->project) }}" wire:navigate class="block rounded-lg border border-red-200 bg-red-50 px-3 py-2 hover:border-red-300 dark:border-red-900 dark:bg-red-950/40" wire:key="ws-{{ $workspace->uuid }}">
                                <div class="text-sm font-medium text-red-800 dark:text-red-300">{{ __('Your workspace of :project failed', ['project' => $workspace->project->name]) }}</div>
                                @if ($workspace->last_error)<div class="mt-0.5 line-clamp-2 text-xs text-red-700 dark:text-red-400">{{ $workspace->last_error }}</div>@endif
                            </a>
                        @endforeach
                        @foreach ($failedSites as $project)
                            <a href="{{ $user->isAdmin() ? route('admin.projects') : route('studio.show', $project) }}" wire:navigate class="block rounded-lg border border-red-200 bg-red-50 px-3 py-2 hover:border-red-300 dark:border-red-900 dark:bg-red-950/40" wire:key="site-{{ $project->uuid }}">
                                <div class="text-sm font-medium text-red-800 dark:text-red-300">{{ __('The site of :project failed', ['project' => $project->name]) }}</div>
                                @if ($project->last_error)<div class="mt-0.5 line-clamp-2 text-xs text-red-700 dark:text-red-400">{{ $project->last_error }}</div>@endif
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- Projects --}}
            <section class="space-y-2">
                <div class="flex items-center justify-between gap-2">
                    <flux:heading size="lg">{{ __('Projects') }}</flux:heading>
                    @if ($projectCount > 0)
                        <flux:link :href="route('projects.index')" wire:navigate class="text-sm">{{ trans_choice('All :count|All :count', $projectCount) }}</flux:link>
                    @endif
                </div>
                @if ($projects->isEmpty())
                    <flux:text class="text-sm">{{ __('None yet.') }}</flux:text>
                @else
                    <div class="space-y-1">
                        @foreach ($projects as $project)
                            @php($group = $groups->get($project->id)?->group)
                            <a href="{{ route('studio.show', $project) }}" wire:navigate class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-800/60" wire:key="project-{{ $project->uuid }}">
                                <flux:icon.folder variant="micro" class="size-4 shrink-0 text-zinc-400" />
                                <span class="min-w-0 flex-1 truncate text-sm">{{ $project->name }}
                                    @if ($projects->where('name', $project->name)->count() > 1)
                                        <span class="font-mono text-xs text-zinc-500">{{ $project->slug }}</span>
                                    @endif
                                </span>
                                @if ($group)<flux:badge size="sm">{{ $group->name }}</flux:badge>@endif
                                @if (! $user->isAdmin())
                                    <span class="text-xs text-zinc-500">{{ $user->roleIn($project)?->label() }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    </div>

    {{-- Git hosts --}}
    @if ($gitAccounts->isNotEmpty())
        <section class="space-y-3">
            <div class="flex items-center justify-between gap-2">
                <flux:heading size="lg">{{ __('On your git hosts') }}</flux:heading>
                <flux:link :href="route('git')" wire:navigate class="text-sm">{{ __('Open Git') }}</flux:link>
            </div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($gitAccounts as $account)
                    <livewire:home.git-inbox :account="$account->uuid" :key="'git-inbox-'.$account->uuid" />
                @endforeach
            </div>
        </section>
    @endif
</div>
