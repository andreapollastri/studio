@php
    $searching = trim($logSearch) !== '';
@endphp

<div class="mb-3">
    <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="logSearch" :placeholder="__('Search messages, authors, commit ids')" clearable />
</div>

@if ($commit)
    <div class="mb-4 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
        <div class="flex items-start gap-3 border-b border-zinc-200 px-3 py-2 dark:border-zinc-700">
            <div class="min-w-0 flex-1">
                <div class="font-medium">{{ $commit['subject'] ?? '' }}</div>
                @if (! empty($commit['body']))
                    <div class="mt-1 whitespace-pre-wrap text-xs text-zinc-600 dark:text-zinc-300">{{ $commit['body'] }}</div>
                @endif
                <div class="mt-1 flex flex-wrap items-center gap-1 text-xs text-zinc-500">
                    <button type="button" class="font-mono hover:text-zinc-800 dark:hover:text-zinc-200" x-data="{ copied: false }" x-on:click="navigator.clipboard?.writeText(@js((string) ($commit['sha'] ?? ''))); copied = true; setTimeout(() => copied = false, 1200)" title="{{ __('Copy the full id') }}">
                        <span x-show="! copied">{{ $commit['short'] ?? '' }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                    </button>
                    · {{ $commit['author'] ?? '' }}
                    @if (! empty($commit['date'])) · {{ \Illuminate\Support\Carbon::parse($commit['date'])->toDayDateTimeString() }} @endif
                    @if (count($commit['parents'] ?? []) > 1) · <flux:badge size="sm" color="violet">{{ __('merge') }}</flux:badge> @endif
                    @foreach ((array) ($commit['refs'] ?? []) as $ref) <flux:badge size="sm">{{ $ref }}</flux:badge> @endforeach
                </div>
            </div>
            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="closeCommit" :aria-label="__('Close')" />
        </div>
        @if ($canUseTerminal)
            @php
                $sha = (string) ($commit['sha'] ?? '');
                $label = trim(($commit['short'] ?? '').' '.($commit['subject'] ?? ''));
            @endphp
            <div class="flex flex-wrap items-center gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-700">
                <form wire:submit="createBranch(@js($sha))" class="flex min-w-0 flex-1 gap-2">
                    <flux:input size="sm" wire:model="newBranch" :placeholder="__('New branch from here')" class="min-w-0 flex-1" />
                    <flux:button size="sm" type="submit" icon="plus">{{ __('Branch') }}</flux:button>
                </form>
                <flux:button size="sm" variant="ghost" icon="tag" wire:click="askTag(@js($sha))">{{ __('Tag') }}</flux:button>
                <flux:dropdown position="bottom" align="end">
                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('More')" />
                    <flux:menu>
                        @if ($commit['onCurrent'] ?? true)
                            <flux:menu.item icon="arrow-uturn-left" x-on:click="$dispatch('git-confirm', @js(['method' => 'revert', 'args' => [$sha], 'icon' => 'revert', 'title' => __('Revert this commit?'), 'body' => __('A new commit that undoes it is added on :branch. Nothing is rewritten: safe on pushed commits.', ['branch' => $git['branch'] ?? 'HEAD']), 'subject' => $label, 'confirm' => __('Revert')]))">{{ __('Revert') }}</flux:menu.item>
                            <flux:menu.item icon="backward" wire:click="askReset">{{ __('Reset :branch to here…', ['branch' => $git['branch'] ?? 'HEAD']) }}</flux:menu.item>
                        @else
                            <flux:menu.item icon="document-duplicate" x-on:click="$dispatch('git-confirm', @js(['method' => 'cherryPick', 'args' => [$sha], 'icon' => 'pick', 'title' => __('Cherry-pick onto :branch?', ['branch' => $git['branch'] ?? 'HEAD']), 'body' => __('A copy of this commit is added on :branch. If it conflicts, nothing changes.', ['branch' => $git['branch'] ?? 'HEAD']), 'subject' => $label, 'confirm' => __('Cherry-pick')]))">{{ __('Cherry-pick onto :branch', ['branch' => $git['branch'] ?? 'HEAD']) }}</flux:menu.item>
                        @endif
                    </flux:menu>
                </flux:dropdown>
            </div>
        @endif
        <pre class="overflow-x-auto whitespace-pre px-3 py-2 font-mono text-xs text-zinc-600 dark:text-zinc-300">{{ $commit['stat'] ?? '' }}</pre>
        <x-studio.diff :text="$commit['patch'] ?? ''" class="max-h-[50vh] rounded-none border-x-0 border-b-0" />
    </div>
@endif

@if ($log && count($log['commits']) > 0)
    @if ($searching)
        {{-- A search breaks the lines of the graph: a plain list --}}
        <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @foreach ($log['commits'] as $c)
                <li class="flex h-7 items-center gap-2 text-xs" wire:key="found-{{ $c['sha'] ?? $loop->index }}">
                    <button type="button" wire:click="showCommit(@js((string) ($c['sha'] ?? '')))" @class(['min-w-0 flex-1 truncate text-left hover:underline', 'font-semibold' => ($commit['sha'] ?? null) === ($c['sha'] ?? '')])>{{ $c['subject'] ?? '' }}</button>
                    <span class="shrink-0 font-mono text-zinc-500">{{ $c['short'] ?? '' }}</span>
                    <span class="hidden shrink-0 whitespace-nowrap text-zinc-500 sm:inline">{{ $c['author'] ?? '' }}</span>
                    @if (! empty($c['date']))<span class="shrink-0 whitespace-nowrap text-zinc-500">{{ \Illuminate\Support\Carbon::parse($c['date'])->diffForHumans(short: true) }}</span>@endif
                </li>
            @endforeach
        </ul>
    @else
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
    @endif
    @if (count($log['commits']) >= $logLimit && $logLimit < 400)
        <div class="mt-2 text-center">
            <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="loadMoreLog">{{ __('Load more') }}</flux:button>
        </div>
    @endif
@elseif ($log)
    <flux:text>{{ $searching ? __('No commit matches.') : __('No commits yet.') }}</flux:text>
@elseif (! $gitError)
    <flux:text>{{ __('Loading…') }}</flux:text>
@endif
