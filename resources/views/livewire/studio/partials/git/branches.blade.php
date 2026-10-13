@if ($branches)
    @php($current = $branches['current'] ?? 'HEAD')
    @if ($canUseTerminal)
        <form wire:submit="createBranch" class="mb-3 flex gap-2">
            <flux:input size="sm" wire:model="newBranch" :placeholder="__('New branch from :branch', ['branch' => $current])" class="flex-1" />
            <flux:button size="sm" type="submit" icon="plus">{{ __('Create') }}</flux:button>
        </form>
    @endif
    <div class="mb-2 flex items-center justify-between">
        <div class="text-[10px] uppercase tracking-wide text-zinc-500">{{ __('Local') }}</div>
        @if ($canUseTerminal)
            <flux:button size="xs" variant="ghost" icon="arrow-down-tray" wire:click="fetch" wire:loading.attr="disabled" wire:target="fetch">{{ __('Fetch') }}</flux:button>
        @endif
    </div>
    <ul class="mb-4 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($branches['local'] as $b)
            @php($name = (string) ($b['name'] ?? ''))
            @php($isCurrent = (bool) ($b['current'] ?? false))
            <li class="flex items-center gap-2 px-3 py-1.5 text-xs" wire:key="local-{{ $name ?: $loop->index }}">
                <flux:icon.code-bracket variant="micro" @class(['size-3.5 shrink-0', 'text-emerald-600' => $isCurrent, 'text-zinc-400' => ! $isCurrent]) />
                <span class="min-w-0 flex-1 truncate">
                    <span @class(['font-mono', 'font-semibold' => $isCurrent])>{{ $name }}</span>
                    @if ($isCurrent)<flux:badge size="sm" color="green" class="ml-1">{{ __('current') }}</flux:badge>@endif
                    @if (($b['gone'] ?? false))
                        <flux:badge size="sm" color="amber" class="ml-1" :title="__('Its branch on the remote was deleted')">{{ __('gone on remote') }}</flux:badge>
                    @elseif (! $isCurrent && ($b['merged'] ?? false))
                        <flux:badge size="sm" class="ml-1" :title="__('Already merged into :branch: safe to delete', ['branch' => $current])">{{ __('merged') }}</flux:badge>
                    @endif
                </span>
                @if (! empty($b['track']) && ! ($b['gone'] ?? false)) <span class="text-zinc-500">{{ $b['track'] }}</span> @endif
                @if (! empty($b['upstream'])) <span class="hidden text-zinc-400 lg:inline">→ {{ $b['upstream'] }}</span> @endif
                <span class="hidden font-mono text-zinc-500 sm:inline">{{ $b['sha'] ?? '' }}</span>
                @if ($canUseTerminal)
                    @if (! $isCurrent)
                        <flux:button size="xs" variant="ghost" wire:click="checkout(@js($name))">{{ __('Switch') }}</flux:button>
                    @endif
                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('More')" />
                        <flux:menu>
                            @if (! $isCurrent)
                                <flux:menu.item icon="arrows-right-left" wire:click="askMerge(@js($name))">{{ __('Merge into :current…', ['current' => $current]) }}</flux:menu.item>
                            @endif
                            <flux:menu.item icon="pencil-square" wire:click="askRenameBranch(@js($name))">{{ __('Rename…') }}</flux:menu.item>
                            @if (! $isCurrent)
                                <flux:menu.separator />
                                <flux:menu.item icon="trash" variant="danger" wire:click="askDeleteBranch(@js($name))">{{ __('Delete…') }}</flux:menu.item>
                            @endif
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </li>
        @empty
            <li class="px-3 py-2 text-xs text-zinc-500">{{ __('No local branches.') }}</li>
        @endforelse
    </ul>
    <div class="mb-2 text-[10px] uppercase tracking-wide text-zinc-500">{{ __('Remote') }}</div>
    <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($branches['remote'] as $b)
            @php($name = (string) ($b['name'] ?? ''))
            <li class="flex items-center gap-2 px-3 py-1.5 text-xs" wire:key="remote-{{ $name ?: $loop->index }}">
                <flux:icon.cloud variant="micro" class="size-3.5 shrink-0 text-zinc-400" />
                <span class="min-w-0 flex-1 truncate font-mono">{{ $name }}</span>
                <span class="hidden font-mono text-zinc-500 sm:inline">{{ $b['sha'] ?? '' }}</span>
                @if ($canUseTerminal)
                    <flux:button size="xs" variant="ghost" wire:click="checkout(@js($name))" :title="__('Switch to its local branch, created when missing')">{{ __('Check out') }}</flux:button>
                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('More')" />
                        <flux:menu>
                            <flux:menu.item icon="arrows-right-left" wire:click="askMerge(@js($name))">{{ __('Merge into :current…', ['current' => $current]) }}</flux:menu.item>
                            <flux:menu.separator />
                            <flux:menu.item icon="trash" variant="danger" x-on:click="$dispatch('git-confirm', @js(['method' => 'deleteRemoteBranch', 'args' => [$name], 'icon' => 'trash', 'danger' => true, 'title' => __('Delete this branch on the remote?'), 'body' => ($b['merged'] ?? false) ? __('It is merged into :branch. Everybody loses it on the remote; local branches stay.', ['branch' => $current]) : __('It is not merged into :branch: its commits are lost on the remote for everybody. Local branches stay.', ['branch' => $current]), 'subject' => $name, 'confirm' => __('Delete on remote')]))">{{ __('Delete on remote…') }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </li>
        @empty
            <li class="px-3 py-2 text-xs text-zinc-500">{{ __('No remote branches. Fetch to see them.') }}</li>
        @endforelse
    </ul>
@elseif (! $gitError)
    <flux:text>{{ __('Loading…') }}</flux:text>
@endif
