{{-- Where the Terminal tab opens a shell: your workspace, the project's own site, or the project's SSH hosts --}}
@if ($canUseRemote || $canUseDevelop)
    <div class="mb-2 inline-flex rounded-lg bg-zinc-100 p-0.5 text-xs dark:bg-zinc-800">
        @foreach (array_filter([
            'local' => [__('Local'), 'computer-desktop', __('A shell in your workspace')],
            'develop' => $canUseDevelop ? [__('Develop'), 'server-stack', __('A shell on the project\'s own site')] : null,
            'remote' => $canUseRemote ? [__('Remote (SSH)'), 'globe-alt', __('The project\'s SSH hosts')] : null,
        ]) as $mode => [$modeLabel, $modeIcon, $modeHint])
            <button type="button" wire:click="setTerminalMode('{{ $mode }}')" title="{{ $modeHint }}" @class([
                'flex items-center gap-1 rounded-md px-2.5 py-1 transition',
                'bg-white font-medium shadow-sm dark:bg-zinc-700' => $terminalMode === $mode,
                'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $terminalMode !== $mode,
            ])><flux:icon :name="$modeIcon" variant="micro" class="size-3.5" />{{ $modeLabel }}</button>
        @endforeach
    </div>
@endif
