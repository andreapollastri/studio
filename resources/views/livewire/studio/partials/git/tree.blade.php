@php
    $files = collect($git['files'] ?? []);
    $staged = $files->filter(fn ($f) => $f['staged'])->values();
    $unstaged = $files->filter(fn ($f) => $f['unstaged'])->values();
    $conflicted = $files->filter(fn ($f) => $f['conflicted'])->values();
    $operation = $git['operation'] ?? null;
    $last = $git['last'] ?? null;
    // one letter per file, as git status says it, in the colour of what it means
    // and the file name in the same colour, struck through when it goes
    $badge = fn (string $code, bool $untracked = false) => match (true) {
        $untracked => ['U', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300', __('New file'), 'text-emerald-700 dark:text-emerald-400'],
        $code === 'M' => ['M', 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300', __('Modified'), 'text-amber-700 dark:text-amber-300'],
        $code === 'A' => ['A', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300', __('Added'), 'text-emerald-700 dark:text-emerald-400'],
        $code === 'D' => ['D', 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300', __('Deleted'), 'text-red-700 line-through decoration-red-400/70 dark:text-red-400'],
        $code === 'R' => ['R', 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300', __('Renamed'), 'text-sky-700 dark:text-sky-300'],
        $code === 'C' => ['C', 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300', __('Copied'), 'text-sky-700 dark:text-sky-300'],
        $code === 'T' => ['T', 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300', __('Type changed'), 'text-zinc-700 dark:text-zinc-300'],
        default => ['!', 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300', __('Conflict'), 'text-red-700 dark:text-red-400'],
    };
    // five blocks in the share of lines added and removed, as GitHub draws them
    $blocks = function (int $added, int $removed): array {
        $total = $added + $removed;
        if ($total === 0) {
            return [0, 0];
        }
        $green = $added === 0 ? 0 : max(1, (int) round(5 * $added / $total));
        $red = $removed === 0 ? 0 : max(1, (int) round(5 * $removed / $total));

        return $green + $red > 5 ? ($green > $red ? [5 - $red, $red] : [$green, 5 - $green]) : [$green, $red];
    };
    $row = 'group flex items-center gap-2 rounded-md px-1.5 py-0.5 hover:bg-zinc-100 dark:hover:bg-zinc-800';
    $icon = 'touch-reveal shrink-0 rounded p-0.5 text-zinc-400 opacity-0 hover:bg-zinc-200 group-hover:opacity-100 focus:opacity-100 dark:hover:bg-zinc-700';
@endphp

@if ($git)
    @if ($git['detached'] ?? false)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-3">
            <flux:callout.heading>{{ __('Not on a branch') }}</flux:callout.heading>
            <flux:callout.text>{{ __('The working tree is on a commit, not on a branch: commits made here are easily lost. Create a branch to keep them.') }}</flux:callout.text>
            @if ($canUseTerminal)
                <x-slot name="actions">
                    <form wire:submit="createBranch" class="flex gap-2">
                        <flux:input size="sm" wire:model="newBranch" :placeholder="__('New branch name')" />
                        <flux:button size="sm" type="submit" icon="plus">{{ __('Create') }}</flux:button>
                    </form>
                </x-slot>
            @endif
        </flux:callout>
    @endif

    {{-- Something git left open (by Claude or from the terminal): its conflicts, file by file --}}
    @if ($operation)
        <div class="mb-3 overflow-hidden rounded-lg border border-amber-300 dark:border-amber-800">
            <div class="flex items-start gap-3 bg-amber-50 px-3 py-2 dark:bg-amber-950/40">
                <flux:icon.arrows-right-left class="mt-0.5 size-4 shrink-0 text-amber-600" />
                <div class="min-w-0 flex-1">
                    <div class="font-medium text-amber-900 dark:text-amber-200">{{ __(':operation in progress', ['operation' => ucfirst(__($operation))]) }}</div>
                    <div class="text-xs text-amber-800 dark:text-amber-300">
                        {{ $conflicted->isNotEmpty() ? __('Pick a version for each conflicting file, or edit it and mark it resolved; then commit to conclude.') : __('No conflicts left: commit to conclude, or abort to go back.') }}
                    </div>
                </div>
                @if ($canUseTerminal)
                    <flux:button size="xs" variant="ghost" icon="x-circle" x-on:click="$dispatch('git-confirm', @js(['method' => 'abortMerge', 'icon' => 'abort', 'danger' => true, 'title' => __('Abort the :operation?', ['operation' => __($operation)]), 'body' => __('The working tree goes back to how it was before it.'), 'confirm' => __('Abort')]))">{{ __('Abort') }}</flux:button>
                @endif
            </div>
            @if ($conflicted->isNotEmpty())
                <ul class="divide-y divide-amber-200 dark:divide-amber-900">
                    @foreach ($conflicted as $file)
                        <li class="flex flex-wrap items-center gap-2 px-3 py-1.5 text-xs" wire:key="conflict-{{ md5($file['path']) }}">
                            <span class="inline-flex size-4 shrink-0 items-center justify-center rounded bg-red-100 text-[10px] font-bold text-red-700 dark:bg-red-950 dark:text-red-300">!</span>
                            <x-studio.file-icon :path="$file['path']" />
                            <button type="button" wire:click="openFile(@js($file['path']))" class="min-w-0 flex-1 truncate text-left font-mono text-red-700 hover:underline dark:text-red-400">{{ $file['path'] }}</button>
                            @if ($canUseTerminal)
                                <flux:button.group>
                                    <flux:button size="xs" wire:click="resolve(@js($file['path']), 'ours')" :title="__('Keep the version of :branch', ['branch' => $git['branch']])">{{ __('Keep mine') }}</flux:button>
                                    <flux:button size="xs" wire:click="resolve(@js($file['path']), 'theirs')" :title="__('Take the incoming version')">{{ __('Take theirs') }}</flux:button>
                                    <flux:button size="xs" icon="check" wire:click="resolve(@js($file['path']), 'resolved')" :title="__('I edited it: mark it resolved')">{{ __('Resolved') }}</flux:button>
                                </flux:button.group>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- Commit --}}
    @if ($canUseTerminal && ($files->isNotEmpty() || $operation || $last))
        <form wire:submit="commitChanges(@js($staged->isNotEmpty() ? 'staged' : 'all'))" class="mb-3 rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">
            <flux:textarea wire:model="commitMessage" rows="2" resize="vertical" :placeholder="__('Commit message: the first line is the title')" class="font-mono! text-xs!" />
            <div class="mt-2 flex flex-wrap items-center gap-2">
                @if ($last)
                    <flux:checkbox wire:model.live="amend" :label="__('Amend last commit')" />
                    @if ($amend && $last['pushed'])
                        <flux:tooltip :content="__('This commit is already on the remote: after amending, a force push replaces it there.')">
                            <flux:icon.exclamation-triangle variant="micro" class="size-4 text-amber-500" />
                        </flux:tooltip>
                    @endif
                @endif
                <span class="ml-auto"></span>
                @if ($files->isNotEmpty() || $operation || $amend)
                    <flux:button size="sm" variant="ghost" icon="arrow-up" wire:click="commitChanges(@js($staged->isNotEmpty() ? 'staged' : 'all'), true)" :title="__('Commit, then push the branch')">{{ __('Commit & push') }}</flux:button>
                    <flux:button size="sm" type="submit" variant="primary" icon="check">
                        @if ($amend)
                            {{ __('Amend') }}
                        @elseif ($staged->isNotEmpty())
                            {{ trans_choice('Commit :count staged file|Commit :count staged files', $staged->count(), ['count' => $staged->count()]) }}
                        @else
                            {{ __('Commit all') }}
                        @endif
                    </flux:button>
                @endif
            </div>
        </form>
    @endif

    @if ($files->isEmpty() && ! $operation)
        <div class="flex flex-col items-center gap-2 py-8 text-center">
            <flux:icon.check-circle class="size-8 text-emerald-500" />
            <flux:text>{{ __('No uncommitted changes.') }}</flux:text>
            @if ($last)
                <flux:text class="text-xs">{{ __('Last commit:') }} <span class="font-mono">{{ $last['short'] }}</span> {{ $last['subject'] }}</flux:text>
            @endif
            @if ($canUseTerminal && ($git['stashes'] ?? 0) > 0)
                <flux:button size="xs" variant="ghost" icon="archive-box" wire:click="setChangesView('stashes')">{{ trans_choice(':count stash|:count stashes', $git['stashes'], ['count' => $git['stashes']]) }}</flux:button>
            @endif
        </div>
    @else
        @if ($canUseTerminal && ! $operation)
            <div class="mb-2 flex flex-wrap items-center gap-1">
                <flux:button size="xs" variant="ghost" icon="archive-box-arrow-down" wire:click="askStash" :title="__('Put the changes aside, for example to switch branch')">{{ __('Stash') }}</flux:button>
                @if (($git['stashes'] ?? 0) > 0)
                    <flux:button size="xs" variant="ghost" icon="archive-box" wire:click="setChangesView('stashes')">{{ trans_choice(':count stash|:count stashes', $git['stashes'], ['count' => $git['stashes']]) }}</flux:button>
                @endif
                <flux:button size="xs" variant="ghost" icon="trash" class="ml-auto text-red-600!" x-on:click="$dispatch('git-confirm', @js(['method' => 'discard', 'icon' => 'trash', 'danger' => true, 'title' => __('Discard all changes?'), 'body' => __('Every uncommitted change goes, new files included. This cannot be undone.'), 'confirm' => __('Discard all')]))">{{ __('Discard all') }}</flux:button>
            </div>
        @endif

        {{-- Staged, then the rest: click a file for its diff --}}
        @foreach ([['staged', __('Staged'), $staged], ['unstaged', __('Changes'), $unstaged]] as [$kind, $label, $list])
            @if ($list->isNotEmpty())
                <div class="mb-3">
                    <div class="mb-1 flex items-center gap-2 px-1.5">
                        <span class="text-[10px] font-medium uppercase tracking-wide text-zinc-500">{{ $label }}</span>
                        <flux:badge size="sm">{{ $list->count() }}</flux:badge>
                        @if ($canUseTerminal && ! $operation)
                            @if ($kind === 'staged')
                                <button type="button" wire:click="unstage" class="ml-auto text-[11px] text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">{{ __('Unstage all') }}</button>
                            @else
                                <button type="button" wire:click="stage" class="ml-auto text-[11px] text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">{{ __('Stage all') }}</button>
                            @endif
                        @endif
                    </div>
                    <ul class="font-mono text-xs">
                        @foreach ($list as $file)
                            @php([$letter, $color, $meaning, $tint] = $badge($kind === 'staged' ? $file['index'] : $file['worktree'], $kind === 'unstaged' && $file['untracked']))
                            @php($open = ($fileDiff['path'] ?? null) === $file['path'])
                            @php($lines = $file['lines'][$kind] ?? null)
                            <li @class([$row, 'bg-zinc-100 dark:bg-zinc-800' => $open]) wire:key="{{ $kind }}-{{ md5($file['path']) }}">
                                <span class="inline-flex size-4 shrink-0 items-center justify-center rounded text-[10px] font-bold {{ $color }}" title="{{ $meaning }}">{{ $letter }}</span>
                                <x-studio.file-icon :path="$file['path']" />
                                <button type="button" wire:click="openFile(@js($file['path']))" class="min-w-0 flex-1 truncate text-left" title="{{ $file['from'] ? $file['from'].' → '.$file['path'] : $file['path'] }}">
                                    <span class="{{ $tint }}">{{ basename($file['path']) }}</span>
                                    @if (dirname($file['path']) !== '.')<span class="text-zinc-400"> {{ dirname($file['path']) }}</span>@endif
                                </button>
                                @if ($lines && $lines['added'] === null)
                                    <span class="shrink-0 text-[10px] text-zinc-400">{{ __('binary') }}</span>
                                @elseif ($lines && ($lines['added'] + $lines['removed']) > 0)
                                    @php([$green, $red] = $blocks($lines['added'], $lines['removed']))
                                    <span class="flex shrink-0 items-center gap-1 text-[10px] tabular-nums" title="{{ __(':added lines added, :removed removed', ['added' => $lines['added'], 'removed' => $lines['removed']]) }}">
                                        @if ($lines['added'] > 0)<span class="text-emerald-600 dark:text-emerald-400">+{{ $lines['added'] }}</span>@endif
                                        @if ($lines['removed'] > 0)<span class="text-red-600 dark:text-red-400">−{{ $lines['removed'] }}</span>@endif
                                        <span class="hidden gap-px sm:flex">
                                            @for ($i = 0; $i < 5; $i++)
                                                <span @class([
                                                    'size-1.5 rounded-[1px]',
                                                    'bg-emerald-500' => $i < $green,
                                                    'bg-red-500' => $i >= $green && $i < $green + $red,
                                                    'bg-zinc-300 dark:bg-zinc-600' => $i >= $green + $red,
                                                ])></span>
                                            @endfor
                                        </span>
                                    </span>
                                @endif
                                @if ($canUseTerminal && ! $operation)
                                    @if ($kind === 'staged')
                                        <button type="button" class="{{ $icon }}" wire:click="unstage(@js($file['path']))" title="{{ __('Unstage') }}"><flux:icon.minus variant="micro" class="size-3.5" /></button>
                                    @else
                                        <button type="button" class="{{ $icon }} hover:text-red-600" x-on:click="$dispatch('git-confirm', @js(['method' => 'discard', 'args' => [$file['from'] ? $file['from'].' -> '.$file['path'] : $file['path']], 'icon' => 'trash', 'danger' => true, 'title' => __('Discard these changes?'), 'body' => $file['untracked'] ? __('The new file is deleted. This cannot be undone.') : __('The file goes back to its last commit. This cannot be undone.'), 'subject' => $file['path'], 'confirm' => __('Discard')]))" title="{{ __('Discard') }}"><flux:icon.arrow-uturn-left variant="micro" class="size-3.5" /></button>
                                        <button type="button" class="{{ $icon }}" wire:click="stage(@js($file['path']))" title="{{ __('Stage') }}"><flux:icon.plus variant="micro" class="size-3.5" /></button>
                                    @endif
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endforeach

        @if ($fileDiff)
            <div class="mb-3">
                <div class="mb-1 flex items-center gap-2">
                    <flux:icon.document-text variant="micro" class="size-4 text-zinc-400" />
                    <span class="min-w-0 flex-1 truncate font-mono text-xs font-medium">{{ $fileDiff['path'] }}</span>
                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="closeFile" :aria-label="__('Close')" />
                </div>
                @if ($fileDiff['staged'] !== '')
                    <div class="mb-1 text-[10px] uppercase tracking-wide text-zinc-500">{{ __('Staged') }}</div>
                    <x-studio.diff :text="$fileDiff['staged']" class="mb-2 max-h-[50vh]" />
                @endif
                @if ($fileDiff['unstaged'] !== '' || $fileDiff['staged'] === '')
                    @if ($fileDiff['staged'] !== '')<div class="mb-1 text-[10px] uppercase tracking-wide text-zinc-500">{{ __('Not staged') }}</div>@endif
                    <x-studio.diff :text="$fileDiff['unstaged']" class="max-h-[50vh]" />
                @endif
            </div>
        @elseif (($git['diff'] ?? '') !== '')
            <details class="group mb-3" open>
                <summary class="mb-1 cursor-pointer list-none text-[10px] uppercase tracking-wide text-zinc-500">
                    <span class="group-open:hidden">▸</span><span class="hidden group-open:inline">▾</span> {{ __('All changes') }}
                    <span class="normal-case tracking-normal text-zinc-400">· {{ __('pick a file for its own diff') }}</span>
                </summary>
                @if ($git['stat'] !== '')
                    <pre class="mb-2 overflow-x-auto whitespace-pre font-mono text-[11px] text-zinc-600 dark:text-zinc-300">{{ $git['stat'] }}</pre>
                @endif
                <x-studio.diff :text="$git['diff']" class="max-h-[60vh]" />
            </details>
        @endif
    @endif
@elseif (! $gitError)
    <flux:text>{{ __('Loading…') }}</flux:text>
@endif
