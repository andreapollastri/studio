<div class="group flex h-full flex-col rounded-xl border border-zinc-200 p-4 transition hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600">
    <div class="flex items-start gap-3">
        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
            <flux:icon :name="$card['icon']" class="size-5 text-zinc-500" />
        </div>
        <div class="min-w-0 flex-1">
            <button type="button" class="block max-w-full truncate text-start font-medium hover:underline" wire:click="$parent.open('{{ $item->uuid }}')">{{ $card['title'] }}</button>
            <div class="truncate text-xs text-zinc-500">{{ $card['subtitle'] }}</div>
        </div>
        @if ($card['status'])
            <flux:badge size="sm" :color="$card['status']['color']" class="shrink-0">{{ $card['status']['label'] }}</flux:badge>
        @endif
    </div>

    @if ($card['error'])
        <flux:text class="mt-3 text-xs text-red-600 dark:text-red-400">{{ $card['error'] }}</flux:text>
    @else
        @if ($card['meters'] !== [])
            <div @class(['mt-3 grid gap-2', 'grid-cols-1' => count($card['meters']) === 1, 'grid-cols-2' => count($card['meters']) === 2, 'grid-cols-3' => count($card['meters']) === 3, 'grid-cols-4' => count($card['meters']) >= 4])>
                @foreach ($card['meters'] as $meter)
                    <div title="{{ $meter['label'] }}">
                        <div class="truncate text-[10px] uppercase tracking-wide text-zinc-500">{{ $meter['label'] }}</div>
                        <div class="mt-0.5 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div @class(['h-full rounded-full', 'bg-emerald-500' => $meter['percent'] < 70, 'bg-amber-500' => $meter['percent'] >= 70 && $meter['percent'] < 90, 'bg-red-500' => $meter['percent'] >= 90]) style="width: {{ min(100, max(1, $meter['percent'])) }}%"></div>
                        </div>
                        <div class="mt-0.5 text-xs tabular-nums">{{ $meter['text'] }}</div>
                    </div>
                @endforeach
            </div>
        @endif
        <dl class="mt-3 space-y-1 text-xs">
            @foreach ($card['facts'] as $label => $value)
                <div class="flex justify-between gap-3"><dt class="shrink-0 text-zinc-500">{{ $label }}</dt><dd class="truncate text-end" title="{{ $value }}">{{ $value }}</dd></div>
            @endforeach
        </dl>
        @foreach ($card['alerts'] as $alert)
            <div class="mt-2 flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-400">
                <flux:icon name="exclamation-triangle" variant="micro" class="mt-px size-3.5 shrink-0" /><span class="line-clamp-2">{{ $alert }}</span>
            </div>
        @endforeach
    @endif

    <div class="mt-auto flex items-center justify-between pt-3">
        <flux:button size="xs" variant="ghost" icon:trailing="arrow-right" wire:click="$parent.open('{{ $item->uuid }}')">{{ __('Open') }}</flux:button>
        <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="refresh" class="opacity-0 group-hover:opacity-100" :aria-label="__('Read again')" />
    </div>
</div>
