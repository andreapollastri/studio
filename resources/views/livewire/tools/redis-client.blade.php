@php
    use App\Databases\Values;
    use Illuminate\Support\Js;
    $writable = $canWrite && ! $locked;
    $typeColors = ['string' => 'zinc', 'hash' => 'sky', 'list' => 'emerald', 'set' => 'violet', 'zset' => 'amber', 'stream' => 'pink'];
@endphp

<div class="flex min-h-[36rem] flex-col gap-3 lg:-m-2 lg:h-[calc(100dvh-4rem)]">
    @include('livewire.tools.partials.database.header')

    @if ($error)
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('The server did not answer')">
            <flux:callout.text class="font-mono text-xs">{{ $error }}</flux:callout.text>
            <x-slot name="actions"><flux:button size="sm" wire:click="refresh">{{ __('Try again') }}</flux:button></x-slot>
        </flux:callout>
    @endif

    <div class="flex min-h-0 flex-1 gap-3 max-lg:flex-col">
        {{-- Databases and keys --}}
        <aside class="flex shrink-0 flex-col gap-2 lg:w-80 lg:min-h-0">
            <div class="flex items-center gap-1">
                <flux:dropdown class="min-w-0 flex-1">
                    <flux:button size="sm" icon="circle-stack" icon:trailing="chevron-up-down" class="w-full justify-start!">db{{ $db }} <span class="text-zinc-400">· {{ number_format($databases[$db] ?? 0) }}</span></flux:button>
                    <flux:menu class="max-h-96 overflow-y-auto">
                        @foreach (range(0, max(15, ...array_keys($databases ?: [0 => 0]))) as $n)
                            <flux:menu.item wire:key="rdb-{{ $n }}" wire:click="useDatabase({{ $n }})" :icon="$n === $db ? 'check' : null">db{{ $n }} <span class="ms-2 text-zinc-400">{{ isset($databases[$n]) ? number_format($databases[$n]) : '' }}</span></flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
                @if ($writable)
                    <flux:button size="sm" variant="subtle" icon="plus" wire:click="startNewKey" :tooltip="__('New key')" />
                @endif
            </div>
            <div class="flex gap-1">
                <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="pattern" :placeholder="__('Pattern: user:*')" class="flex-1 font-mono" clearable />
                <flux:select size="sm" wire:model.live="type" class="w-24">
                    <flux:select.option value="">{{ __('all') }}</flux:select.option>
                    @foreach (array_keys($typeColors) as $t)
                        <flux:select.option :value="$t">{{ $t }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <nav class="min-h-0 flex-1 overflow-y-auto rounded-lg border border-zinc-200 p-1 max-lg:max-h-72 dark:border-zinc-700">
                @forelse ($keys as $item)
                    <button type="button" wire:key="rk-{{ md5($item['key']) }}" wire:click="openKey({{ Js::from($item['key']) }})"
                        @class(['flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1 text-start text-sm', 'bg-zinc-200/70 dark:bg-zinc-700' => $key === $item['key'], 'hover:bg-zinc-100 dark:hover:bg-zinc-800' => $key !== $item['key']])>
                        <flux:badge size="sm" :color="$typeColors[$item['type']] ?? 'zinc'" class="w-14 shrink-0 justify-center">{{ $item['type'] }}</flux:badge>
                        <span class="min-w-0 flex-1 truncate font-mono text-xs" title="{{ $item['key'] }}">{{ Values::display($item['key'], 120) }}</span>
                        @if ($item['ttl'] > 0)
                            <flux:icon.clock variant="micro" class="size-3 shrink-0 text-zinc-400" />
                        @endif
                    </button>
                @empty
                    <div class="px-2 py-3 text-xs text-zinc-500">{{ $pattern !== '' ? __('No key matches.') : __('This database is empty.') }}</div>
                @endforelse
                @if ($cursor !== '0')
                    <flux:button size="xs" variant="ghost" class="mt-1 w-full" wire:click="scan">{{ __('Load more keys') }}</flux:button>
                @endif
            </nav>
            <flux:text class="text-[11px]">{{ trans_choice(':count key shown|:count keys shown', count($keys), ['count' => number_format(count($keys))]) }}{{ $cursor !== '0' ? ' · '.__('more to load') : '' }}</flux:text>
        </aside>

        <main class="flex min-h-0 min-w-0 flex-1 flex-col gap-2">
            <div class="flex shrink-0 items-center gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-700">
                @foreach (['keys' => $key ? $key : __('Key'), 'console' => __('Console'), 'info' => __('Server'), 'transfers' => __('Import & export')] as $name => $label)
                    <button type="button" wire:click="setTab('{{ $name }}')" @class(['-mb-px max-w-72 shrink-0 cursor-pointer truncate border-b-2 px-3 py-2 text-sm', 'border-zinc-800 font-medium dark:border-white' => $tab === $name, 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $tab !== $name, 'font-mono' => $name === 'keys' && $key])>{{ $label }}</button>
                @endforeach
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto pe-1">
                @if ($tab === 'console')
                    <div class="flex h-full flex-col gap-2" x-data="{ async send() { const r = await $wire.run(); if (r && r.confirm && confirm(r.confirm)) await $wire.run(true); $nextTick(() => $refs.out.scrollTop = $refs.out.scrollHeight) } }">
                        <div x-ref="out" class="min-h-64 flex-1 overflow-y-auto rounded-xl border border-zinc-200 bg-zinc-950 p-3 font-mono text-xs text-zinc-100 dark:border-zinc-700">
                            @forelse ($console as $i => $line)
                                <div wire:key="cl-{{ $i }}" class="mb-2">
                                    <div class="text-emerald-400">db{{ $db }}&gt; {{ $line['command'] }}</div>
                                    <pre @class(['whitespace-pre-wrap', 'text-red-400' => $line['error']])>{{ $line['reply'] }}</pre>
                                </div>
                            @empty
                                <div class="text-zinc-500">{{ __('Commands as in redis-cli: GET user:1, HGETALL session:abc, SCAN 0 MATCH cache:* COUNT 100…') }}</div>
                            @endforelse
                        </div>
                        <form class="flex gap-2" x-on:submit.prevent="send()">
                            <flux:input wire:model="command" class="flex-1 font-mono" :placeholder="$canWrite ? 'SET greeting &quot;hello&quot;' : 'GET greeting'" autocomplete="off" />
                            <flux:button type="submit" variant="primary" icon="play">{{ __('Run') }}</flux:button>
                            <flux:button variant="ghost" icon="trash" wire:click="clearConsole" />
                        </form>
                    </div>
                @elseif ($tab === 'info')
                    <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                        @foreach ($info as $section => $values)
                            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700" wire:key="info-{{ $section }}">
                                <div class="bg-zinc-50 px-3 py-1.5 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:bg-zinc-900">{{ $section }}</div>
                                <table class="w-full text-xs">
                                    @foreach ($values as $name => $value)
                                        <tr class="border-t border-zinc-100 dark:border-zinc-800"><td class="px-3 py-1 text-zinc-500">{{ $name }}</td><td class="break-all px-3 py-1 font-mono">{{ $value }}</td></tr>
                                    @endforeach
                                </table>
                            </div>
                        @endforeach
                    </div>
                @elseif ($tab === 'transfers')
                    <div class="space-y-5">
                        <div class="grid gap-4 xl:grid-cols-2">
                            <form wire:submit="startExport" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                                <flux:heading>{{ __('Export db:n as JSON', ['n' => $db]) }}</flux:heading>
                                <flux:input wire:model="exportPattern" :label="__('Keys matching')" placeholder="*" class="font-mono" />
                                <flux:checkbox wire:model="exportGzip" :label="__('gzip')" />
                                <flux:text class="text-xs">{{ __('Every key with its type, value and TTL; binary values in base64. Import it here or on another server.') }}</flux:text>
                                <div class="flex justify-end"><flux:button type="submit" variant="primary" icon="arrow-up-tray">{{ __('Export') }}</flux:button></div>
                            </form>
                            <form wire:submit="startImport" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                                <flux:heading>{{ __('Import into db:n', ['n' => $db]) }}</flux:heading>
                                @if ($writable)
                                    <input type="file" wire:model="importFile" accept=".json,.gz,.jsonl" class="block w-full text-sm file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm dark:file:bg-zinc-700" />
                                    <flux:error name="importFile" />
                                    <flux:checkbox wire:model="importReplace" :label="__('Replace the keys that exist')" :description="__('Otherwise they stay as they are.')" />
                                    <div class="flex justify-end"><flux:button type="submit" variant="primary" icon="arrow-down-tray">{{ __('Import') }}</flux:button></div>
                                @else
                                    <flux:callout variant="warning" icon="lock-closed"><flux:callout.text>{{ ! $canWrite ? __('You may only read this connection.') : __('Production: unlock changes first (the lock at the top).') }}</flux:callout.text></flux:callout>
                                @endif
                            </form>
                        </div>
                        @include('livewire.tools.partials.database.transfer-list')
                    </div>
                @elseif (! $detail)
                    <div class="flex h-full min-h-64 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
                        <flux:icon.key class="size-8 text-zinc-400" />
                        <flux:heading>{{ $key ? __('That key is gone') : __('Choose a key') }}</flux:heading>
                        <flux:text>{{ __('Pick a key on the left to see and change its value, or use the console.') }}</flux:text>
                    </div>
                @else
                    {{-- The open key --}}
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:badge :color="$typeColors[$detail['type']] ?? 'zinc'">{{ $detail['type'] }}</flux:badge>
                            <code class="min-w-0 truncate font-mono text-sm">{{ Values::display($detail['key'], 300) }}</code>
                            <span class="text-xs text-zinc-500">
                                {{ $detail['length'] !== null ? trans_choice(':count item|:count items', $detail['length'], ['count' => number_format($detail['length'])]).' · ' : '' }}{{ $detail['memory'] !== null ? Values::bytes($detail['memory']).' · ' : '' }}{{ $detail['encoding'] }}
                            </span>
                            <div class="ms-auto flex items-center gap-1">
                                <flux:button size="xs" variant="ghost" icon="clipboard-document" x-on:click="navigator.clipboard.writeText({{ Js::from($detail['key']) }})">{{ __('Copy key') }}</flux:button>
                                @if ($writable)
                                    <flux:button size="xs" variant="ghost" icon="pencil" x-on:click="let n = prompt({{ Js::from(__('New name of the key')) }}, {{ Js::from($detail['key']) }}); if (n) $wire.renameKey(n)">{{ __('Rename') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteKey" wire:confirm="{{ __('Delete this key?') }}">{{ __('Delete') }}</flux:button>
                                @endif
                            </div>
                        </div>

                        <form wire:submit="saveTtl" class="flex flex-wrap items-end gap-2">
                            <flux:input size="sm" type="number" min="0" wire:model="ttl" :label="__('Expires in (seconds)')" :placeholder="__('never')" class="w-44" :disabled="! $writable" />
                            @if ($writable)
                                <flux:button size="sm" type="submit">{{ __('Set TTL') }}</flux:button>
                            @endif
                            @if ($detail['ttl'] > 0)
                                <span class="pb-2 text-xs text-zinc-500">{{ __('expires :when', ['when' => now()->addMilliseconds($detail['ttl'])->diffForHumans()]) }}</span>
                            @endif
                        </form>

                        @if ($detail['truncated'])
                            <flux:callout icon="information-circle"><flux:callout.text>{{ __('Only the first :count items are shown: use the console for the rest.', ['count' => \App\Databases\Redis\Keyspace::ITEMS]) }}</flux:callout.text></flux:callout>
                        @endif

                        @switch($detail['type'])
                            @case('string')
                                @php($json = json_decode((string) $detail['value'], true))
                                <form wire:submit="saveString" class="space-y-2">
                                    <flux:textarea wire:model="stringValue" rows="10" class="font-mono text-xs" :disabled="! $writable" spellcheck="false" />
                                    <div class="flex items-center gap-2">
                                        @if ($writable)
                                            <flux:button size="sm" type="submit" variant="primary">{{ __('Save') }}</flux:button>
                                        @endif
                                        @if (is_array($json))
                                            <flux:button size="sm" variant="ghost" x-on:click="$wire.stringValue = JSON.stringify(JSON.parse($wire.stringValue), null, 2)">{{ __('Format JSON') }}</flux:button>
                                        @endif
                                        <span class="text-xs text-zinc-500">{{ Values::bytes($detail['length'] ?? 0) }}</span>
                                    </div>
                                </form>
                                @break
                            @case('hash')
                            @case('zset')
                                <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                                    <table class="min-w-full text-xs">
                                        <thead class="bg-zinc-50 text-[11px] uppercase tracking-wide text-zinc-500 dark:bg-zinc-900">
                                            <tr><th class="px-3 py-2 text-start">{{ $detail['type'] === 'hash' ? __('Field') : __('Member') }}</th><th class="px-3 py-2 text-start">{{ $detail['type'] === 'hash' ? __('Value') : __('Score') }}</th><th class="w-10"></th></tr>
                                        </thead>
                                        <tbody class="divide-y divide-zinc-100 font-mono dark:divide-zinc-800">
                                            @foreach ($detail['value'] as [$a, $b])
                                                <tr wire:key="pair-{{ $loop->index }}-{{ md5($a) }}">
                                                    <td class="max-w-xs truncate px-3 py-1" title="{{ Values::display($a, 500) }}">{{ Values::display($a, 200) }}</td>
                                                    <td class="max-w-md truncate px-3 py-1" title="{{ Values::display($b, 500) }}">{{ Values::display($b, 300) }}</td>
                                                    <td class="px-1">
                                                        @if ($writable)
                                                            @if ($detail['type'] === 'hash')
                                                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteField({{ Js::from($a) }})" />
                                                            @else
                                                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeScored({{ Js::from($a) }})" />
                                                            @endif
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                @if ($writable)
                                    <form wire:submit="{{ $detail['type'] === 'hash' ? 'setField' : 'addScored' }}" class="flex flex-wrap items-end gap-2">
                                        @if ($detail['type'] === 'hash')
                                            <flux:input size="sm" wire:model="field" :label="__('Field')" class="font-mono" />
                                        @else
                                            <flux:input size="sm" wire:model="score" :label="__('Score')" class="w-28 font-mono" />
                                        @endif
                                        <flux:input size="sm" wire:model="fieldValue" :label="$detail['type'] === 'hash' ? __('Value') : __('Member')" class="min-w-48 flex-1 font-mono" />
                                        <flux:button size="sm" type="submit" icon="plus">{{ __('Set') }}</flux:button>
                                    </form>
                                @endif
                                @break
                            @case('list')
                                <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                                    <table class="min-w-full text-xs">
                                        <tbody class="divide-y divide-zinc-100 font-mono dark:divide-zinc-800">
                                            @foreach ($detail['value'] as $i => $item)
                                                <tr wire:key="li-{{ $i }}">
                                                    <td class="w-12 px-3 py-1 text-zinc-400">{{ $i }}</td>
                                                    <td class="max-w-xl truncate px-3 py-1" title="{{ Values::display($item, 500) }}">{{ Values::display($item, 300) }}</td>
                                                    <td class="w-20 px-1 text-end">
                                                        @if ($writable)
                                                            <flux:button size="xs" variant="ghost" icon="pencil" x-on:click="let v = prompt({{ Js::from(__('New value')) }}, {{ Js::from(Values::display($item)) }}); if (v !== null) $wire.setItem({{ $i }}, v)" />
                                                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeItem({{ Js::from((string) $item) }})" />
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                @if ($writable)
                                    <form wire:submit="pushItem" class="flex items-end gap-2">
                                        <flux:input size="sm" wire:model="fieldValue" :label="__('Value')" class="flex-1 font-mono" />
                                        <flux:button size="sm" wire:click="pushItem('left')">{{ __('Push to the head') }}</flux:button>
                                        <flux:button size="sm" type="submit" icon="plus">{{ __('Push to the tail') }}</flux:button>
                                    </form>
                                @endif
                                @break
                            @case('set')
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($detail['value'] as $member)
                                        <span wire:key="sm-{{ md5($member) }}" class="inline-flex max-w-full items-center gap-1 rounded-md border border-zinc-200 px-2 py-0.5 font-mono text-xs dark:border-zinc-700">
                                            <span class="truncate">{{ Values::display($member, 200) }}</span>
                                            @if ($writable)
                                                <button type="button" class="text-zinc-400 hover:text-red-600" wire:click="removeMember({{ Js::from($member) }})"><flux:icon.x-mark variant="micro" class="size-3" /></button>
                                            @endif
                                        </span>
                                    @endforeach
                                </div>
                                @if ($writable)
                                    <form wire:submit="addMember" class="flex items-end gap-2">
                                        <flux:input size="sm" wire:model="fieldValue" :label="__('Member')" class="flex-1 font-mono" />
                                        <flux:button size="sm" type="submit" icon="plus">{{ __('Add') }}</flux:button>
                                    </form>
                                @endif
                                @break
                            @case('stream')
                                <div class="space-y-2">
                                    @foreach ($detail['value'] as $entry)
                                        <div wire:key="se-{{ $entry['id'] }}" class="rounded-lg border border-zinc-200 p-2 font-mono text-xs dark:border-zinc-700">
                                            <div class="mb-1 text-zinc-500">{{ $entry['id'] }}</div>
                                            @foreach ($entry['fields'] as [$f, $v])
                                                <div><span class="text-sky-700 dark:text-sky-300">{{ $f }}</span> {{ Values::display($v, 300) }}</div>
                                            @endforeach
                                        </div>
                                    @endforeach
                                </div>
                                @if ($writable)
                                    <form wire:submit="addEntry" class="flex flex-wrap items-end gap-2">
                                        <flux:input size="sm" wire:model="field" :label="__('Field')" class="font-mono" />
                                        <flux:input size="sm" wire:model="fieldValue" :label="__('Value')" class="min-w-48 flex-1 font-mono" />
                                        <flux:button size="sm" type="submit" icon="plus">{{ __('Add entry') }}</flux:button>
                                    </form>
                                @endif
                                @break
                            @default
                                <flux:text>{{ __('This type is shown in the console only.') }}</flux:text>
                        @endswitch
                    </div>
                @endif
            </div>
        </main>
    </div>

    {{-- A new key --}}
    <flux:modal name="redis-key" class="md:w-[32rem]">
        <form wire:submit="createKey" class="space-y-4">
            <flux:heading size="lg">{{ __('New key in db:n', ['n' => $db]) }}</flux:heading>
            <flux:input wire:model="newKey" :label="__('Key')" class="font-mono" required />
            <flux:select wire:model.live="newType" :label="__('Type')">
                @foreach (array_keys($typeColors) as $t)
                    <flux:select.option :value="$t">{{ $t }}</flux:select.option>
                @endforeach
            </flux:select>
            @if (in_array($newType, ['hash', 'stream'], true))
                <flux:input wire:model="newField" :label="__('Field')" class="font-mono" />
            @endif
            @if ($newType === 'zset')
                <flux:input wire:model="newScore" :label="__('Score')" class="font-mono" />
            @endif
            <flux:textarea wire:model="newValue" :label="in_array($newType, ['set', 'zset'], true) ? __('Member') : __('Value')" rows="3" class="font-mono text-xs" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
