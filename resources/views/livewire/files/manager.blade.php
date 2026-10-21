{{--
    The file manager of a ManagesFiles component: the project's Files tab and the SSH page.
    $bar, when given, is a partial shown above it; $treeWidth sizes the tree.
--}}
<div
    class="flex h-full min-h-0 w-full flex-col"
    x-data="studioEditor(@js($this->editorScope()))"
    x-on:files-open="openFile($event.detail)"
    x-on:files-renamed="renamed($event.detail)"
    x-on:files-deleted="removed($event.detail)"
    x-on:files-reset="reset()"
>
    @isset($bar)
        @include($bar)
    @endisset
    <div class="studio-files flex min-h-0 w-full flex-1">
        <aside
            x-show="tree"
            x-data="studioFileDrop({{ $this::MAX_UPLOAD_FILES }}, {{ (int) config('studio.drive.max_upload_mb', 100) }})"
            class="studio-files-tree flex {{ $treeWidth ?? 'w-44' }} shrink-0 flex-col border-r border-zinc-200 dark:border-zinc-700"
        >
            <div class="flex items-center gap-0.5 border-b border-zinc-200 px-2 py-1 dark:border-zinc-700">
                <span class="min-w-0 flex-1 truncate text-[10px] font-semibold uppercase tracking-wide text-zinc-500">{{ __('Files') }}</span>
                <flux:dropdown position="bottom" align="end">
                    <flux:button size="xs" variant="ghost" icon="arrow-up-tray" :title="__('Upload')" />
                    <flux:menu class="font-sans">
                        <flux:menu.item icon="document-arrow-up" x-on:click="pick('', false)">{{ __('Upload files') }}</flux:menu.item>
                        <flux:menu.item icon="folder-arrow-down" x-on:click="pick('', true)">{{ __('Upload a folder') }}</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
                <flux:button size="xs" variant="ghost" icon="document-plus" wire:click="startCreating('', 'file')" :title="__('New file')" />
                <flux:button size="xs" variant="ghost" icon="folder-plus" wire:click="startCreating('', 'dir')" :title="__('New folder')" />
                <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="refresh" :title="__('Refresh')" />
            </div>
            <div x-show="busy" x-cloak class="border-b border-zinc-200 px-3 py-1.5 text-[11px] text-zinc-500 dark:border-zinc-700">
                <div class="flex justify-between gap-2"><span x-text="status"></span><span x-text="progress + '%'"></span></div>
                <div class="mt-1 h-1 overflow-hidden rounded bg-zinc-200 dark:bg-zinc-700"><div class="h-full bg-zinc-900 transition-all dark:bg-zinc-100" :style="'width: ' + progress + '%'"></div></div>
            </div>
            <div
                class="min-h-0 flex-1 overflow-auto py-1 font-mono text-xs"
                :class="over === '' && 'bg-sky-50 ring-1 ring-inset ring-sky-400 dark:bg-sky-950/40'"
                x-on:dragover.prevent="hover($event, '')"
                x-on:dragleave="leave($event, '')"
                x-on:drop.prevent="drop($event, '')"
            >
                @if ($error)
                    <div class="px-3 py-2 text-red-600">{{ $error }}</div>
                @endif
                <div x-show="problem" x-cloak class="px-3 py-2 text-red-600" x-text="problem"></div>
                @error('uploads.*')
                    <div class="px-3 py-2 text-red-600">{{ $message }}</div>
                @enderror
                @if ($notice)
                    <div class="flex items-start gap-1 px-3 py-2 text-emerald-700 dark:text-emerald-400">
                        <span class="min-w-0 flex-1">{{ $notice }}</span>
                        <button type="button" wire:click="$set('notice', null)" class="leading-none text-zinc-400 hover:text-zinc-700" aria-label="{{ __('Dismiss') }}">×</button>
                    </div>
                @endif
                @if ($clashes)
                    <div class="mx-2 my-1 rounded border border-amber-300 bg-amber-50 p-2 font-sans text-amber-800 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                        <p>{{ trans_choice(':count file is already there:|:count files are already there:', count($clashes), ['count' => count($clashes)]) }}
                            <span class="font-mono">{{ collect($clashes)->take(3)->pluck('path')->implode(', ') }}{{ count($clashes) > 3 ? '…' : '' }}</span></p>
                        <div class="mt-1.5 flex gap-1">
                            <flux:button size="xs" variant="primary" wire:click="replaceClashes">{{ __('Replace') }}</flux:button>
                            <flux:button size="xs" wire:click="skipClashes">{{ __('Keep the old') }}</flux:button>
                        </div>
                    </div>
                @endif
                @if ($unopenable)
                    <div class="px-3 py-2 text-amber-700 dark:text-amber-400">
                        {{ $unopenable['binary']
                            ? __(':path is a binary file (:size).', ['path' => $unopenable['path'], 'size' => \Illuminate\Support\Number::fileSize($unopenable['size'])])
                            : __(':path is larger than the editor limit (:size, limit :limit).', ['path' => $unopenable['path'], 'size' => \Illuminate\Support\Number::fileSize($unopenable['size']), 'limit' => \Illuminate\Support\Number::fileSize($unopenable['limit'])]) }}
                    </div>
                @endif
                @include('livewire.files.tree', ['dir' => '', 'depth' => 0])
            </div>
        </aside>

        {{-- Move to…: browse the folders and pick one --}}
        <flux:modal :name="$this->moveModal()" class="w-full max-w-sm">
            <form wire:submit="move" class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Move :name', ['name' => basename((string) $moving)]) }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Open the folder it goes into, then move it there.') }}</flux:text>
                </div>
                <nav class="flex flex-wrap items-center gap-1 font-mono text-xs text-zinc-500">
                    <button type="button" wire:click="browseMove('')" class="hover:text-zinc-900 dark:hover:text-white">{{ $this->rootLabel() }}</button>
                    @php($crumb = '')
                    @foreach (array_filter(explode('/', $moveTo)) as $segment)
                        @php($crumb = ltrim($crumb.'/'.$segment, '/'))
                        <span>/</span>
                        <button type="button" wire:click="browseMove(@js($crumb))" @class(['hover:text-zinc-900 dark:hover:text-white', 'font-semibold text-zinc-900 dark:text-white' => $loop->last])>{{ $segment }}</button>
                    @endforeach
                </nav>
                <div class="max-h-64 overflow-auto rounded border border-zinc-200 py-1 font-mono text-xs dark:border-zinc-700">
                    @if ($moveTo !== '')
                        <button type="button" wire:click="browseMove(@js(str_contains($moveTo, '/') ? substr($moveTo, 0, strrpos($moveTo, '/')) : ''))" class="flex w-full items-center gap-1.5 px-3 py-1 text-left text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                            <flux:icon.arrow-uturn-left class="size-3.5" /> ..
                        </button>
                    @endif
                    @forelse ($moveDirs as $folderPath)
                        <button type="button" wire:click="browseMove(@js($folderPath))" class="flex w-full items-center gap-1.5 px-3 py-1 text-left hover:bg-zinc-100 dark:hover:bg-zinc-800">
                            <flux:icon.folder class="size-3.5 shrink-0 text-amber-500" />
                            <span class="truncate">{{ basename($folderPath) }}</span>
                        </button>
                    @empty
                        <p class="px-3 py-1 text-zinc-400">{{ __('No folders in here.') }}</p>
                    @endforelse
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    @php($here = str_contains((string) $moving, '/') ? substr((string) $moving, 0, strrpos((string) $moving, '/')) : '')
                    <flux:button type="submit" variant="primary" icon="arrow-right-end-on-rectangle" :disabled="$moveTo === $here">{{ $moveTo === $here ? __('It is already here') : __('Move to /:path', ['path' => $moveTo]) }}</flux:button>
                </div>
            </form>
        </flux:modal>

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
</div>
