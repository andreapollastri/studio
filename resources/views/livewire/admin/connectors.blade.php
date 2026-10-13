<div class="flex h-full w-full flex-1 flex-col gap-6 max-sm:gap-4">
    <div>
        <flux:heading size="xl">{{ __('Connectors') }}</flux:heading>
        <flux:text class="mt-1">{{ __('The clouds and services your servers run on, driven from Studio through their APIs. A connector that is on shows under Infrastructure in the menu of the administrators, and nobody else\'s; the others stay hidden. Each one holds any number of accounts.') }}</flux:text>
    </div>

    <div class="grid gap-3 md:grid-cols-2">
        @foreach ($connectors as $key => $connector)
            @php($setting = $settings->get($key))
            @php($enabled = (bool) $setting?->enabled)
            <div wire:key="connector-{{ $key }}" @class([
                'flex flex-col gap-3 rounded-xl border p-4',
                'border-zinc-200 dark:border-zinc-700' => ! $enabled,
                'border-zinc-400 dark:border-zinc-500' => $enabled,
            ])>
                <div class="flex items-start gap-3">
                    <flux:icon :name="$connector->icon()" class="mt-0.5 size-6 shrink-0 text-zinc-400" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading>{{ $connector->label() }}</flux:heading>
                            <flux:badge size="sm" :color="($accounts[$key] ?? 0) > 0 ? 'blue' : 'zinc'">{{ trans_choice(':count account|:count accounts', (int) ($accounts[$key] ?? 0)) }}</flux:badge>
                        </div>
                        <flux:text class="mt-1 text-xs">{{ $connector->description() }}</flux:text>
                    </div>
                    <flux:switch :checked="$enabled" wire:click="toggle({{ Js::from($key) }})" :aria-label="__('Enable :connector', ['connector' => $connector->label()])" />
                </div>

                @if ($enabled)
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-zinc-100 pt-3 text-sm dark:border-zinc-800">
                        <flux:text class="text-xs">{{ __('Administrators only') }}</flux:text>
                        <flux:button size="xs" variant="ghost" icon:trailing="arrow-right" class="ms-auto" :href="route('infrastructure', $key)" wire:navigate>{{ ($accounts[$key] ?? 0) > 0 ? __('Open') : __('Add an account') }}</flux:button>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <flux:callout icon="shield-check" variant="secondary">
        <flux:callout.text>{{ __('Every administrator can do on a connector\'s accounts everything their credentials allow, after confirming their password. Give each account the narrowest token the job needs. Every action is written to Studio\'s log with who did it.') }}</flux:callout.text>
    </flux:callout>
</div>
