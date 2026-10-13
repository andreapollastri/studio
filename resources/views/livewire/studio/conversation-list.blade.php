<div
    class="flex min-h-0 flex-1 flex-col"
    {{-- One of them working or asking: look every 3 seconds; otherwise now and then, in case one started elsewhere. --}}
    @if ($busy) wire:poll.3s="refreshList" @else wire:poll.30s.visible="refreshList" @endif
>
    <nav class="min-h-0 flex-1 overflow-y-auto px-2 pb-3">
        @forelse ($conversations as $item)
            <div class="group relative" wire:key="conversation-{{ $item->uuid }}">
                <a
                    href="{{ route('studio.conversation', [$project, $item]) }}"
                    wire:navigate
                    @class([
                        'block rounded-lg py-2 ps-3 pe-8 text-sm transition max-md:py-3 max-md:pe-10',
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
                @can('delete', $item)
                    <flux:button
                        size="xs"
                        variant="ghost"
                        icon="trash"
                        class="touch-reveal absolute! end-1 top-1.5 opacity-0 group-hover:opacity-100 focus:opacity-100 max-md:top-3"
                        wire:click="confirmDelete('{{ $item->uuid }}')"
                        :tooltip="__('Delete')"
                        :aria-label="__('Delete the conversation')"
                    />
                @endcan
            </div>
        @empty
            <flux:text class="px-3 py-2 text-xs">{{ auth()->user()->canChat() ? __('No conversations. Open one to talk to Claude about this project.') : __('Conversations are for the team.') }}</flux:text>
        @endforelse
    </nav>

    <flux:modal name="delete-conversation" class="md:w-[28rem]" x-on:close="$wire.set('deleting', null)">
        @if ($doomed)
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Delete this conversation?') }}</flux:heading>
                <flux:text>
                    {!! __('«:title» and all its messages are deleted for good: it cannot be undone.', ['title' => '<strong>'.e($doomed->displayTitle()).'</strong>']) !!}
                </flux:text>
                @if ($doomed->isBusy())
                    <flux:callout variant="warning" icon="exclamation-triangle" :text="__('Claude is still working on it: it will be stopped.')" />
                @endif
                <flux:text class="text-xs">{{ __('The changes it made to the files of the workspace stay where they are.') }}</flux:text>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" icon="trash" wire:click="delete">{{ __('Delete for good') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
