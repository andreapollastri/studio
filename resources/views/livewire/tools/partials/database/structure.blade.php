{{-- A table's columns, indexes and constraints; what changes them is SQL shown first. --}}
@use('Illuminate\Support\Js')
<div class="h-full space-y-5 overflow-y-auto pe-1">
    <section class="space-y-2">
        <div class="flex items-center gap-2">
            <flux:heading size="sm">{{ __('Columns') }}</flux:heading>
            <flux:badge size="sm">{{ count($columns) }}</flux:badge>
            @if ($writable && ($object['type'] ?? 'table') === 'table')
                <flux:button size="xs" icon="plus" class="ms-auto" wire:click="startAddColumn">{{ __('Add column') }}</flux:button>
            @endif
        </div>
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full text-xs">
                <thead class="bg-zinc-50 text-start text-[11px] uppercase tracking-wide text-zinc-500 dark:bg-zinc-900">
                    <tr>
                        <th class="px-3 py-2 text-start">#</th>
                        <th class="px-3 py-2 text-start">{{ __('Name') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('Type') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('Null') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('Default') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('Key') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('Extra') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('Comment') }}</th>
                        <th class="w-10"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($columns as $c)
                        <tr wire:key="col-{{ md5($c['name']) }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                            <td class="px-3 py-1.5 text-zinc-400">{{ $loop->iteration }}</td>
                            <td class="px-3 py-1.5 font-mono font-medium">{{ $c['name'] }}</td>
                            <td class="px-3 py-1.5 font-mono text-sky-700 dark:text-sky-300">{{ $c['type'] }}</td>
                            <td class="px-3 py-1.5">{{ $c['nullable'] ? __('yes') : '' }}</td>
                            <td class="max-w-48 truncate px-3 py-1.5 font-mono" title="{{ $c['default'] }}">{{ $c['default'] ?? '' }}</td>
                            <td class="px-3 py-1.5">
                                @if ($c['key'])
                                    <flux:badge size="sm" :color="$c['key'] === 'primary' ? 'amber' : ($c['key'] === 'unique' ? 'sky' : 'zinc')">{{ $c['key'] }}</flux:badge>
                                @endif
                            </td>
                            <td class="px-3 py-1.5 text-zinc-500">{{ $c['extra'] }}</td>
                            <td class="max-w-48 truncate px-3 py-1.5 text-zinc-500" title="{{ $c['comment'] }}">{{ $c['comment'] }}</td>
                            <td class="px-1 py-1">
                                @if ($writable && ($object['type'] ?? 'table') === 'table')
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="xs" variant="ghost" icon="ellipsis-vertical" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil" x-on:click="let n = await studioDialog.prompt({ title: {{ Js::from(__('Rename the column :name', ['name' => $c['name']])) }}, label: {{ Js::from(__('New name')) }}, value: {{ Js::from($c['name']) }}, confirm: {{ Js::from(__('Continue')) }} }); if (n) $wire.reviewRenameColumn({{ Js::from($c['name']) }}, n)">{{ __('Rename…') }}</flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger" wire:click="reviewDropColumn({{ Js::from($c['name']) }})">{{ __('Drop…') }}</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @if (($object['type'] ?? 'table') === 'table')
        <section class="space-y-2">
            <div class="flex items-center gap-2">
                <flux:heading size="sm">{{ __('Indexes') }}</flux:heading>
                <flux:badge size="sm">{{ count($indexes) }}</flux:badge>
                @if ($writable)
                    <flux:button size="xs" icon="plus" class="ms-auto" wire:click="startAddIndex">{{ __('Add index') }}</flux:button>
                @endif
            </div>
            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="min-w-full text-xs">
                    <thead class="bg-zinc-50 text-[11px] uppercase tracking-wide text-zinc-500 dark:bg-zinc-900">
                        <tr>
                            <th class="px-3 py-2 text-start">{{ __('Name') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('Columns') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('Kind') }}</th>
                            <th class="px-3 py-2 text-start">{{ __('Definition') }}</th>
                            <th class="w-10"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($indexes as $index)
                            <tr wire:key="idx-{{ md5($index['name']) }}">
                                <td class="px-3 py-1.5 font-mono">{{ $index['name'] }}</td>
                                <td class="px-3 py-1.5 font-mono">{{ $index['columns'] }}</td>
                                <td class="px-3 py-1.5">
                                    <flux:badge size="sm" :color="$index['primary'] ? 'amber' : ($index['unique'] ? 'sky' : 'zinc')">{{ $index['primary'] ? __('primary') : ($index['unique'] ? __('unique') : __('index')) }}</flux:badge>
                                </td>
                                <td class="max-w-md truncate px-3 py-1.5 font-mono text-zinc-500" title="{{ $index['definition'] }}">{{ $index['definition'] }}</td>
                                <td class="px-1 py-1">
                                    @if ($writable && ! ($index['primary'] && $family === 'sqlite'))
                                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="reviewDropIndex({{ Js::from($index['name']) }})" />
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-3 text-zinc-500">{{ __('No indexes.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-2">
            <div class="flex items-center gap-2">
                <flux:heading size="sm">{{ __('Constraints and foreign keys') }}</flux:heading>
                <flux:badge size="sm">{{ count($constraints) }}</flux:badge>
            </div>
            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="min-w-full text-xs">
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($constraints as $constraint)
                            <tr wire:key="con-{{ md5($constraint['name']) }}">
                                <td class="px-3 py-1.5 font-mono">{{ $constraint['name'] }}</td>
                                <td class="px-3 py-1.5"><flux:badge size="sm">{{ $constraint['type'] }}</flux:badge></td>
                                <td class="px-3 py-1.5 font-mono text-zinc-600 dark:text-zinc-300">{{ $constraint['definition'] }}</td>
                            </tr>
                        @empty
                            <tr><td class="px-3 py-3 text-zinc-500">{{ __('No foreign keys or checks.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
