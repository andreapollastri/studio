<div class="flex flex-col gap-5" x-data="{ dragging: false }"
    @if ($canWrite)
        x-on:dragover.prevent="dragging = true"
        x-on:dragleave.prevent="if (! $el.contains($event.relatedTarget)) dragging = false"
        x-on:drop.prevent="dragging = false; $wire.uploadMultiple('uploads', [...$event.dataTransfer.files])"
    @endif
>
    <div class="flex flex-wrap items-start gap-3">
        <div class="min-w-0 flex-1">
            <flux:heading size="xl">{{ __('Drive') }}</flux:heading>
            <nav class="mt-1 flex flex-wrap items-center gap-1 text-sm text-zinc-500">
                <button type="button" wire:click="openFolder(null)" class="hover:text-zinc-900 dark:hover:text-white">{{ __('Drive') }}</button>
                @foreach ($trail as $crumb)
                    <flux:icon.chevron-right variant="micro" class="size-3.5" />
                    <button type="button" wire:click="openFolder({{ $crumb->id }})" @class(['hover:text-zinc-900 dark:hover:text-white', 'font-medium text-zinc-900 dark:text-white' => $loop->last])>{{ $crumb->name }}</button>
                @endforeach
                @if ($projectName)
                    <flux:badge size="sm" color="sky" icon="rectangle-stack" class="ms-2">{{ $projectName }}</flux:badge>
                @endif
            </nav>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($workspace)
                <flux:dropdown position="bottom" align="end">
                    <flux:button size="sm" icon="arrow-up-on-square-stack" icon:trailing="chevron-down">{{ __('To the project') }}</flux:button>
                    <flux:menu>
                        <flux:menu.item icon="document-duplicate" wire:click="startTransfer('copy')">{{ __('Copy into the workspace') }}</flux:menu.item>
                        @if ($canWrite)
                            <flux:menu.item icon="arrow-right-start-on-rectangle" wire:click="startTransfer('move')">{{ __('Move into the workspace') }}</flux:menu.item>
                        @endif
                    </flux:menu>
                </flux:dropdown>
            @endif
            @if ($canWrite)
                <flux:button size="sm" icon="folder-plus" wire:click="newFolder">{{ __('New folder') }}</flux:button>
                <label class="inline-flex">
                    <input type="file" multiple wire:model="uploads" class="hidden" />
                    <span class="inline-flex h-8 cursor-pointer items-center gap-2 rounded-lg bg-zinc-800 px-3 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                        <flux:icon.arrow-up-tray variant="micro" class="size-4" />{{ __('Upload') }}
                    </span>
                </label>
            @endif
        </div>
    </div>

    <div wire:loading.flex wire:target="uploads" class="items-center gap-2 text-sm text-zinc-500">
        <flux:icon.loading class="size-4" /> {{ __('Uploading…') }}
    </div>
    @error('uploads.*')<flux:text class="text-sm text-red-600">{{ $message }}</flux:text>@enderror

    @if (count($selectedFolders) + count($selectedFiles) > 0)
        <div class="flex flex-wrap items-center gap-2 rounded-lg bg-zinc-100 px-3 py-2 text-sm dark:bg-zinc-800">
            <span class="me-2">{{ trans_choice(':count selected|:count selected', count($selectedFolders) + count($selectedFiles), ['count' => count($selectedFolders) + count($selectedFiles)]) }}</span>
            @if ($workspace)
                <flux:button size="xs" icon="document-duplicate" wire:click="startTransfer('copy')">{{ __('Copy into the workspace') }}</flux:button>
                <flux:button size="xs" icon="arrow-right-start-on-rectangle" wire:click="startTransfer('move')">{{ __('Move into the workspace') }}</flux:button>
            @endif
            <flux:button size="xs" icon="folder-arrow-down" wire:click="startMove">{{ __('Move to folder') }}</flux:button>
            <flux:button size="xs" variant="danger" icon="trash" wire:click="deleteSelected" wire:confirm="{{ __('Remove the selected items? Folders go with everything inside them.') }}">{{ __('Remove') }}</flux:button>
            <flux:button size="xs" variant="ghost" wire:click="$set('selectedFolders', []); $set('selectedFiles', [])">{{ __('Clear') }}</flux:button>
        </div>
    @endif

    <div class="relative overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div x-show="dragging" class="absolute inset-0 z-10 flex items-center justify-center rounded-xl border-2 border-dashed border-sky-500 bg-sky-50/90 text-sm font-medium text-sky-700 dark:bg-sky-950/80 dark:text-sky-300" style="display: none">
            {{ __('Drop the files to upload them here') }}
        </div>
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800">
                <tr>
                    <th class="w-8 px-3 py-2"></th>
                    <th class="px-3 py-2 text-start">{{ __('Name') }}</th>
                    <th class="px-3 py-2 text-start">{{ __('Access') }}</th>
                    <th class="px-3 py-2 text-end">{{ __('Size') }}</th>
                    <th class="px-3 py-2 text-start">{{ __('Changed') }}</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->folders as $item)
                    <tr wire:key="drive-folder-{{ $item->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-3 py-2"><input type="checkbox" value="{{ $item->id }}" wire:model.live="selectedFolders" class="rounded" /></td>
                        <td class="px-3 py-2">
                            <button type="button" wire:click="openFolder({{ $item->id }})" class="flex items-center gap-2 font-medium">
                                <flux:icon.folder variant="solid" class="size-5 text-amber-400" />{{ $item->name }}
                            </button>
                            @if ($folder === null && $item->user_id !== auth()->id())
                                <div class="ps-7 text-xs text-zinc-500">{{ __('from :name', ['name' => $item->user->name]) }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2">
                            @if ($item->project_id)
                                <flux:badge size="sm" color="sky" icon="rectangle-stack">{{ $item->project?->name }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-3 py-2"></td>
                        <td class="px-3 py-2 text-xs text-zinc-500">{{ $item->updated_at?->diffForHumans() }}</td>
                        <td class="px-3 py-2 text-end whitespace-nowrap">
                            <flux:dropdown position="bottom" align="end">
                                <flux:button size="xs" variant="ghost" icon="ellipsis-vertical" />
                                <flux:menu>
                                    <flux:menu.item icon="folder-open" wire:click="openFolder({{ $item->id }})">{{ __('Open') }}</flux:menu.item>
                                    @if (in_array($item->id, $manageable, true))
                                        <flux:menu.item icon="share" wire:click="share({{ $item->id }})">{{ __('Project and access') }}</flux:menu.item>
                                    @endif
                                    @if (in_array($item->id, $writable, true))
                                        <flux:menu.item icon="pencil-square" wire:click="startRename('folder', {{ $item->id }})">{{ __('Rename') }}</flux:menu.item>
                                    @endif
                                </flux:menu>
                            </flux:dropdown>
                        </td>
                    </tr>
                @endforeach
                @foreach ($this->files as $file)
                    <tr wire:key="drive-file-{{ $file->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-3 py-2"><input type="checkbox" value="{{ $file->id }}" wire:model.live="selectedFiles" class="rounded" /></td>
                        <td class="px-3 py-2">
                            <a href="{{ route('drive.file', ['file' => $file, 'inline' => $file->isPreviewable() ? 1 : null]) }}" target="{{ $file->isPreviewable() ? '_blank' : '_self' }}" class="flex items-center gap-2">
                                <flux:icon :name="str_starts_with((string) $file->mime, 'image/') ? 'photo' : 'document'" class="size-5 text-zinc-400" />
                                <span>{{ $file->name }}</span>
                            </a>
                        </td>
                        <td class="px-3 py-2 text-xs text-zinc-500">{{ $file->user->name }}</td>
                        <td class="px-3 py-2 text-end text-xs tabular-nums text-zinc-500">{{ \Illuminate\Support\Number::fileSize($file->size, 1) }}</td>
                        <td class="px-3 py-2 text-xs text-zinc-500">{{ $file->updated_at?->diffForHumans() }}</td>
                        <td class="px-3 py-2 text-end whitespace-nowrap">
                            <flux:dropdown position="bottom" align="end">
                                <flux:button size="xs" variant="ghost" icon="ellipsis-vertical" />
                                <flux:menu>
                                    <flux:menu.item icon="arrow-down-tray" :href="route('drive.file', $file)">{{ __('Download') }}</flux:menu.item>
                                    @if ($canWrite)
                                        <flux:menu.item icon="pencil-square" wire:click="startRename('file', {{ $file->id }})">{{ __('Rename') }}</flux:menu.item>
                                    @endif
                                </flux:menu>
                            </flux:dropdown>
                        </td>
                    </tr>
                @endforeach
                @if ($this->folders->isEmpty() && $this->files->isEmpty())
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-zinc-500">
                            @if ($canWrite)
                                {{ $folder === null ? __('Your drive is empty. Create a folder, tie it to a project, and choose who reads or writes in it.') : __('This folder is empty. Upload files or drop them here.') }}
                            @else
                                {{ __('Nothing here yet.') }}
                            @endif
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
    @if ($canWrite)
        <flux:text class="text-xs">{{ __('Up to :mb MB per file.', ['mb' => $maxUploadMb]) }}</flux:text>
    @endif

    {{-- New folder --}}
    <flux:modal name="drive-folder" class="md:w-96">
        <form wire:submit="saveFolder" class="space-y-5">
            <flux:heading size="lg">{{ __('New folder') }}</flux:heading>
            <flux:input wire:model="folder_name" :label="__('Name')" required autofocus />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Rename --}}
    <flux:modal name="drive-rename" class="md:w-96">
        <form wire:submit="rename" class="space-y-5">
            <flux:heading size="lg">{{ __('Rename') }}</flux:heading>
            <flux:input wire:model="rename_to" :label="__('Name')" required autofocus />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Move within the drive --}}
    <flux:modal name="drive-move" class="md:w-[28rem]">
        <form wire:submit="moveSelected" class="space-y-5">
            <flux:heading size="lg">{{ __('Move to folder') }}</flux:heading>
            <flux:select wire:model="moveTarget" :label="__('Folder')" :placeholder="__('Choose…')">
                @foreach ($targets as $id => $path)
                    <flux:select.option :value="$id">{{ $path }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:text class="text-xs">{{ __('Items whose name is already taken there stay where they are.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Move') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Project and access --}}
    <flux:modal name="drive-sharing" class="md:w-[40rem]">
        @if ($sharingFolder)
            <div class="space-y-6" wire:key="drive-sharing-{{ $sharingFolder->id }}">
                <div>
                    <flux:heading size="lg">{{ __('Project and access: :name', ['name' => $sharingFolder->name]) }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Applies to everything inside, down to a folder tied to another project. Administrators always see everything. Changes apply at once.') }}</flux:text>
                </div>

                <div class="space-y-3">
                    <x-search-picker
                        wire:key="drive-project-picker-{{ $sharingFolder->id }}"
                        search="searchProjects"
                        select="pickShareProject"
                        :selected="$sharingFolder->project ? ['id' => $sharingFolder->project->id, 'label' => $sharingFolder->project->name] : null"
                        :placeholder="__('Personal: search a project to tie it to…')"
                    />
                    @if ($sharingFolder->project)
                        <div class="grid grid-cols-3 gap-3">
                            @foreach (['client' => __('Client'), 'pm' => __('PM'), 'dev' => __('Developer')] as $role => $label)
                                <flux:select size="sm" wire:model.live="share_access.{{ $role }}" :label="$label">
                                    <flux:select.option value="none">{{ __('no access') }}</flux:select.option>
                                    <flux:select.option value="read">{{ __('can view') }}</flux:select.option>
                                    <flux:select.option value="write">{{ __('can view and edit') }}</flux:select.option>
                                </flux:select>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800">
                            <tr>
                                <th class="px-3 py-2 text-start">{{ __('Who') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('Through') }}</th>
                                <th class="px-3 py-2 text-start">{{ __('Access') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($audience as $entry)
                                <tr wire:key="drive-audience-{{ $entry['user']->id }}">
                                    <td class="px-3 py-1.5">
                                        <div>{{ $entry['user']->name }}</div>
                                        <div class="text-xs text-zinc-500">{{ $entry['user']->role->label() }}</div>
                                    </td>
                                    <td class="px-3 py-1.5 text-xs text-zinc-500">{{ match ($entry['via']) { 'owner' => __('owner'), 'admin' => __('administrator'), default => __('project role') } }}</td>
                                    <td class="px-3 py-1.5 text-xs">{{ $entry['level'] === 'write' ? __('can view and edit') : __('can view') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-end">
                    <flux:modal.close><flux:button variant="primary">{{ __('Done') }}</flux:button></flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Copy / move into the workspace --}}
    <flux:modal name="drive-transfer" class="md:w-[34rem]">
        @if ($workspace)
            <form wire:submit="transfer" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ $transfer_mode === 'move' ? __('Move into the workspace') : __('Copy into the workspace') }}</flux:heading>
                    <flux:text class="mt-1">
                        @if (count($selectedFolders) + count($selectedFiles) > 0)
                            {{ trans_choice(':count selected item goes to your workspace of :project.|:count selected items go to your workspace of :project.', count($selectedFolders) + count($selectedFiles), ['count' => count($selectedFolders) + count($selectedFiles), 'project' => $projectName]) }}
                        @else
                            {{ __('The folder :name goes, as a whole, to your workspace of :project.', ['name' => $current?->name, 'project' => $projectName]) }}
                        @endif
                    </flux:text>
                </div>

                <flux:radio.group wire:model="transfer_mode" variant="segmented">
                    <flux:radio value="copy" :label="__('Copy')" icon="document-duplicate" />
                    @if ($canWrite)
                        <flux:radio value="move" :label="__('Move')" icon="arrow-right-start-on-rectangle" />
                    @endif
                </flux:radio.group>

                <div class="space-y-1">
                    <flux:label>{{ __('Into') }}</flux:label>
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <div class="flex flex-wrap items-center gap-1 border-b border-zinc-200 px-3 py-2 font-mono text-xs dark:border-zinc-700">
                            <button type="button" wire:click="browse('')" class="hover:underline">/</button>
                            @php($walked = '')
                            @foreach (array_filter(explode('/', $transfer_path)) as $segment)
                                @php($walked = ltrim($walked.'/'.$segment, '/'))
                                <button type="button" wire:click="browse(@js($walked))" class="hover:underline">{{ $segment }}</button><span>/</span>
                            @endforeach
                        </div>
                        <div class="max-h-56 overflow-y-auto py-1">
                            @forelse ($transfer_dirs as $dir)
                                <button type="button" wire:key="transfer-dir-{{ $dir['name'] }}" wire:click="browse(@js(ltrim($transfer_path.'/'.$dir['name'], '/')))" class="flex w-full items-center gap-2 px-3 py-1 text-start text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                    <flux:icon.folder variant="micro" class="size-4 text-amber-400" />{{ $dir['name'] }}
                                </button>
                            @empty
                                <div class="px-3 py-2 text-xs text-zinc-500">{{ __('No folders inside: the files go right here.') }}</div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <flux:checkbox wire:model="transfer_overwrite" :label="__('Replace files that already exist')" />

                @if ($transfer_error)
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.text>{{ $transfer_error }}</flux:callout.text>
                    </flux:callout>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="transfer">
                        {{ $transfer_mode === 'move' ? __('Move to /:path', ['path' => $transfer_path]) : __('Copy to /:path', ['path' => $transfer_path]) }}
                    </flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
