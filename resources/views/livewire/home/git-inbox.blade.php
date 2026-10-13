<div class="min-w-0 overflow-hidden rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
    <a href="{{ route('git', ['connector' => $item->connector, 'account' => $item->uuid]) }}" wire:navigate class="flex min-w-0 items-center gap-2">
        <flux:icon :name="$definition->icon()" class="size-5 text-zinc-400" />
        <span class="truncate font-medium hover:underline">{{ $item->name }}</span>
        <span class="truncate text-xs text-zinc-500">{{ $item->host }}</span>
    </a>
    @if ($error)
        <flux:text class="mt-3 text-xs text-red-600">{{ $error }}</flux:text>
    @else
        <div class="mt-3 space-y-3">
            @forelse ($groups as $heading => $rows)
                <div>
                    <div class="text-xs font-medium text-zinc-500">{{ $heading }}</div>
                    @if ($rows === [])
                        <div class="text-xs text-zinc-400">—</div>
                    @else
                        <ul class="mt-1 space-y-0.5">
                            @foreach (array_slice($rows, 0, 5, true) as $ref => $title)
                                <li class="flex min-w-0 items-baseline gap-2 text-sm max-sm:flex-col max-sm:gap-0">
                                    <span class="shrink-0 font-mono text-xs text-zinc-500">{{ is_int($ref) ? '' : $ref }}</span>
                                    <span class="min-w-0 max-w-full truncate">{{ is_scalar($title) ? $title : '' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @empty
                <flux:text class="text-xs">{{ __('Nothing waiting here.') }}</flux:text>
            @endforelse
        </div>
    @endif
</div>
