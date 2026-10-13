@if ($stashes !== null)
    @if ($canUseTerminal && count($git['files'] ?? []) > 0)
        <div class="mb-3">
            <flux:button size="sm" icon="archive-box-arrow-down" wire:click="askStash">{{ __('Stash the current changes') }}</flux:button>
        </div>
    @endif
    <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($stashes as $st)
            @php($index = (int) ($st['index'] ?? 0))
            @php($files = (array) ($st['files'] ?? []))
            <li class="px-3 py-2 text-xs" wire:key="stash-{{ $index }}-{{ md5((string) ($st['message'] ?? '')) }}">
                <div class="flex items-center gap-2">
                    <flux:icon.archive-box variant="micro" class="size-3.5 shrink-0 text-zinc-400" />
                    <button type="button" class="min-w-0 flex-1 truncate text-left font-medium hover:underline" wire:click="showStash({{ $index }})">{{ $st['message'] ?? '' }}</button>
                    @if (! empty($st['branch']))<flux:badge size="sm" class="font-mono">{{ $st['branch'] }}</flux:badge>@endif
                    <span class="whitespace-nowrap text-zinc-500">{{ trans_choice(':count file|:count files', count($files), ['count' => count($files)]) }}</span>
                    @if (! empty($st['date']))<span class="hidden whitespace-nowrap text-zinc-500 sm:inline">{{ \Illuminate\Support\Carbon::parse($st['date'])->diffForHumans(short: true) }}</span>@endif
                    @if ($canUseTerminal)
                        <flux:button size="xs" variant="ghost" icon="arrow-uturn-up" wire:click="stashApply({{ $index }})" :title="__('Bring the changes back and delete the stash')">{{ __('Pop') }}</flux:button>
                        <flux:dropdown position="bottom" align="end">
                            <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('More')" />
                            <flux:menu>
                                <flux:menu.item icon="eye" wire:click="showStash({{ $index }})">{{ ($stashPatch['index'] ?? null) === $index ? __('Hide changes') : __('Show changes') }}</flux:menu.item>
                                <flux:menu.item icon="document-duplicate" wire:click="stashApply({{ $index }}, false)">{{ __('Apply and keep') }}</flux:menu.item>
                                <flux:menu.separator />
                                <flux:menu.item icon="trash" variant="danger" x-on:click="$dispatch('git-confirm', @js(['method' => 'stashDrop', 'args' => [$index], 'icon' => 'trash', 'danger' => true, 'title' => __('Delete this stash?'), 'body' => __('Its changes are lost. This cannot be undone.'), 'subject' => (string) ($st['message'] ?? ''), 'confirm' => __('Delete')]))">{{ __('Delete…') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                </div>
                @if (($stashPatch['index'] ?? null) === $index)
                    <div class="mt-2">
                        @if ($files !== [])
                            <div class="mb-1 font-mono text-[11px] text-zinc-500">{{ implode(' · ', $files) }}</div>
                        @endif
                        <x-studio.diff :text="$stashPatch['patch']" class="max-h-[50vh]" />
                    </div>
                @endif
            </li>
        @empty
            <li class="px-3 py-6 text-center text-xs text-zinc-500">{{ __('No stashes. Stash changes to put them aside, for example to switch branch.') }}</li>
        @endforelse
    </ul>
@elseif (! $gitError)
    <flux:text>{{ __('Loading…') }}</flux:text>
@endif
