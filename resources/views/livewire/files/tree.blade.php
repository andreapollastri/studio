{{-- One level of the file tree; folders include themselves recursively while expanded. --}}
@php
    $entries = $dirs[$dir] ?? [];
    $pad = 8 + $depth * 12;
@endphp

@if ($creating && $creating['path'] === $dir)
    <form wire:submit="create" class="flex items-center gap-1 py-0.5 pr-2" style="padding-left: {{ $pad + 16 }}px">
        @if ($creating['type'] === 'dir')
            <flux:icon.folder class="size-3.5 shrink-0 text-amber-500" />
        @else
            <flux:icon.document class="size-3.5 shrink-0 text-zinc-400" />
        @endif
        <input
            type="text"
            wire:model="name"
            wire:keydown.escape="cancel"
            x-init="$el.focus()"
            autocomplete="off"
            placeholder="{{ $creating['type'] === 'dir' ? __('folder name') : __('file name') }}"
            class="w-full rounded border border-zinc-300 bg-white px-1 py-0.5 font-mono text-xs outline-none focus:border-zinc-500 dark:border-zinc-600 dark:bg-zinc-800"
        />
    </form>
@endif

@foreach ($entries as $entry)
    @php($path = ltrim($dir.'/'.$entry['name'], '/'))
    @if ($renaming === $path)
        <form wire:submit="rename" class="flex items-center gap-1 py-0.5 pr-2" style="padding-left: {{ $pad + 16 }}px">
            <input
                type="text"
                wire:model="name"
                wire:keydown.escape="cancel"
                x-init="$el.focus(); $el.select()"
                autocomplete="off"
                class="w-full rounded border border-zinc-300 bg-white px-1 py-0.5 font-mono text-xs outline-none focus:border-zinc-500 dark:border-zinc-600 dark:bg-zinc-800"
            />
        </form>
    @else
        <div
            class="group flex items-center gap-1 pr-1 hover:bg-zinc-100 dark:hover:bg-zinc-800"
            style="padding-left: {{ $pad }}px"
            draggable="true"
            x-on:dragstart="grab($event, @js($path))"
            @if ($entry['type'] === 'dir')
                :class="over === @js($path) && 'bg-sky-50 ring-1 ring-inset ring-sky-400 dark:bg-sky-950/40'"
                x-on:dragover.prevent.stop="hover($event, @js($path))"
                x-on:dragleave.stop="leave($event, @js($path))"
                x-on:drop.prevent.stop="drop($event, @js($path))"
            @endif
        >
            @if ($entry['type'] === 'dir')
                <button type="button" wire:click="toggle(@js($path))" class="flex min-w-0 flex-1 items-center gap-1 py-0.5 text-left">
                    <flux:icon.chevron-right class="size-3 shrink-0 text-zinc-400 transition {{ in_array($path, $expanded, true) ? 'rotate-90' : '' }}" />
                    <flux:icon.folder class="size-3.5 shrink-0 text-amber-500" />
                    <span class="truncate">{{ $entry['name'] }}</span>
                </button>
            @elseif ($entry['type'] === 'file')
                <button type="button" wire:click="open(@js($path))" class="flex min-w-0 flex-1 items-center gap-1 py-0.5 pl-4 text-left" :class="active === @js($path) && 'font-semibold text-zinc-900 dark:text-white'">
                    <flux:icon.document class="size-3.5 shrink-0 text-zinc-400" />
                    <span class="truncate">{{ $entry['name'] }}</span>
                </button>
            @else
                <span class="flex min-w-0 flex-1 items-center gap-1 py-0.5 pl-4 text-zinc-400" title="{{ __('Not a regular file') }}">
                    <flux:icon.link class="size-3.5 shrink-0" />
                    <span class="truncate">{{ $entry['name'] }}</span>
                </span>
            @endif
            <flux:dropdown position="bottom" align="end">
                <button type="button" class="touch-reveal rounded px-1 text-zinc-400 opacity-0 hover:text-zinc-700 group-hover:opacity-100 dark:hover:text-zinc-200" aria-label="{{ __('Actions') }}">…</button>
                <flux:menu class="font-sans">
                    @if ($entry['type'] === 'dir')
                        <flux:menu.item icon="document-plus" wire:click="startCreating(@js($path), 'file')">{{ __('New file') }}</flux:menu.item>
                        <flux:menu.item icon="folder-plus" wire:click="startCreating(@js($path), 'dir')">{{ __('New folder') }}</flux:menu.item>
                        <flux:menu.item icon="document-arrow-up" x-on:click="pick(@js($path), false)">{{ __('Upload files here') }}</flux:menu.item>
                        <flux:menu.item icon="folder-arrow-down" x-on:click="pick(@js($path), true)">{{ __('Upload a folder here') }}</flux:menu.item>
                        <flux:menu.separator />
                    @elseif ($entry['type'] === 'file')
                        <flux:menu.item icon="arrow-down-tray" :href="$this->downloadUrl($path)">{{ __('Download') }}</flux:menu.item>
                        @if (str_ends_with(strtolower($entry['name']), '.zip'))
                            <flux:menu.item icon="archive-box" wire:click="extract(@js($path))">{{ __('Extract here') }}</flux:menu.item>
                        @endif
                        <flux:menu.separator />
                    @endif
                    <flux:menu.item icon="pencil" wire:click="startRenaming(@js($path))">{{ __('Rename') }}</flux:menu.item>
                    <flux:menu.item icon="arrow-right-end-on-rectangle" wire:click="startMoving(@js($path))">{{ __('Move to…') }}</flux:menu.item>
                    <flux:menu.item icon="archive-box-arrow-down" wire:click="compress(@js($path))">{{ __('Compress to zip') }}</flux:menu.item>
                    <flux:menu.item icon="trash" variant="danger" wire:click="delete(@js($path))" wire:confirm="{{ __('Delete :name? This cannot be undone.', ['name' => $entry['name']]) }}">{{ __('Delete') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
        @if ($entry['type'] === 'dir' && in_array($path, $expanded, true))
            @include('livewire.files.tree', ['dir' => $path, 'depth' => $depth + 1])
        @endif
    @endif
@endforeach
