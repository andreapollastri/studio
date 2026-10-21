{{-- The connection across the top of the SQL client and the Redis browser: its colour, what it is, the lock. --}}
@php($env = $connection->environment)
<div class="shrink-0 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
    <div class="h-1 {{ $env->bar() }}"></div>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2">
        @unless ($embedded)
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('databases')" wire:navigate :aria-label="__('All connections')" />
        @endunless
        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg text-xs font-bold {{ $connection->driver->tint() }}">{{ $connection->driver->short() }}</span>
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <flux:heading class="truncate">{{ $connection->name }}</flux:heading>
                <flux:badge size="sm" :color="$env->color()">{{ $env->label() }}</flux:badge>
            </div>
            <div class="truncate font-mono text-[11px] text-zinc-500">
                {{ $connection->address() }}
                @if ($connection->sshConnection)
                    · {{ __('via :ssh', ['ssh' => $connection->sshConnection->name]) }}
                @endif
            </div>
        </div>

        <div class="ms-auto flex flex-wrap items-center gap-2">
            {{ $slot ?? '' }}
            @unless ($canWrite)
                <flux:badge color="amber" icon="eye">{{ __('Read only') }}</flux:badge>
            @endunless
            @if ($env->isProduction() && $canWrite)
                <flux:button size="sm" :variant="$locked ? 'filled' : 'danger'" :icon="$locked ? 'lock-closed' : 'lock-open'" wire:click="toggleLock"
                    :tooltip="$locked ? __('Changes are locked on production: unlock them to edit data, structures and run statements that write.') : __('Changes are unlocked for this session: lock them again.')">
                    {{ $locked ? __('Locked') : __('Unlocked') }}
                </flux:button>
            @endif
            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refresh" :tooltip="__('Refresh')" />
        </div>
    </div>
</div>
