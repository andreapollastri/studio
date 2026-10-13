<nav
    class="min-h-0 flex-1 overflow-y-auto px-2 pb-3"
    {{-- One of them working or asking: look every 3 seconds; otherwise now and then, in case one started elsewhere. --}}
    @if ($busy) wire:poll.3s="refreshList" @else wire:poll.30s.visible="refreshList" @endif
>
    @forelse ($conversations as $item)
        <a
            href="{{ route('studio.conversation', [$project, $item]) }}"
            wire:navigate
            wire:key="conversation-{{ $item->uuid }}"
            @class([
                'block rounded-lg px-3 py-2 text-sm transition',
                'bg-white shadow-sm dark:bg-zinc-800' => $item->uuid === $current,
                'hover:bg-white/60 dark:hover:bg-zinc-800/60' => $item->uuid !== $current,
            ])
        >
            <div class="flex items-center gap-2">
                <x-studio.conversation-dot :conversation="$item" />
                <span class="truncate">{{ $item->displayTitle() }}</span>
            </div>
            <div class="mt-0.5 truncate ps-4 text-xs text-zinc-500">{{ $item->updated_at?->diffForHumans() }}</div>
        </a>
    @empty
        <flux:text class="px-3 py-2 text-xs">{{ auth()->user()->canChat() ? __('No conversations. Open one to talk to Claude about this project.') : __('Conversations are for the team.') }}</flux:text>
    @endforelse
</nav>
