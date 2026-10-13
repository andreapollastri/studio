<div class="flex h-full w-full min-h-0 flex-col">
    <div class="flex items-center gap-0.5 overflow-x-auto border-b border-zinc-200 px-2 py-2 dark:border-zinc-700">
        @foreach (array_filter(['preview' => __('Preview'), 'changes' => $hasWorkspace ? __('Changes') : null, 'terminal' => $canUseTerminal ? __('Terminal') : null, 'files' => $canUseTerminal ? __('Files') : null]) as $key => $label)
            <button
                type="button"
                wire:click="setTab('{{ $key }}')"
                @class([
                    'shrink-0 rounded-md px-2.5 py-1 text-sm transition',
                    'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' => $tab === $key,
                    'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => $tab !== $key,
                ])
            >{{ $label }}</button>
        @endforeach
        <flux:spacer />
        @if ($tab === 'preview' && ($workspace->isRunning() ? $workspace->app_url : $previewUrl))
            <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square" :href="$workspace->isRunning() ? $workspace->app_url : $previewUrl" target="_blank">{{ $workspace->isRunning() ? __('Open') : __('Open the site') }}</flux:button>
        @elseif ($tab === 'changes')
            <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="setChangesView(@js($changesView))">{{ __('Refresh') }}</flux:button>
        @elseif ($tab === 'terminal' && $run && ($run['status'] ?? '') === 'running')
            <flux:button size="xs" variant="ghost" icon="stop" wire:click="killRun">{{ __('Stop') }}</flux:button>
        @endif
        <div class="shrink-0" x-data="{ wide: false }" x-on:studio-pane-wide-changed.window="wide = $event.detail.wide">
            <flux:button size="xs" variant="ghost" icon="arrows-pointing-out" x-show="! wide" x-on:click="$dispatch('studio-pane-toggle')" :aria-label="__('Full screen')" :title="__('Full screen')" />
            <flux:button size="xs" variant="ghost" icon="arrows-pointing-in" x-show="wide" x-cloak x-on:click="$dispatch('studio-pane-toggle')" :aria-label="__('Exit full screen')" :title="__('Exit full screen')" />
        </div>
    </div>

    <div @class(['relative min-h-0 flex-1', 'overflow-hidden' => $tab === 'files', 'overflow-auto' => $tab !== 'files'])>
        @if ($filesMounted)
            {{-- Mounted on first use and kept, hidden, so open editor tabs survive switching panes. --}}
            <div @class(['absolute inset-0', 'hidden' => $tab !== 'files'])>
                <livewire:studio.files :workspace="$workspace" :key="'files-'.$workspace->id" />
            </div>
        @endif
        @if ($tab === 'preview')
            @if ($workspace->isRunning() && $workspace->app_url)
                <iframe src="{{ $workspace->app_url }}" title="{{ __('Workspace preview') }}" class="h-full w-full bg-white" referrerpolicy="no-referrer" sandbox="allow-same-origin allow-scripts allow-forms allow-popups"></iframe>
            @elseif ($previewUrl)
                <iframe src="{{ $previewUrl }}" title="{{ __('Project site') }}" class="h-full w-full bg-white" referrerpolicy="no-referrer" sandbox="allow-same-origin allow-scripts allow-forms allow-popups"></iframe>
            @else
                <div class="p-6 text-sm text-zinc-500">
                    {{ $workspace->isRunning() ? __('This workspace does not expose a preview URL.') : __('The preview appears when the workspace is running.') }}
                </div>
            @endif
        @elseif ($tab === 'changes')
            <div class="flex h-full min-h-0 flex-col text-sm">
                <div class="flex flex-wrap items-center gap-1 border-b border-zinc-200 px-3 py-1.5 dark:border-zinc-700">
                    @foreach (['tree' => __('Working tree'), 'history' => __('History'), 'branches' => __('Branches')] as $key => $label)
                        <button type="button" wire:click="setChangesView('{{ $key }}')" @class(['rounded px-2 py-0.5 text-xs', 'bg-zinc-200 font-medium dark:bg-zinc-700' => $changesView === $key, 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => $changesView !== $key])>{{ $label }}</button>
                    @endforeach
                    @if ($git && $git['branch'])
                        <span class="ml-auto flex items-center gap-1.5">
                            <span class="font-mono text-xs text-zinc-500" title="{{ $git['upstream'] ? '→ '.$git['upstream'] : __('Not pushed yet') }}">{{ $git['branch'] }}</span>
                            @if ($git['behind'] > 0)<span class="text-xs text-amber-600" title="{{ __('Commits to pull') }}">↓{{ $git['behind'] }}</span>@endif
                            @if ($git['ahead'] > 0)<span class="text-xs text-blue-600" title="{{ __('Commits to push') }}">↑{{ $git['ahead'] }}</span>@endif
                            @if ($canUseTerminal)
                                <flux:button size="xs" variant="ghost" icon="arrow-down" wire:click="pullRemote" wire:loading.attr="disabled" wire:target="pullRemote,pushRemote" :title="__('Pull: bring in the commits of the remote branch')">{{ __('Pull') }}</flux:button>
                                <flux:button size="xs" variant="ghost" icon="arrow-up" wire:click="pushRemote" wire:loading.attr="disabled" wire:target="pullRemote,pushRemote" :title="__('Push: send the commits of this branch to the remote')">{{ __('Push') }}</flux:button>
                            @endif
                        </span>
                    @elseif ($log && $log['branch'])
                        <span class="ml-auto font-mono text-xs text-zinc-500">{{ $log['branch'] }}</span>
                    @endif
                </div>
                <div class="min-h-0 flex-1 overflow-auto p-3">
                    @if ($gitError)
                        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-3"><flux:callout.text class="whitespace-pre-wrap">{{ $gitError }}</flux:callout.text></flux:callout>
                    @elseif ($gitNotice)
                        <flux:callout variant="success" icon="check-circle" class="mb-3"><flux:callout.text>{{ $gitNotice }}</flux:callout.text></flux:callout>
                    @endif

                    @if ($git && $git['merging'])
                        <flux:callout variant="warning" icon="arrows-right-left" class="mb-3">
                            <flux:callout.heading>{{ __('Merge in progress') }}</flux:callout.heading>
                            <flux:callout.text>
                                @if (count($git['conflicts']) > 0)
                                    {{ __('Resolve the conflicts in these files, then commit to conclude the merge:') }}
                                    <span class="mt-1 block font-mono text-xs">{{ implode(', ', $git['conflicts']) }}</span>
                                @else
                                    {{ __('No conflicts left: commit to conclude the merge.') }}
                                @endif
                            </flux:callout.text>
                            @if ($canUseTerminal)
                                <x-slot name="actions">
                                    <flux:button size="sm" wire:click="abortMerge" wire:confirm="{{ __('Abort the merge? The working tree goes back to how it was before it.') }}">{{ __('Abort merge') }}</flux:button>
                                </x-slot>
                            @endif
                        </flux:callout>
                    @endif

                    @if ($changesView === 'tree')
                        @if ($git)
                            @if ($canUseTerminal && (count($git['status']) > 0 || $git['merging']))
                                <form wire:submit="commitAll" class="mb-3 flex gap-2">
                                    <flux:input size="sm" wire:model="commitMessage" :placeholder="__('Commit message')" class="flex-1" />
                                    <flux:button size="sm" type="submit" variant="primary" icon="check">{{ __('Commit all') }}</flux:button>
                                </form>
                            @endif
                            @if ($canUseTerminal && (count($git['status']) > 0 || $git['stashes'] > 0) && ! $git['merging'])
                                <div class="mb-3 flex flex-wrap items-center gap-1">
                                    @if (count($git['status']) > 0)
                                        <flux:button size="xs" variant="ghost" icon="archive-box-arrow-down" wire:click="stash" :title="__('Put the changes aside, for example to switch branch')">{{ __('Stash') }}</flux:button>
                                    @endif
                                    @if ($git['stashes'] > 0)
                                        <flux:button size="xs" variant="ghost" icon="archive-box" wire:click="stashPop">{{ __('Restore stash') }} ({{ $git['stashes'] }})</flux:button>
                                    @endif
                                    @if (count($git['status']) > 0)
                                        <flux:button size="xs" variant="ghost" icon="trash" class="ml-auto text-red-600!" wire:click="discard" wire:confirm="{{ __('Discard every uncommitted change, new files included? This cannot be undone.') }}">{{ __('Discard all') }}</flux:button>
                                    @endif
                                </div>
                            @endif
                            @if (count($git['status']) === 0)
                                <flux:text>{{ __('No uncommitted changes.') }}</flux:text>
                            @else
                                <ul class="mb-3 space-y-0.5 font-mono text-xs">
                                    @foreach ($git['status'] as $line)
                                        <li class="group flex items-center gap-2" wire:key="status-{{ md5($line) }}">
                                            <span class="min-w-0 flex-1 truncate whitespace-pre">{{ $line }}</span>
                                            @if ($canUseTerminal && ! $git['merging'])
                                                <button type="button" class="shrink-0 rounded px-1 text-zinc-400 opacity-0 hover:bg-zinc-100 hover:text-red-600 group-hover:opacity-100 focus:opacity-100 dark:hover:bg-zinc-800" wire:click="discard(@js(substr($line, 3)))" wire:confirm="{{ __('Discard the changes to :path? This cannot be undone.', ['path' => substr($line, 3)]) }}" title="{{ __('Discard') }}">✕</button>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                                <pre class="mb-3 whitespace-pre-wrap font-mono text-xs text-zinc-600 dark:text-zinc-300">{{ $git['stat'] }}</pre>
                                <pre class="max-h-[60vh] overflow-auto rounded bg-zinc-50 p-3 font-mono text-[11px] leading-snug dark:bg-zinc-800">{{ $git['diff'] }}</pre>
                            @endif
                        @elseif (! $gitError)
                            <flux:text>{{ __('Loading…') }}</flux:text>
                        @endif

                    @elseif ($changesView === 'history')
                        @if ($commit)
                            <div class="mb-4 rounded-lg border border-zinc-200 dark:border-zinc-700">
                                <div class="flex items-start gap-3 border-b border-zinc-200 px-3 py-2 dark:border-zinc-700">
                                    <div class="min-w-0 flex-1">
                                        <div class="font-medium">{{ $commit['subject'] ?? '' }}</div>
                                        <div class="mt-0.5 text-xs text-zinc-500">
                                            <span class="font-mono">{{ $commit['short'] ?? '' }}</span>
                                            · {{ $commit['author'] ?? '' }}
                                            @if (! empty($commit['date'])) · {{ \Illuminate\Support\Carbon::parse($commit['date'])->toDayDateTimeString() }} @endif
                                            @foreach ((array) ($commit['refs'] ?? []) as $ref) <flux:badge size="sm">{{ $ref }}</flux:badge> @endforeach
                                        </div>
                                    </div>
                                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="closeCommit" />
                                </div>
                                @if ($canUseTerminal)
                                    <div class="flex flex-wrap items-center gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-700">
                                        <form wire:submit="createBranch(@js((string) ($commit['sha'] ?? '')))" class="flex min-w-0 flex-1 gap-2">
                                            <flux:input size="sm" wire:model="newBranch" :placeholder="__('New branch from here')" class="min-w-0 flex-1" />
                                            <flux:button size="sm" type="submit" icon="plus">{{ __('Create') }}</flux:button>
                                        </form>
                                        <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="revert(@js((string) ($commit['sha'] ?? '')))" wire:confirm="{{ __('Revert this commit? A new commit that undoes it is added on :branch.', ['branch' => $git['branch'] ?? 'HEAD']) }}">{{ __('Revert') }}</flux:button>
                                    </div>
                                @endif
                                <pre class="whitespace-pre-wrap px-3 py-2 font-mono text-xs text-zinc-600 dark:text-zinc-300">{{ $commit['stat'] ?? '' }}</pre>
                                <pre class="max-h-[40vh] overflow-auto border-t border-zinc-200 bg-zinc-50 p-3 font-mono text-[11px] leading-snug dark:border-zinc-700 dark:bg-zinc-800">{{ $commit['patch'] ?? '' }}</pre>
                            </div>
                        @endif
                        @if ($log && count($log['commits']) > 0)
                            @php
                                $graph = \App\Git\Graph::lanes(array_map(fn (array $c) => ['sha' => (string) ($c['sha'] ?? ''), 'parents' => array_values(array_map('strval', (array) ($c['parents'] ?? [])))], $log['commits']));
                                $gutter = $graph['lanes'] * 14 + 6;
                            @endphp
                            <div class="relative">
                                <svg class="pointer-events-none absolute left-0 top-0" width="{{ $gutter }}" height="{{ count($log['commits']) * 28 }}" aria-hidden="true">
                                    @foreach ($graph['rows'] as $i => $row)
                                        @foreach ($row['edges'] as $edge)
                                            @php
                                                $x1 = $edge['from'] * 14 + 9; $x2 = $edge['to'] * 14 + 9; $y1 = $i * 28 + 14; $y2 = $y1 + 28;
                                            @endphp
                                            @if ($x1 === $x2)
                                                <line x1="{{ $x1 }}" y1="{{ $y1 }}" x2="{{ $x2 }}" y2="{{ $y2 }}" stroke="{{ \App\Git\Graph::color($edge['to']) }}" stroke-width="2" />
                                            @else
                                                <path d="M{{ $x1 }} {{ $y1 }} C{{ $x1 }} {{ $y1 + 16 }}, {{ $x2 }} {{ $y1 + 12 }}, {{ $x2 }} {{ $y2 }}" fill="none" stroke="{{ \App\Git\Graph::color($edge['to']) }}" stroke-width="2" />
                                            @endif
                                        @endforeach
                                    @endforeach
                                    @foreach ($graph['rows'] as $i => $row)
                                        <circle cx="{{ $row['lane'] * 14 + 9 }}" cy="{{ $i * 28 + 14 }}" r="{{ ($log['commits'][$i]['head'] ?? false) ? 5 : 4 }}" fill="{{ \App\Git\Graph::color($row['lane']) }}" @if ($log['commits'][$i]['head'] ?? false) stroke="white" stroke-width="2" @endif />
                                    @endforeach
                                </svg>
                                <ul style="padding-left: {{ $gutter + 4 }}px">
                                    @foreach ($log['commits'] as $c)
                                        <li class="flex h-7 items-center gap-2 text-xs" wire:key="commit-{{ $c['sha'] ?? $loop->index }}" title="{{ ($c['short'] ?? '').' · '.($c['author'] ?? '') }}">
                                            <button type="button" wire:click="showCommit(@js((string) ($c['sha'] ?? '')))" @class(['min-w-[45%] flex-1 truncate text-left hover:underline', 'font-semibold' => ($commit['sha'] ?? null) === ($c['sha'] ?? '')])>{{ $c['subject'] ?? '' }}</button>
                                            @if (! empty($c['refs']))
                                                <span class="flex min-w-0 shrink gap-1 overflow-hidden">
                                                    @foreach ((array) $c['refs'] as $ref)
                                                        <flux:badge size="sm" class="truncate" :color="str_starts_with((string) $ref, 'origin/') ? 'zinc' : (str_starts_with((string) $ref, 'tag: ') ? 'amber' : 'blue')">{{ $ref }}</flux:badge>
                                                    @endforeach
                                                </span>
                                            @endif
                                            <span class="shrink-0 font-mono text-zinc-500">{{ $c['short'] ?? '' }}</span>
                                            <span class="hidden shrink-0 whitespace-nowrap text-zinc-500 2xl:inline">{{ $c['author'] ?? '' }}</span>
                                            @if (! empty($c['date']))<span class="shrink-0 whitespace-nowrap text-zinc-500">{{ \Illuminate\Support\Carbon::parse($c['date'])->diffForHumans(short: true) }}</span>@endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @elseif ($log)
                            <flux:text>{{ __('No commits yet.') }}</flux:text>
                        @elseif (! $gitError)
                            <flux:text>{{ __('Loading…') }}</flux:text>
                        @endif

                    @else
                        @if ($branches)
                            @if ($canUseTerminal)
                                <form wire:submit="createBranch" class="mb-3 flex gap-2">
                                    <flux:input size="sm" wire:model="newBranch" :placeholder="__('New branch from :branch', ['branch' => $branches['current'] ?? 'HEAD'])" class="flex-1" />
                                    <flux:button size="sm" type="submit" icon="plus">{{ __('Create') }}</flux:button>
                                </form>
                            @endif
                            <div class="mb-2 flex items-center justify-between">
                                <div class="text-[10px] uppercase tracking-wide text-zinc-500">{{ __('Local') }}</div>
                                @if ($canUseTerminal)
                                    <flux:button size="xs" variant="ghost" icon="arrow-down-tray" wire:click="fetch">{{ __('Fetch') }}</flux:button>
                                @endif
                            </div>
                            <ul class="mb-4 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                                @forelse ($branches['local'] as $b)
                                    <li class="flex items-center gap-3 px-3 py-1.5 text-xs" wire:key="local-{{ $b['name'] ?? $loop->index }}">
                                        <span @class(['min-w-0 flex-1 truncate font-mono', 'font-semibold' => $b['current'] ?? false])>{{ $b['name'] ?? '' }}@if ($b['current'] ?? false) <span class="ml-1 text-green-600">●</span>@endif</span>
                                        @if (! empty($b['track'])) <span class="text-zinc-500">{{ $b['track'] }}</span> @endif
                                        @if (! empty($b['upstream'])) <span class="hidden text-zinc-400 sm:inline">→ {{ $b['upstream'] }}</span> @endif
                                        <span class="font-mono text-zinc-500">{{ $b['sha'] ?? '' }}</span>
                                        @if ($canUseTerminal && ! ($b['current'] ?? false))
                                            <flux:button size="xs" variant="ghost" wire:click="checkout(@js((string) ($b['name'] ?? '')))">{{ __('Switch') }}</flux:button>
                                            <flux:dropdown position="bottom" align="end">
                                                <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('More')" />
                                                <flux:menu>
                                                    <flux:menu.item icon="arrows-right-left" wire:click="merge(@js((string) ($b['name'] ?? '')))" wire:confirm="{{ __('Merge :branch into :current?', ['branch' => $b['name'] ?? '', 'current' => $branches['current'] ?? 'HEAD']) }}">{{ __('Merge into :current', ['current' => $branches['current'] ?? 'HEAD']) }}</flux:menu.item>
                                                    <flux:menu.item icon="trash" variant="danger" wire:click="deleteBranch(@js((string) ($b['name'] ?? '')))" wire:confirm="{{ __('Delete the branch :branch? Git refuses if it is not merged anywhere.', ['branch' => $b['name'] ?? '']) }}">{{ __('Delete') }}</flux:menu.item>
                                                </flux:menu>
                                            </flux:dropdown>
                                        @endif
                                    </li>
                                @empty
                                    <li class="px-3 py-2 text-xs text-zinc-500">{{ __('No local branches.') }}</li>
                                @endforelse
                            </ul>
                            <div class="mb-2 text-[10px] uppercase tracking-wide text-zinc-500">{{ __('Remote') }}</div>
                            <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                                @forelse ($branches['remote'] as $b)
                                    <li class="flex items-center gap-3 px-3 py-1.5 text-xs" wire:key="remote-{{ $b['name'] ?? $loop->index }}">
                                        <span class="min-w-0 flex-1 truncate font-mono">{{ $b['name'] ?? '' }}</span>
                                        <span class="font-mono text-zinc-500">{{ $b['sha'] ?? '' }}</span>
                                        @if ($canUseTerminal)
                                            <flux:button size="xs" variant="ghost" wire:click="checkout(@js((string) ($b['name'] ?? '')))">{{ __('Check out') }}</flux:button>
                                            <flux:dropdown position="bottom" align="end">
                                                <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('More')" />
                                                <flux:menu>
                                                    <flux:menu.item icon="arrows-right-left" wire:click="merge(@js((string) ($b['name'] ?? '')))" wire:confirm="{{ __('Merge :branch into :current?', ['branch' => $b['name'] ?? '', 'current' => $branches['current'] ?? 'HEAD']) }}">{{ __('Merge into :current', ['current' => $branches['current'] ?? 'HEAD']) }}</flux:menu.item>
                                                </flux:menu>
                                            </flux:dropdown>
                                        @endif
                                    </li>
                                @empty
                                    <li class="px-3 py-2 text-xs text-zinc-500">{{ __('No remote branches. Fetch to see them.') }}</li>
                                @endforelse
                            </ul>
                        @elseif (! $gitError)
                            <flux:text>{{ __('Loading…') }}</flux:text>
                        @endif
                    @endif
                </div>
            </div>
        @elseif ($tab === 'terminal')
            <div class="flex h-full min-h-0 flex-col" @if ($run && ($run['status'] ?? '') === 'running') wire:poll.2s="refreshRun" @endif>
                <div class="border-b border-zinc-200 p-3 dark:border-zinc-700">
                    <div class="mb-2 flex flex-wrap gap-1.5">
                        @foreach ($shortcuts as $shortcut)
                            <flux:button size="xs" variant="filled" wire:click="runCommand(@js($shortcut['command']))" :title="$shortcut['command']">{{ $shortcut['label'] }}</flux:button>
                        @endforeach
                    </div>
                    <form wire:submit="runCommand" class="flex gap-2">
                        <flux:input wire:model="command" class="font-mono" :placeholder="__('php artisan … · composer … · npm … · git …')" autocomplete="off" />
                        <flux:button type="submit" variant="primary" icon="play">{{ __('Run') }}</flux:button>
                    </form>
                        <flux:text class="mt-1 text-xs">{{ __('Runs in your workspace as you. Allowed: php artisan, composer, npm, git, vendor/bin/pest, pint and phpstan; no shell, no pipes.') }}</flux:text>
                    @if ($terminalError)
                        <flux:text class="mt-1 text-xs text-red-600">{{ $terminalError }}</flux:text>
                    @endif
                </div>
                <div class="min-h-0 flex-1 overflow-auto bg-zinc-950 p-3 font-mono text-[11px] leading-snug text-zinc-100">
                    @if ($run)
                        <div class="mb-2 flex items-center gap-2 text-zinc-400">
                            <span class="text-zinc-200">$ {{ $run['command'] ?? '' }}</span>
                            @if (($run['status'] ?? '') === 'running')
                                <flux:icon.loading class="size-3" />
                            @else
                                <span @class(['text-green-400' => ($run['status'] ?? '') === 'done', 'text-red-400' => ($run['status'] ?? '') === 'failed'])>{{ $run['status'] ?? '' }}@if (isset($run['exit_code'])) · exit {{ $run['exit_code'] }}@endif</span>
                            @endif
                        </div>
                        <pre class="whitespace-pre-wrap">{{ \App\Support\Ansi::toHtml($run['output'] ?? '') }}@if ($run['truncated'] ?? false)
[output truncated]@endif</pre>
                    @else
                        <div class="text-zinc-500">{{ __('Pick a shortcut or type a command.') }}</div>
                    @endif
                    @foreach ($runs as $earlier)
                        <details class="mt-3 text-zinc-400">
                            <summary class="cursor-pointer">$ {{ $earlier['command'] ?? '' }} · {{ $earlier['status'] ?? '' }}</summary>
                            <pre class="mt-1 whitespace-pre-wrap">{{ \App\Support\Ansi::toHtml($earlier['output'] ?? '') }}</pre>
                        </details>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
