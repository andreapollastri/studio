<div class="flex h-full w-full flex-1 flex-col gap-6 max-sm:gap-4">
    <div>
        <flux:heading size="xl">{{ __('Connectors') }}</flux:heading>
        <flux:text class="mt-1">{{ __('The services Studio drives through their APIs. Those that are on show in the menu; the others stay hidden.') }}</flux:text>
    </div>

    @foreach ([
        ['heading' => __('Infrastructure'), 'text' => __('The clouds and services your servers run on, for the administrators only: under Infrastructure in their menu, with shared accounts that administrators add.'), 'items' => $connectors, 'route' => 'infrastructure', 'who' => __('Administrators only'), 'git' => false],
        ['heading' => __('Git hosting'), 'text' => __('Pull requests, issues, branches and pipelines, for everyone: under Tools in the menu, where each person adds their own accounts (as many as they work with) with the name and email their commits carry.'), 'items' => $forges, 'route' => 'git', 'who' => __('Everyone, with their own accounts'), 'git' => true],
    ] as $group)
        <section class="flex flex-col gap-3" wire:key="group-{{ $group['route'] }}">
            <div>
                <flux:heading size="lg">{{ $group['heading'] }}</flux:heading>
                <flux:text class="mt-1 text-sm">{{ $group['text'] }}</flux:text>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                @foreach ($group['items'] as $key => $connector)
                    @php($on = (bool) ($enabled[$key] ?? false))
                    @php($count = (int) ($accounts[$key] ?? 0))
                    <div wire:key="connector-{{ $key }}" @class([
                        'flex flex-col gap-3 rounded-xl border p-4',
                        'border-zinc-200 dark:border-zinc-700' => ! $on,
                        'border-zinc-400 dark:border-zinc-500' => $on,
                    ])>
                        <div class="flex items-start gap-3">
                            <flux:icon :name="$connector->icon()" class="mt-0.5 size-6 shrink-0 text-zinc-400" />
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:heading>{{ $connector->label() }}</flux:heading>
                                    <flux:badge size="sm" :color="$count > 0 ? 'blue' : 'zinc'">{{ $group['git'] ? trans_choice(':count personal account|:count personal accounts', $count) : trans_choice(':count account|:count accounts', $count) }}</flux:badge>
                                </div>
                                <flux:text class="mt-1 text-xs">{{ $connector->description() }}</flux:text>
                            </div>
                            <flux:switch :checked="$on" wire:click="toggle({{ Js::from($key) }})" :aria-label="__('Enable :connector', ['connector' => $connector->label()])" />
                        </div>

                        @if ($on)
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-zinc-100 pt-3 text-sm dark:border-zinc-800">
                                <flux:text class="text-xs">{{ $group['who'] }}</flux:text>
                                <flux:button size="xs" variant="ghost" icon:trailing="arrow-right" class="ms-auto" :href="route($group['route'], $key)" wire:navigate>{{ $group['git'] || $count > 0 ? __('Open') : __('Add an account') }}</flux:button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    <flux:callout icon="shield-check" variant="secondary">
        <flux:callout.text>{{ __('Every administrator can do on an infrastructure connector\'s accounts everything their credentials allow, after confirming their password. Give each account the narrowest token the job needs. On the git hosts everyone acts as themselves, with their own tokens, which nobody else sees. Every action is written to Studio\'s log with who did it.') }}</flux:callout.text>
    </flux:callout>
</div>
