@if ($tags !== null)
    @if ($canUseTerminal)
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <flux:button size="sm" icon="tag" wire:click="askTag">{{ __('Tag the current commit') }}</flux:button>
            @if (collect($tags)->contains(fn ($t) => ! ($t['pushed'] ?? false)))
                <flux:button size="sm" variant="ghost" icon="arrow-up" class="ml-auto" wire:click="pushTags">{{ __('Push all tags') }}</flux:button>
            @endif
        </div>
    @endif
    <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($tags as $t)
            @php($name = (string) ($t['name'] ?? ''))
            <li class="flex items-center gap-2 px-3 py-1.5 text-xs" wire:key="tag-{{ $name }}">
                <flux:icon.tag variant="micro" class="size-3.5 shrink-0 text-amber-500" />
                <span class="min-w-0 flex-1 truncate">
                    <span class="font-mono font-medium">{{ $name }}</span>
                    @if (! empty($t['message']))<span class="text-zinc-500"> · {{ $t['message'] }}</span>@endif
                </span>
                <button type="button" class="font-mono text-zinc-500 hover:underline" wire:click="openCommit(@js((string) ($t['sha'] ?? '')))" title="{{ __('Open the commit') }}">{{ $t['sha'] ?? '' }}</button>
                @if (! empty($t['date']))<span class="hidden whitespace-nowrap text-zinc-500 sm:inline">{{ \Illuminate\Support\Carbon::parse($t['date'])->diffForHumans(short: true) }}</span>@endif
                @if ($t['pushed'] ?? false)
                    <flux:badge size="sm" color="green" icon="cloud">{{ __('pushed') }}</flux:badge>
                @elseif ($canUseTerminal)
                    <flux:button size="xs" variant="ghost" icon="arrow-up" wire:click="pushTags(@js($name))">{{ __('Push') }}</flux:button>
                @endif
                @if ($canUseTerminal)
                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="askDeleteTag(@js($name))" :aria-label="__('Delete')" />
                @endif
            </li>
        @empty
            <li class="px-3 py-6 text-center text-xs text-zinc-500">{{ __('No tags yet. Tag a commit to mark a release.') }}</li>
        @endforelse
    </ul>
@elseif (! $gitError)
    <flux:text>{{ __('Loading…') }}</flux:text>
@endif
