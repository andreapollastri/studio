{{-- Export a database, some tables, as SQL, CSV or JSON; import a dump or rows. --}}
@php($tables = collect($objects)->where('type', 'table')->pluck('name'))
<div class="h-full space-y-5 overflow-y-auto pe-1">
    @if ($family !== 'sqlite' && ! $db)
        <flux:callout icon="circle-stack" :heading="__('Choose a database first')">
            <flux:callout.text>{{ __('Imports and exports work on the database chosen on the left.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="grid gap-4 xl:grid-cols-2">
            {{-- Export --}}
            <form wire:submit="startExport" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-center gap-2">
                    <flux:icon.arrow-up-tray variant="micro" class="size-4 text-zinc-400" />
                    <flux:heading>{{ __('Export') }}</flux:heading>
                    <span class="font-mono text-xs text-zinc-500">{{ $db }}{{ $schema && $schema !== 'public' ? '.'.$schema : '' }}</span>
                </div>
                <flux:radio.group wire:model.live="exportFormat" variant="segmented" size="sm">
                    <flux:radio value="sql" :label="__('SQL dump')" />
                    <flux:radio value="csv" label="CSV" />
                    <flux:radio value="json" label="JSON" />
                </flux:radio.group>

                <flux:field>
                    <div class="flex items-center justify-between">
                        <flux:label>{{ $exportFormat === 'sql' ? __('Tables (none: all of them)') : __('Tables (a file each, in a zip when more than one)') }}</flux:label>
                        <div class="flex gap-1">
                            <flux:button size="xs" variant="ghost" wire:click="$set('exportTables', {{ \Illuminate\Support\Js::from($tables->values()->all()) }})">{{ __('All') }}</flux:button>
                            <flux:button size="xs" variant="ghost" wire:click="$set('exportTables', [])">{{ __('None') }}</flux:button>
                        </div>
                    </div>
                    <div class="max-h-48 overflow-y-auto rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">
                        <div class="grid gap-1 sm:grid-cols-2">
                            @forelse ($tables as $name)
                                <label class="flex min-w-0 items-center gap-2 text-xs" wire:key="exp-{{ md5($name) }}">
                                    <input type="checkbox" wire:model.live="exportTables" value="{{ $name }}" />
                                    <span class="truncate font-mono">{{ $name }}</span>
                                </label>
                            @empty
                                <span class="text-xs text-zinc-500">{{ __('No tables.') }}</span>
                            @endforelse
                        </div>
                    </div>
                    <flux:error name="exportTables" />
                </flux:field>

                @if ($exportFormat === 'sql')
                    <div class="flex flex-wrap gap-x-5 gap-y-2">
                        <flux:checkbox wire:model="exportStructure" :label="__('Structure')" />
                        <flux:checkbox wire:model="exportData" :label="__('Data')" />
                        <flux:checkbox wire:model="exportDrop" :label="__('DROP before CREATE')" />
                        <flux:checkbox wire:model="exportGzip" :label="__('gzip')" />
                    </div>
                    <flux:select wire:model="exportEngine" size="sm" :label="__('Made with')">
                        <flux:select.option value="auto">{{ __('The database’s own tool when here, else Studio') }}</flux:select.option>
                        <flux:select.option value="native">{{ $family === 'mysql' ? 'mariadb-dump / mysqldump' : ($family === 'pgsql' ? 'pg_dump' : 'sqlite3 .dump') }}</flux:select.option>
                        <flux:select.option value="studio">{{ __('Studio (portable INSERTs)') }}</flux:select.option>
                    </flux:select>
                @else
                    <flux:checkbox wire:model="exportGzip" :label="__('gzip (one table)')" />
                @endif

                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary" icon="arrow-up-tray">{{ __('Export') }}</flux:button>
                </div>
            </form>

            {{-- Import --}}
            <form wire:submit="startImport" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-center gap-2">
                    <flux:icon.arrow-down-tray variant="micro" class="size-4 text-zinc-400" />
                    <flux:heading>{{ __('Import') }}</flux:heading>
                    <span class="font-mono text-xs text-zinc-500">{{ $db }}</span>
                </div>

                @if (! $writable)
                    <flux:callout variant="warning" icon="lock-closed">
                        <flux:callout.text>{{ ! $canWrite ? __('You may only read this connection.') : __('Production: unlock changes first (the lock at the top).') }}</flux:callout.text>
                    </flux:callout>
                @else
                    <flux:field>
                        <flux:label>{{ __('File') }}</flux:label>
                        <input type="file" wire:model="importFile" accept=".sql,.gz,.csv,.tsv,.json,.txt" class="block w-full text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm dark:file:bg-zinc-700" />
                        <div wire:loading wire:target="importFile" class="text-xs text-zinc-500">{{ __('Uploading…') }}</div>
                        <flux:error name="importFile" />
                        <flux:description>{{ __('.sql or .sql.gz (a dump), .csv or .json (rows for a table); up to :size MB.', ['size' => config('studio.databases.max_import_mb')]) }}</flux:description>
                    </flux:field>
                    @if ($drive->isNotEmpty())
                        <flux:select wire:model.live="importDrive" size="sm" :label="__('Or a file of your drive')" :placeholder="__('Choose…')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach ($drive as $file)
                                <flux:select.option :value="$file->uuid">{{ $file->name }} · {{ \App\Databases\Values::bytes($file->size) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

                    <flux:radio.group wire:model.live="importFormat" variant="segmented" size="sm">
                        <flux:radio value="sql" label="SQL" />
                        <flux:radio value="csv" label="CSV" />
                        <flux:radio value="json" label="JSON" />
                    </flux:radio.group>

                    @if ($importFormat === 'sql')
                        <flux:checkbox wire:model="importContinue" :label="__('Go on after a statement fails')" :description="__('Otherwise the import stops at the first error.')" />
                    @else
                        <flux:select wire:model="importTable" size="sm" :label="__('Into the table')" :placeholder="__('Choose…')">
                            @foreach ($tables as $name)
                                <flux:select.option :value="$name">{{ $name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="importTable" />
                        @if ($importFormat === 'csv')
                            <div class="grid grid-cols-2 gap-3">
                                <flux:select wire:model="importDelimiter" size="sm" :label="__('Separator')">
                                    <flux:select.option value="auto">{{ __('Find out') }}</flux:select.option>
                                    <flux:select.option value=",">, (comma)</flux:select.option>
                                    <flux:select.option value=";">; (semicolon)</flux:select.option>
                                    <flux:select.option value="\t">{{ __('tab') }}</flux:select.option>
                                    <flux:select.option value="|">| (pipe)</flux:select.option>
                                </flux:select>
                                <flux:checkbox wire:model="importHeader" class="mt-7" :label="__('First row: column names')" />
                            </div>
                        @endif
                        <div class="flex flex-wrap gap-x-5 gap-y-2">
                            <flux:checkbox wire:model="importEmptyNull" :label="__('Empty values as NULL')" />
                            <flux:checkbox wire:model="importTruncate" :label="__('Empty the table first')" />
                        </div>
                        <flux:text class="text-xs">{{ __('Columns are matched by name; those the table does not have are left out.') }}</flux:text>
                    @endif

                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" icon="arrow-down-tray" wire:loading.attr="disabled" wire:target="importFile,startImport">{{ __('Import') }}</flux:button>
                    </div>
                @endif
            </form>
        </div>
    @endif

    @include('livewire.tools.partials.database.transfer-list')
</div>
