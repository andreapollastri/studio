{{-- One level of the folder tree of the password manager; it includes itself for the next. --}}
@foreach ($tree->get($parent, collect()) as $node)
    <div wire:key="vault-folder-{{ $node->uuid }}">
        <div class="group flex items-center rounded-md hover:bg-zinc-100 dark:hover:bg-zinc-800" style="padding-inline-start: {{ $depth * 0.75 }}rem">
            <button type="button" wire:click="show('folder', '{{ $node->uuid }}')" @class([
                'flex min-w-0 flex-1 items-center gap-2 px-2 py-1 text-start text-sm',
                'font-medium text-zinc-900 dark:text-white' => $view === 'folder' && $folder === $node->uuid,
                'text-zinc-600 dark:text-zinc-300' => ! ($view === 'folder' && $folder === $node->uuid),
            ])>
                <flux:icon.folder variant="micro" class="size-4 shrink-0 text-zinc-400" />
                <span class="truncate">{{ $node->name }}</span>
            </button>
            <flux:dropdown position="bottom" align="end">
                <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" class="touch-reveal opacity-0 group-hover:opacity-100" />
                <flux:menu>
                    <flux:menu.item icon="folder-plus" wire:click="newFolder('{{ $node->uuid }}')">{{ __('New folder inside') }}</flux:menu.item>
                    <flux:menu.item icon="pencil-square" wire:click="renameFolder('{{ $node->uuid }}')">{{ __('Rename') }}</flux:menu.item>
                    <flux:menu.separator />
                    <flux:menu.item icon="trash" variant="danger" wire:click="deleteFolder('{{ $node->uuid }}')" wire:confirm="{{ __('Remove the folder :name and the folders inside it? Their items move to the top level.', ['name' => $node->name]) }}">{{ __('Remove') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
        @include('livewire.tools.partials.vault-folders', ['parent' => $node->id, 'depth' => $depth + 1])
    </div>
@endforeach
