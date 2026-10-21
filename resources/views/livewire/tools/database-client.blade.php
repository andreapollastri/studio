@php
    use App\Databases\Values;
    use Illuminate\Support\Js;
    $family = $this->family();
    $writable = $canWrite && ! $locked;
@endphp

{{-- In the Db tab ($embedded) the layout follows the pane's width, not the window's. --}}
<div @class(['flex flex-col gap-3', 'min-h-[36rem] lg:-m-2 lg:h-[calc(100dvh-4rem)]' => ! $embedded, '@container h-full p-3' => $embedded])>
    @include('livewire.tools.partials.database.header')

    @if ($error)
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('The database did not answer')">
            <flux:callout.text class="font-mono text-xs">{{ $error }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" wire:click="refresh">{{ __('Try again') }}</flux:button>
                @if ($connection->isOwnedBy(auth()->user()) && $connection->managed === null)
                    <flux:button size="sm" variant="ghost" :href="route('databases')" wire:navigate>{{ __('Edit the connection') }}</flux:button>
                @endif
            </x-slot>
        </flux:callout>
    @endif

    <div @class(['flex min-h-0 flex-1 gap-3', 'max-lg:flex-col' => ! $embedded, 'flex-col @3xl:flex-row' => $embedded])>
        {{-- Databases, schemas, tables and views --}}
        <aside @class(['flex shrink-0 flex-col gap-2', 'lg:w-64 lg:min-h-0' => ! $embedded, '@3xl:w-56 @3xl:min-h-0' => $embedded])>
            @if ($family !== 'sqlite')
                <div class="flex items-center gap-1">
                    <flux:dropdown class="min-w-0 flex-1">
                        <flux:button size="sm" icon="circle-stack" icon:trailing="chevron-up-down" class="w-full justify-start! truncate">
                            <span class="truncate">{{ $db ?? __('Choose a database') }}</span>
                        </flux:button>
                        <flux:menu class="max-h-96 overflow-y-auto">
                            @forelse ($databases as $name)
                                <flux:menu.item wire:key="dbsel-{{ md5($name) }}" wire:click="useDatabase({{ Js::from($name) }})" :icon="$name === $db ? 'check' : null">{{ $name }}</flux:menu.item>
                            @empty
                                <flux:menu.item disabled>{{ __('No databases') }}</flux:menu.item>
                            @endforelse
                            @if ($writable)
                                <flux:menu.separator />
                                <flux:menu.item icon="plus" x-on:click="let n = await studioDialog.prompt({ title: {{ Js::from(__('New database')) }}, label: {{ Js::from(__('Name')) }}, confirm: {{ Js::from(__('Continue')) }} }); if (n) $wire.reviewNewDatabase(n)">{{ __('New database…') }}</flux:menu.item>
                                @if ($db)
                                    <flux:menu.item icon="trash" variant="danger" wire:click="reviewDropDatabase({{ Js::from($db) }})">{{ __('Drop :name…', ['name' => $db]) }}</flux:menu.item>
                                @endif
                            @endif
                        </flux:menu>
                    </flux:dropdown>
                </div>
                @if ($schemas !== [])
                    <flux:select size="sm" wire:change="useSchema($event.target.value)">
                        @foreach ($schemas as $name)
                            <flux:select.option :value="$name" :selected="$name === $schema">{{ __('schema') }}: {{ $name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            @endif

            <div class="flex items-center gap-1">
                <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.150ms="objectFilter" :placeholder="__('Filter tables…')" clearable class="flex-1" />
                @if ($writable && ($family === 'sqlite' || $db))
                    <flux:button size="sm" variant="subtle" icon="plus" wire:click="startNewTable" :tooltip="__('New table')" />
                @endif
            </div>

            <nav @class(['min-h-0 flex-1 overflow-y-auto rounded-lg border border-zinc-200 p-1 dark:border-zinc-700', 'max-lg:max-h-64' => ! $embedded, 'max-h-48 @3xl:max-h-none' => $embedded])>
                @php($needle = mb_strtolower(trim($objectFilter)))
                @php($shown = collect($objects)->filter(fn ($o) => $needle === '' || str_contains(mb_strtolower($o['name']), $needle)))
                @foreach (['table' => __('Tables'), 'view' => __('Views')] as $type => $label)
                    @php($list = $shown->where('type', $type))
                    @continue($list->isEmpty())
                    <div class="px-2 pb-1 pt-2 text-[11px] font-medium uppercase tracking-wide text-zinc-500">{{ $label }} <span class="text-zinc-400">{{ $list->count() }}</span></div>
                    @foreach ($list as $item)
                        <div wire:key="obj-{{ md5($item['name']) }}" @class(['group flex items-center gap-1 rounded-md px-2 py-1 text-sm', 'bg-zinc-200/70 dark:bg-zinc-700' => $table === $item['name'], 'hover:bg-zinc-100 dark:hover:bg-zinc-800' => $table !== $item['name']])>
                            <button type="button" class="flex min-w-0 flex-1 cursor-pointer items-center gap-2 text-start" wire:click="openTable({{ Js::from($item['name']) }}, {{ Js::from(in_array($tab, ['data', 'structure', 'ddl'], true) ? $tab : 'data') }})" title="{{ $item['comment'] ?? $item['name'] }}">
                                @if ($type === 'view')
                                    <flux:icon.eye variant="micro" class="size-3.5 shrink-0 text-zinc-400" />
                                @else
                                    <flux:icon.table-cells variant="micro" class="size-3.5 shrink-0 text-zinc-400" />
                                @endif
                                <span class="truncate">{{ $item['name'] }}</span>
                            </button>
                            @if ($item['rows'] !== null)
                                <span class="shrink-0 text-[10px] text-zinc-400" title="{{ __('about :count rows', ['count' => number_format($item['rows'])]) }}">{{ $item['rows'] >= 1000 ? number_format($item['rows'] / 1000, $item['rows'] >= 10000 ? 0 : 1).'k' : $item['rows'] }}</span>
                            @endif
                            <flux:dropdown position="bottom" align="end">
                                <button type="button" class="shrink-0 rounded p-0.5 text-zinc-400 opacity-0 hover:text-zinc-700 group-hover:opacity-100 dark:hover:text-zinc-200"><flux:icon.ellipsis-horizontal variant="micro" class="size-4" /></button>
                                <flux:menu>
                                    <flux:menu.item icon="table-cells" wire:click="openTable({{ Js::from($item['name']) }}, 'data')">{{ __('Data') }}</flux:menu.item>
                                    <flux:menu.item icon="wrench-screwdriver" wire:click="openTable({{ Js::from($item['name']) }}, 'structure')">{{ __('Structure') }}</flux:menu.item>
                                    <flux:menu.item icon="code-bracket" wire:click="openTable({{ Js::from($item['name']) }}, 'ddl')">{{ __('DDL') }}</flux:menu.item>
                                    <flux:menu.item icon="clipboard-document" x-on:click="navigator.clipboard.writeText({{ Js::from($item['name']) }})">{{ __('Copy name') }}</flux:menu.item>
                                    <flux:menu.item icon="arrow-up-tray" wire:click="exportTable({{ Js::from($item['name']) }})">{{ __('Export…') }}</flux:menu.item>
                                    @if ($writable)
                                        <flux:menu.separator />
                                        <flux:menu.item icon="pencil" x-on:click="let n = await studioDialog.prompt({ title: {{ Js::from(__('Rename :name', ['name' => $item['name']])) }}, label: {{ Js::from(__('New name')) }}, value: {{ Js::from($item['name']) }}, confirm: {{ Js::from(__('Continue')) }} }); if (n) $wire.reviewRename({{ Js::from($item['name']) }}, n)">{{ __('Rename…') }}</flux:menu.item>
                                        @if ($type === 'table')
                                            <flux:menu.item icon="backspace" variant="danger" wire:click="reviewTruncate({{ Js::from($item['name']) }})">{{ __('Empty…') }}</flux:menu.item>
                                        @endif
                                        <flux:menu.item icon="trash" variant="danger" wire:click="reviewDrop({{ Js::from($item['name']) }})">{{ __('Drop…') }}</flux:menu.item>
                                    @endif
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    @endforeach
                @endforeach
                @if ($shown->isEmpty())
                    <div class="px-2 py-3 text-xs text-zinc-500">
                        {{ $family !== 'sqlite' && ! $db ? __('Choose a database above.') : ($needle !== '' ? __('No table matches.') : __('No tables yet.')) }}
                    </div>
                @endif
            </nav>
        </aside>

        {{-- What is open --}}
        <main class="flex min-h-0 min-w-0 flex-1 flex-col gap-2">
            <div class="flex shrink-0 items-center gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-700">
                @foreach (array_filter([
                    'data' => $table ? __('Data') : null,
                    'structure' => $table ? __('Structure') : null,
                    'ddl' => $table ? __('DDL') : null,
                    'sql' => __('SQL editor'),
                    'transfers' => __('Import & export'),
                ]) as $key => $label)
                    <button type="button" wire:click="setTab('{{ $key }}')" @class(['-mb-px shrink-0 cursor-pointer border-b-2 px-3 py-2 text-sm', 'border-zinc-800 font-medium dark:border-white' => $tab === $key, 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $tab !== $key])>
                        @if ($key === 'data' && $table)
                            <span class="font-mono">{{ $table }}</span>
                        @else
                            {{ $label }}
                        @endif
                    </button>
                @endforeach
            </div>

            <div class="min-h-0 flex-1" wire:loading.class="opacity-60" wire:target="openTable,useDatabase,useSchema,setTab,sortBy,goToPage,filters,perPage">
                @if ($tab === 'sql')
                    @include('livewire.tools.partials.database.sql')
                @elseif ($tab === 'transfers')
                    @include('livewire.tools.partials.database.transfers')
                @elseif (! $table)
                    <div class="flex h-full min-h-64 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
                        <flux:icon.table-cells class="size-8 text-zinc-400" />
                        <flux:heading>{{ $family !== 'sqlite' && ! $db ? __('Choose a database') : __('Choose a table') }}</flux:heading>
                        <flux:text class="max-w-md">{{ __('Open a table on the left to see its rows, its structure and its DDL, or write SQL in the editor.') }}</flux:text>
                        <flux:button size="sm" icon="command-line" wire:click="setTab('sql')">{{ __('SQL editor') }}</flux:button>
                    </div>
                @elseif ($tab === 'structure')
                    @include('livewire.tools.partials.database.structure')
                @elseif ($tab === 'ddl' && $ddl !== null)
                    <div class="relative h-full" x-data>
                        <flux:button size="xs" icon="clipboard-document" class="absolute end-2 top-2" x-on:click="navigator.clipboard.writeText($refs.ddl.innerText); $flux.toast({ text: {{ Js::from(__('Copied.')) }} })">{{ __('Copy') }}</flux:button>
                        <pre x-ref="ddl" class="h-full overflow-auto rounded-xl border border-zinc-200 bg-zinc-50 p-4 font-mono text-xs leading-relaxed dark:border-zinc-700 dark:bg-zinc-900">{{ $ddl }}</pre>
                    </div>
                @elseif ($grid !== null)
                    @include('livewire.tools.partials.database.grid')
                @endif
            </div>
        </main>
    </div>

    @include('livewire.tools.partials.database.modals')
</div>
