{{-- A table's rows: filters, order, pages, a cell edited in place (double click), rows added, changed, copied, deleted. --}}
@php
    use App\Databases\Values;
    use App\Livewire\Tools\DatabaseClient;
    use Illuminate\Support\Js;

    $result = $grid['result'];
    $keys = $grid['keys'];
    $locator = $grid['locator'];
    $index = array_flip($result->columns);
    $shownColumns = array_values(array_filter($result->columns, fn ($c) => $c !== '__studio_row'));
    $types = collect($columns)->pluck('type', 'name');
    $editable = $writable && ($keys !== [] || $locator !== null);
    $pages = $count === null ? 1 : max(1, (int) ceil(min($count, 100000) / $perPage));
@endphp

<div
    class="flex h-full flex-col gap-2"
    x-data="{
        selected: [],
        editor: null,
        editable: {{ Js::from($editable) }},
        reason: {{ Js::from(! $canWrite ? __('You may only read this connection.') : ($locked ? __('Production: unlock changes first (the lock at the top).') : __('This table has no primary key: its rows cannot be told apart. Use the SQL editor.'))) }},
        async edit(event) {
            const cell = event.target.closest('td[data-col]');
            if (! cell) return;
            if (! this.editable) { $flux.toast({ variant: 'warning', text: this.reason }); return; }
            const row = cell.parentElement.dataset.row;
            const value = await $wire.cellValue(row, cell.dataset.col);
            if (! value) return;
            const box = cell.getBoundingClientRect(), frame = this.$refs.frame.getBoundingClientRect();
            this.editor = { row, column: cell.dataset.col, value: value.value ?? '', isNull: value.value === null, binary: value.binary,
                top: box.top - frame.top + this.$refs.frame.scrollTop, left: Math.min(box.left - frame.left + this.$refs.frame.scrollLeft, this.$refs.frame.scrollWidth - 340), width: Math.max(box.width, 320) };
            this.$nextTick(() => this.$refs.cell?.focus());
        },
        async save() {
            if (! this.editor || this.editor.binary) return;
            await $wire.saveCell(this.editor.row, this.editor.column, this.editor.isNull ? null : this.editor.value);
            this.editor = null;
        },
        toggleAll(event) { this.selected = event.target.checked ? [...this.$el.querySelectorAll('tr[data-row]')].map(r => r.dataset.row) : []; },
    }"
    x-on:keydown.escape.window="editor = null"
>
    {{-- Filters and the toolbar --}}
    <div class="flex shrink-0 flex-col gap-2">
        @foreach ($filters as $i => $filter)
            <div class="flex flex-wrap items-center gap-2" wire:key="filter-{{ $i }}">
                <flux:select size="sm" wire:model.live="filters.{{ $i }}.column" class="max-w-48">
                    @foreach ($columns as $c)
                        <flux:select.option :value="$c['name']">{{ $c['name'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select size="sm" wire:model.live="filters.{{ $i }}.op" class="max-w-40">
                    @foreach ($operators as $op)
                        <flux:select.option :value="$op">{{ $op === 'sql' ? __('raw SQL') : $op }}</flux:select.option>
                    @endforeach
                </flux:select>
                @unless (in_array($filter['op'], ['null', 'not null', 'empty'], true))
                    <flux:input size="sm" wire:model.live.debounce.500ms="filters.{{ $i }}.value" class="min-w-40 flex-1 font-mono" :placeholder="$filter['op'] === 'sql' ? 'price > 10 AND status = \'paid\'' : ($filter['op'] === 'in' ? 'a, b, c' : __('value'))" />
                @endunless
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeFilter({{ $i }})" />
            </div>
        @endforeach

        <div class="flex flex-wrap items-center gap-2">
            <flux:button size="sm" icon="funnel" wire:click="addFilter">{{ __('Filter') }}</flux:button>
            @if ($writable)
                <flux:button size="sm" icon="plus" wire:click="newRow">{{ __('Row') }}</flux:button>
            @endif
            <template x-if="selected.length > 0">
                <flux:button size="sm" variant="danger" icon="trash" x-on:click="if (await studioDialog.confirm({ title: {{ Js::from(__('Delete the selected rows?')) }}, body: selected.length + ' ' + {{ Js::from(__('rows, for good: a delete is not undone.')) }}, confirm: {{ Js::from(__('Delete')) }}, danger: true })) { await $wire.deleteRows(selected); selected = [] }">
                    <span x-text="{{ Js::from(__('Delete')) }} + ' ' + selected.length"></span>
                </flux:button>
            </template>
            <flux:dropdown>
                <flux:button size="sm" icon="arrow-up-tray" icon:trailing="chevron-down">{{ __('Export') }}</flux:button>
                <flux:menu>
                    <flux:menu.item wire:click="exportTable({{ Js::from($table) }}, 'csv')">CSV</flux:menu.item>
                    <flux:menu.item wire:click="exportTable({{ Js::from($table) }}, 'json')">JSON</flux:menu.item>
                    <flux:menu.item wire:click="exportTable({{ Js::from($table) }}, 'sql')">SQL</flux:menu.item>
                </flux:menu>
            </flux:dropdown>

            <div class="ms-auto flex items-center gap-2 text-xs text-zinc-500">
                <span>
                    @if ($count === null)
                        —
                    @elseif ($count > 100000)
                        {{ __('more than :count rows', ['count' => '100,000']) }}
                    @else
                        {{ trans_choice(':count row|:count rows', $count, ['count' => number_format($count)]) }}
                    @endif
                </span>
                <flux:select size="sm" wire:model.live="perPage" class="w-20">
                    @foreach ([50, 100, 250, 500, 1000] as $n)
                        <flux:select.option :value="$n">{{ $n }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="goToPage({{ $page - 1 }})" :disabled="$page <= 1" />
                <span class="tabular-nums">{{ $page }} / {{ $pages }}</span>
                <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="goToPage({{ $page + 1 }})" :disabled="$page >= $pages" />
            </div>
        </div>
    </div>

    {{-- The rows --}}
    <div x-ref="frame" class="relative min-h-64 flex-1 overflow-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="min-w-full border-separate border-spacing-0 text-xs">
            <thead class="sticky top-0 z-10 bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th class="w-8 border-b border-zinc-200 px-2 py-1.5 dark:border-zinc-700">
                        @if ($editable)
                            <input type="checkbox" class="align-middle" x-on:change="toggleAll($event)" x-bind:checked="selected.length > 0" />
                        @endif
                    </th>
                    <th class="w-8 border-b border-zinc-200 dark:border-zinc-700"></th>
                    @foreach ($shownColumns as $name)
                        <th class="whitespace-nowrap border-b border-s border-zinc-200 px-2 py-1.5 text-start font-medium dark:border-zinc-700" wire:key="th-{{ md5($name) }}">
                            <button type="button" class="flex cursor-pointer items-center gap-1" wire:click="sortBy({{ Js::from($name) }})" title="{{ $types[$name] ?? '' }}">
                                @if (in_array($name, $keys, true))
                                    <flux:icon.key variant="micro" class="size-3 text-amber-500" />
                                @endif
                                <span>{{ $name }}</span>
                                <span class="font-normal text-zinc-400">{{ \Illuminate\Support\Str::limit((string) ($types[$name] ?? ''), 18) }}</span>
                                @if ($sort === $name)
                                    <flux:icon :name="$direction === 'asc' ? 'chevron-up' : 'chevron-down'" variant="micro" class="size-3" />
                                @endif
                            </button>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody x-on:dblclick="edit($event)" class="font-mono">
                @forelse ($result->rows as $r => $row)
                    @php
                        $identity = $keys !== [] ? collect($keys)->mapWithKeys(fn ($k) => [$k => $row[$index[$k]] ?? null])->all() : ($locator !== null ? ['__studio_row' => $row[$index['__studio_row']] ?? null] : []);
                        $rowKey = $identity === [] ? 'r'.$r : DatabaseClient::rowKey($identity);
                    @endphp
                    <tr data-row="{{ $rowKey }}" wire:key="row-{{ $page }}-{{ $r }}-{{ md5($rowKey) }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                        <td class="border-b border-zinc-100 px-2 py-1 dark:border-zinc-800">
                            @if ($editable)
                                <input type="checkbox" class="align-middle" value="{{ $rowKey }}" x-model="selected" />
                            @endif
                        </td>
                        <td class="border-b border-zinc-100 px-0.5 dark:border-zinc-800">
                            @if ($identity !== [])
                                <flux:dropdown position="bottom" align="start">
                                    <button type="button" class="rounded p-0.5 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"><flux:icon.ellipsis-vertical variant="micro" class="size-3.5" /></button>
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="editRow({{ Js::from($rowKey) }})">{{ $editable ? __('Edit row') : __('View row') }}</flux:menu.item>
                                        @if ($writable)
                                            <flux:menu.item icon="document-duplicate" wire:click="editRow({{ Js::from($rowKey) }}, true)">{{ __('Duplicate') }}</flux:menu.item>
                                        @endif
                                        @if ($editable)
                                            <flux:menu.separator />
                                            <flux:menu.item icon="trash" variant="danger" wire:click="deleteRows([{{ Js::from($rowKey) }}])" wire:confirm="{{ __('Delete this row?') }}">{{ __('Delete') }}</flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </td>
                        @foreach ($shownColumns as $name)
                            @php($value = Values::display($row[$index[$name]] ?? null, 200))
                            <td data-col="{{ $name }}" class="max-w-xs cursor-default truncate whitespace-nowrap border-b border-s border-zinc-100 px-2 py-1 dark:border-zinc-800">
                                @if ($value === null)
                                    <span class="italic text-zinc-400">NULL</span>
                                @elseif (is_bool($value))
                                    {{ $value ? 'true' : 'false' }}
                                @elseif ($value === '')
                                    <span class="text-zinc-300 dark:text-zinc-600">''</span>
                                @else
                                    {{ $value }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($shownColumns) + 2 }}" class="px-3 py-6 text-center font-sans text-sm text-zinc-500">{{ $filters !== [] ? __('No row matches the filters.') : __('This table is empty.') }}</td></tr>
                @endforelse
            </tbody>
        </table>

        {{-- The cell being edited --}}
        <template x-if="editor">
            <div class="absolute z-20 rounded-lg border border-zinc-300 bg-white p-2 shadow-xl dark:border-zinc-600 dark:bg-zinc-800" x-bind:style="`top:${editor.top}px;left:${Math.max(0, editor.left)}px;width:${editor.width}px`" x-on:click.outside="editor = null">
                <div class="mb-1 flex items-center gap-2 text-[11px] text-zinc-500"><span class="font-mono" x-text="editor.column"></span></div>
                <template x-if="editor.binary">
                    <div class="text-xs text-zinc-500">{{ __('A binary value: change it with the SQL editor.') }}</div>
                </template>
                <template x-if="! editor.binary">
                    <div>
                        <textarea x-ref="cell" x-model="editor.value" x-bind:disabled="editor.isNull" rows="3" class="w-full rounded border border-zinc-200 bg-white p-1.5 font-mono text-xs outline-none focus:border-zinc-400 disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-900"
                            x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); save() }"></textarea>
                        <div class="mt-1 flex items-center gap-2">
                            <label class="flex items-center gap-1 text-xs"><input type="checkbox" x-model="editor.isNull" /> NULL</label>
                            <span class="text-[10px] text-zinc-400">{{ __('Enter saves, Shift+Enter a new line, Esc cancels') }}</span>
                            <flux:button size="xs" variant="primary" class="ms-auto" x-on:click="save()">{{ __('Save') }}</flux:button>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>

    @if ($keys === [] && $locator === null)
        <flux:text class="shrink-0 text-xs">{{ __('No primary key: rows can be read here and changed with the SQL editor.') }}</flux:text>
    @elseif ($editable)
        <flux:text class="shrink-0 text-xs">{{ __('Double-click a cell to change it.') }}</flux:text>
    @endif
</div>
