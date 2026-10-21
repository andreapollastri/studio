{{-- Which database the Db tab opens: your workspace's, the project's own site's, or one of the project's Databases connections --}}
<div class="flex flex-wrap items-center gap-2">
    @if ($canUseRemoteDb || $canUseDevelop)
        <div class="inline-flex rounded-lg bg-zinc-100 p-0.5 text-xs dark:bg-zinc-800">
            @foreach (array_filter([
                'local' => [__('Local'), 'computer-desktop', __('Your workspace\'s database')],
                'develop' => $canUseDevelop ? [__('Develop'), 'server-stack', __('The database of the project\'s own site')] : null,
                'remote' => $canUseRemoteDb ? [__('Remote'), 'globe-alt', __('The project\'s connections in Databases')] : null,
            ]) as $mode => [$modeLabel, $modeIcon, $modeHint])
                <button type="button" wire:click="setDbMode('{{ $mode }}')" title="{{ $modeHint }}" @class([
                    'flex items-center gap-1 rounded-md px-2.5 py-1 transition',
                    'bg-white font-medium shadow-sm dark:bg-zinc-700' => $dbMode === $mode,
                    'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $dbMode !== $mode,
                ])><flux:icon :name="$modeIcon" variant="micro" class="size-3.5" />{{ $modeLabel }}</button>
            @endforeach
        </div>
    @endif
    @if ($dbMode === 'remote' && $this->remoteDatabases->count() > 1)
        <flux:dropdown position="bottom" align="start">
            <flux:button size="xs" icon="circle-stack" icon:trailing="chevron-down">{{ $dbOpen?->name ?? __('Choose a connection') }}</flux:button>
            <flux:menu>
                @foreach ($this->remoteDatabases as $remoteDb)
                    <flux:menu.item wire:key="pane-db-{{ $remoteDb->uuid }}" wire:click="setDbMode('remote', '{{ $remoteDb->uuid }}')" :icon="$dbOpen?->is($remoteDb) ? 'check' : null">
                        {{ $remoteDb->name }} <span class="ms-1 text-xs text-zinc-500">{{ $remoteDb->environment->label() }}</span>
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    @endif
</div>
