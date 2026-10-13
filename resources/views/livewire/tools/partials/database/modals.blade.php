@use('Illuminate\Support\Js')
@php($columnTypes = collect($columns)->pluck('type', 'name'))

<datalist id="db-types">
    @foreach ($types as $type)
        <option value="{{ $type }}"></option>
    @endforeach
</datalist>

{{-- A row: added, changed, or copied --}}
<flux:modal name="db-row" class="w-full md:w-[40rem]">
    <form wire:submit="saveRow" class="space-y-4">
        <flux:heading size="lg">{{ $rowIdentity ? ($writable ? __('Edit row') : __('Row')) : __('New row') }} <span class="font-mono text-sm text-zinc-500">{{ $table }}</span></flux:heading>
        <div class="max-h-[60vh] space-y-3 overflow-y-auto pe-1">
            @foreach ($rowValues as $name => $value)
                <div wire:key="rowfield-{{ md5($name) }}" class="grid gap-2 sm:grid-cols-[10rem_1fr] sm:items-start">
                    <div class="pt-1.5">
                        <div class="truncate font-mono text-xs font-medium" title="{{ $name }}">{{ $name }}</div>
                        <div class="truncate font-mono text-[10px] text-zinc-500">{{ $columnTypes[$name] ?? '' }}</div>
                    </div>
                    <div class="space-y-1">
                        @if (! $rowIdentity && ($rowDefaults[$name] ?? false))
                            <div class="flex items-center gap-3">
                                <flux:checkbox wire:model.live="rowDefaults.{{ $name }}" :label="__('Default')" />
                            </div>
                        @else
                            @if (mb_strlen((string) $value) > 80 || str_contains((string) $value, "\n"))
                                <flux:textarea wire:model="rowValues.{{ $name }}" rows="3" class="font-mono text-xs" :disabled="($rowNulls[$name] ?? false) || ! $writable" />
                            @else
                                <flux:input wire:model="rowValues.{{ $name }}" size="sm" class="font-mono" :disabled="($rowNulls[$name] ?? false) || ! $writable" />
                            @endif
                            <div class="flex gap-4">
                                <flux:checkbox wire:model.live="rowNulls.{{ $name }}" label="NULL" :disabled="! $writable" />
                                @if (! $rowIdentity && array_key_exists($name, $rowDefaults))
                                    <flux:checkbox wire:model.live="rowDefaults.{{ $name }}" :label="__('Default')" />
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">{{ $writable ? __('Cancel') : __('Close') }}</flux:button></flux:modal.close>
            @if ($writable)
                <flux:button type="submit" variant="primary">{{ $rowIdentity ? __('Save') : __('Add') }}</flux:button>
            @endif
        </div>
    </form>
</flux:modal>

{{-- SQL to read before it runs --}}
<flux:modal name="db-review" class="w-full md:w-[44rem]">
    <form wire:submit="applyReview" class="space-y-4">
        <div>
            <flux:heading size="lg">{{ $reviewTitle }}</flux:heading>
            <flux:text class="mt-1">{{ __('This is what runs on :name. Change it if you need to.', ['name' => $connection->name]) }}</flux:text>
        </div>
        @if ($connection->environment->isProduction())
            <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('Production database')" />
        @endif
        <flux:textarea wire:model="reviewSql" rows="10" class="font-mono text-xs" spellcheck="false" />
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
            <flux:button type="submit" :variant="preg_match('/^\s*(DROP|TRUNCATE|DELETE)/i', $reviewSql) ? 'danger' : 'primary'" icon="play">{{ __('Run') }}</flux:button>
        </div>
    </form>
</flux:modal>

{{-- A new table --}}
<flux:modal name="db-new-table" class="w-full md:w-[52rem]">
    <form wire:submit="reviewNewTable" class="space-y-4">
        <flux:heading size="lg">{{ __('New table') }}</flux:heading>
        <flux:input wire:model="newTableName" :label="__('Name')" class="font-mono" required />
        <div class="space-y-2">
            <div class="grid grid-cols-[1fr_1fr_8rem_auto_auto_auto_auto] items-center gap-2 text-[11px] font-medium uppercase tracking-wide text-zinc-500 max-md:hidden">
                <span>{{ __('Column') }}</span><span>{{ __('Type') }}</span><span>{{ __('Default') }}</span><span>{{ __('Null') }}</span><span>{{ __('Key') }}</span><span>{{ __('Auto') }}</span><span></span>
            </div>
            @foreach ($newColumns as $i => $c)
                <div wire:key="newcol-{{ $i }}" class="grid grid-cols-2 items-center gap-2 md:grid-cols-[1fr_1fr_8rem_auto_auto_auto_auto]">
                    <flux:input size="sm" wire:model="newColumns.{{ $i }}.name" class="font-mono" :placeholder="__('name')" />
                    <flux:input size="sm" wire:model="newColumns.{{ $i }}.type" class="font-mono" list="db-types" />
                    <flux:input size="sm" wire:model="newColumns.{{ $i }}.default" class="font-mono" :placeholder="__('none')" />
                    <flux:checkbox wire:model="newColumns.{{ $i }}.nullable" />
                    <flux:checkbox wire:model="newColumns.{{ $i }}.primary" />
                    <flux:checkbox wire:model="newColumns.{{ $i }}.increments" />
                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeNewColumn({{ $i }})" />
                </div>
            @endforeach
            <flux:error name="newColumns" />
            <flux:button size="sm" icon="plus" wire:click="addNewColumn">{{ __('Column') }}</flux:button>
        </div>
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
            <flux:button type="submit" variant="primary">{{ __('Review SQL') }}</flux:button>
        </div>
    </form>
</flux:modal>

{{-- A new column --}}
<flux:modal name="db-column" class="md:w-[32rem]">
    <form wire:submit="reviewAddColumn" class="space-y-4">
        <flux:heading size="lg">{{ __('Add a column to :table', ['table' => $table]) }}</flux:heading>
        <flux:input wire:model="column.name" :label="__('Name')" class="font-mono" required />
        <flux:input wire:model="column.type" :label="__('Type')" class="font-mono" list="db-types" required />
        <flux:input wire:model="column.default" :label="__('Default')" class="font-mono" :placeholder="__('none')" />
        <flux:checkbox wire:model="column.nullable" :label="__('May be NULL')" />
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
            <flux:button type="submit" variant="primary">{{ __('Review SQL') }}</flux:button>
        </div>
    </form>
</flux:modal>

{{-- A new index --}}
<flux:modal name="db-index" class="md:w-[32rem]">
    <form wire:submit="reviewAddIndex" class="space-y-4">
        <flux:heading size="lg">{{ __('New index on :table', ['table' => $table]) }}</flux:heading>
        <flux:field>
            <flux:label>{{ __('Columns, in order') }}</flux:label>
            <div class="max-h-48 space-y-1 overflow-y-auto rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">
                @foreach ($columns as $c)
                    <label class="flex items-center gap-2 text-xs" wire:key="idxcol-{{ md5($c['name']) }}">
                        <input type="checkbox" wire:model.live="indexColumns" value="{{ $c['name'] }}" />
                        <span class="font-mono">{{ $c['name'] }}</span>
                        @if (($n = array_search($c['name'], $indexColumns, true)) !== false)
                            <flux:badge size="sm">{{ $n + 1 }}</flux:badge>
                        @endif
                    </label>
                @endforeach
            </div>
            <flux:error name="indexColumns" />
        </flux:field>
        <flux:input wire:model="indexName" :label="__('Name')" class="font-mono" :placeholder="__('made from the columns')" />
        <flux:checkbox wire:model="indexUnique" :label="__('Unique')" />
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
            <flux:button type="submit" variant="primary">{{ __('Review SQL') }}</flux:button>
        </div>
    </form>
</flux:modal>

{{-- Save the editor's query --}}
<flux:modal name="db-save-query" class="md:w-[28rem]">
    <form wire:submit="saveQuery" class="space-y-4">
        <flux:heading size="lg">{{ __('Save the query') }}</flux:heading>
        <flux:input wire:model="saveTitle" :label="__('Title')" :placeholder="__('Orders of the last week')" required />
        <flux:error name="sql" />
        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
        </div>
    </form>
</flux:modal>
