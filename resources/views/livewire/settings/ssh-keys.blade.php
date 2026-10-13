<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
        <flux:heading size="lg">{{ __('SSH keys') }}</flux:heading>
        <flux:badge size="sm">{{ trans_choice(':count key|:count keys', $this->keys->count()) }}</flux:badge>
        <flux:button size="sm" icon="plus" class="ms-auto" wire:click="newKey">{{ __('New key') }}</flux:button>
    </div>
    <flux:text>{{ __('The keys the SSH terminal opens your connections with, and those of the projects where you are a developer. Generate one here and copy its public half to the server, or import one you already use: it is stored encrypted and only reaches the SSH gateway.') }}</flux:text>

    @if ($this->keys->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-6 text-center dark:border-zinc-700">
            <flux:icon.key class="mx-auto size-8 text-zinc-400" />
            <flux:text class="mt-2">{{ __('No keys yet.') }}</flux:text>
        </div>
    @else
        <div class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
            @foreach ($this->keys as $key)
                <div class="flex flex-wrap items-center gap-3 px-4 py-3" wire:key="ssh-key-{{ $key->uuid }}">
                    <flux:icon.key class="size-5 shrink-0 text-zinc-400" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ $key->name }}</span>
                            <flux:badge size="sm">{{ $key->label() }}</flux:badge>
                            @if ($key->connections_count)<span class="text-xs text-zinc-500">{{ trans_choice('used by :count connection|used by :count connections', $key->connections_count) }}</span>@endif
                        </div>
                        <div class="truncate font-mono text-xs text-zinc-500">{{ $key->fingerprint }}</div>
                    </div>
                    <div class="flex gap-1">
                        <flux:button size="xs" variant="ghost" icon="clipboard-document" :tooltip="__('Public key')" wire:click="showKey('{{ $key->uuid }}')" />
                        <flux:button size="xs" variant="ghost" icon="trash" :tooltip="__('Remove')" wire:click="deleteKey('{{ $key->uuid }}')" wire:confirm="{{ $key->connections_count ? __('Remove the key :name? :count connection(s) use it and will need another one.', ['name' => $key->name, 'count' => $key->connections_count]) : __('Remove the key :name?', ['name' => $key->name]) }}" />
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @include('livewire.ssh.partials.key-modals')
</div>
