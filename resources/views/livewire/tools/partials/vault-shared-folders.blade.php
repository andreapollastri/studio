{{-- One level of the folders shared with this person; it includes itself for the next. --}}
@foreach ($tree->get($parent, collect()) as $node)
    <div wire:key="vault-shared-folder-{{ $node->uuid }}">
        <button type="button" wire:click="show('sharedfolder', '{{ $node->uuid }}')" style="padding-inline-start: {{ 0.5 + $depth * 0.75 }}rem" @class([
            'flex w-full min-w-0 items-center gap-2 rounded-md py-1 pe-2 text-start text-sm',
            'bg-zinc-100 font-medium text-zinc-900 dark:bg-zinc-800 dark:text-white' => $view === 'sharedfolder' && $folder === $node->uuid,
            'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => ! ($view === 'sharedfolder' && $folder === $node->uuid),
        ])>
            <flux:icon.folder variant="micro" class="size-4 shrink-0 text-amber-500" />
            <span class="min-w-0 flex-1 truncate">{{ $node->name }}</span>
            @if ($depth === 0)
                <span class="shrink-0 text-xs text-zinc-400">{{ $node->user->handle ?: \Illuminate\Support\Str::before($node->user->name, ' ') }}</span>
            @endif
            @if ($this->access->canWrite($node->id))
                <flux:icon.pencil variant="micro" class="size-3 shrink-0 text-zinc-400" :title="__('You can edit')" />
            @endif
        </button>
        @include('livewire.tools.partials.vault-shared-folders', ['tree' => $tree, 'parent' => $node->id, 'depth' => $depth + 1])
    </div>
@endforeach
