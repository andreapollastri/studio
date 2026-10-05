<div
    class="flex h-full min-h-0 w-full"
    x-data="studioEditor"
    x-on:files-open.window="openFile($event.detail)"
    x-on:files-renamed.window="renamed($event.detail)"
    x-on:files-deleted.window="removed($event.detail)"
>
    <aside x-show="tree" class="flex w-44 shrink-0 flex-col border-r border-zinc-200 dark:border-zinc-700">
        <div class="flex items-center gap-0.5 border-b border-zinc-200 px-2 py-1 dark:border-zinc-700">
            <span class="min-w-0 flex-1 truncate text-[10px] font-semibold uppercase tracking-wide text-zinc-500">{{ __('Files') }}</span>
            <flux:button size="xs" variant="ghost" icon="document-plus" wire:click="startCreating('', 'file')" :title="__('New file')" />
            <flux:button size="xs" variant="ghost" icon="folder-plus" wire:click="startCreating('', 'dir')" :title="__('New folder')" />
            <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="refresh" :title="__('Refresh')" />
        </div>
        <div class="min-h-0 flex-1 overflow-auto py-1 font-mono text-xs">
            @if ($error)
                <div class="px-3 py-2 text-red-600">{{ $error }}</div>
            @endif
            @if ($unopenable)
                <div class="px-3 py-2 text-amber-700 dark:text-amber-400">
                    {{ $unopenable['binary']
                        ? __(':path is a binary file (:size).', ['path' => $unopenable['path'], 'size' => \Illuminate\Support\Number::fileSize($unopenable['size'])])
                        : __(':path is larger than the editor limit (:size, limit :limit).', ['path' => $unopenable['path'], 'size' => \Illuminate\Support\Number::fileSize($unopenable['size']), 'limit' => \Illuminate\Support\Number::fileSize($unopenable['limit'])]) }}
                </div>
            @endif
            @include('livewire.studio.partials.file-tree', ['dir' => '', 'depth' => 0])
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <div class="flex h-9 shrink-0 items-stretch overflow-x-auto border-b border-zinc-200 bg-zinc-50 text-xs dark:border-zinc-700 dark:bg-zinc-900">
            <button type="button" class="shrink-0 border-r border-zinc-200 px-2 text-zinc-500 hover:text-zinc-900 dark:border-zinc-700 dark:hover:text-zinc-100" x-on:click="tree = !tree" :title="tree ? @js(__('Hide the file tree')) : @js(__('Show the file tree'))" :aria-pressed="tree">
                <flux:icon.bars-3 class="size-4" />
            </button>
            <template x-for="tab in tabs" :key="tab.path">
                <div
                    class="flex cursor-pointer items-center gap-1.5 border-r border-zinc-200 px-3 dark:border-zinc-700"
                    :class="tab.path === active ? 'bg-white text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200'"
                    :title="tab.path"
                    x-on:click="show(tab.path)"
                >
                    <span class="whitespace-nowrap" x-text="tab.path.split('/').pop()"></span>
                    <button type="button" class="rounded px-1 leading-none hover:bg-zinc-200 dark:hover:bg-zinc-700" x-on:click.stop="close(tab.path)" :aria-label="'Close ' + tab.path">
                        <span x-show="tab.dirty" class="text-amber-500">●</span><span x-show="!tab.dirty">×</span>
                    </button>
                </div>
            </template>
        </div>

        <div class="relative min-h-0 flex-1">
            <div wire:ignore x-ref="editor" class="absolute inset-0" x-show="tabs.length > 0"></div>
            <div x-show="tabs.length === 0" class="absolute inset-0 flex items-center justify-center p-6 text-center text-sm text-zinc-500">
                {{ __('Pick a file on the left. Changes save with ⌘S / Ctrl+S.') }}
            </div>
        </div>

        <div class="flex h-8 shrink-0 items-center gap-3 border-t border-zinc-200 px-3 text-[11px] text-zinc-500 dark:border-zinc-700">
            <span class="min-w-0 flex-1 truncate font-mono" x-text="active ?? ''"></span>
            <span x-show="message" x-text="message"></span>
            <span x-show="current()?.dirty" class="text-amber-600">{{ __('Unsaved') }}</span>
            <button
                type="button"
                class="rounded bg-zinc-900 px-2 py-0.5 text-white disabled:opacity-40 dark:bg-zinc-100 dark:text-zinc-900"
                x-on:click="save()"
                :disabled="!current() || saving"
                x-text="saving ? @js(__('Saving…')) : @js(__('Save'))"
            ></button>
        </div>
    </div>
</div>
