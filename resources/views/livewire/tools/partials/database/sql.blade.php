{{-- The SQL editor: run everything or the selection (Ctrl/⌘+Enter), one result per statement; saved queries and history on the side. --}}
@use('Illuminate\Support\Js')
<div class="flex h-full gap-3 max-xl:flex-col"
     x-data="{
        running: false,
        async run(all = false) {
            const ta = this.$refs.editor;
            const text = all ? ta.value : (ta.value.substring(ta.selectionStart, ta.selectionEnd) || ta.value);
            this.running = true;
            try {
                const answer = await $wire.runSql(text);
                if (answer && answer.confirm && await studioDialog.confirm({ message: answer.confirm, confirm: {{ Js::from(__('Run it')) }}, danger: true })) await $wire.runSql(text, true);
            } finally { this.running = false }
        },
        indent(event) {
            const ta = event.target, start = ta.selectionStart;
            ta.setRangeText('  ', start, ta.selectionEnd, 'end');
            ta.dispatchEvent(new Event('input'));
        },
     }">
    <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-2">
        <div class="relative shrink-0">
            <textarea
                x-ref="editor"
                wire:model.live.debounce.1500ms="sql"
                spellcheck="false"
                rows="9"
                x-on:keydown.ctrl.enter.prevent="run()"
                x-on:keydown.meta.enter.prevent="run()"
                x-on:keydown.tab.prevent="indent($event)"
                class="block w-full resize-y rounded-xl border border-zinc-200 bg-zinc-50 p-3 font-mono text-xs leading-relaxed outline-none focus:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-900 dark:focus:border-zinc-500"
                placeholder="{{ $family === 'pgsql' ? 'SELECT * FROM users ORDER BY id DESC LIMIT 50;' : 'SELECT * FROM users ORDER BY id DESC LIMIT 50;' }}"
            ></textarea>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <flux:button size="sm" variant="primary" icon="play" x-on:click="run()" x-bind:disabled="running">{{ __('Run') }}</flux:button>
            <flux:button size="sm" icon="forward" x-on:click="run(true)" x-bind:disabled="running">{{ __('Run all') }}</flux:button>
            <flux:text class="text-xs">{{ __('Ctrl/⌘ + Enter runs the selection, or everything.') }}{{ $canWrite ? '' : ' '.__('Read only: statements that write are refused.') }}</flux:text>
            <div class="ms-auto flex gap-2">
                <flux:button size="sm" variant="ghost" icon="bookmark" x-on:click="$flux.modal('db-save-query').show()">{{ __('Save') }}</flux:button>
                @if (collect($results)->contains(fn ($r) => $r['columns'] !== [] && $r['error'] === null))
                    <flux:dropdown>
                        <flux:button size="sm" variant="ghost" icon="arrow-up-tray" icon:trailing="chevron-down">{{ __('Export result') }}</flux:button>
                        <flux:menu>
                            <flux:menu.item wire:click="exportResult('csv')">CSV</flux:menu.item>
                            <flux:menu.item wire:click="exportResult('json')">JSON</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>
        </div>

        <div class="min-h-0 flex-1 space-y-3 overflow-y-auto pe-1" wire:loading.class="opacity-60" wire:target="runSql">
            @foreach ($results as $i => $result)
                <div wire:key="result-{{ $resultsKey }}-{{ $i }}" @class(['overflow-hidden rounded-xl border', 'border-red-300 dark:border-red-800' => $result['error'], 'border-zinc-200 dark:border-zinc-700' => ! $result['error']])>
                    <div class="flex items-center gap-2 bg-zinc-50 px-3 py-1.5 text-xs dark:bg-zinc-900">
                        <code class="min-w-0 flex-1 truncate text-zinc-600 dark:text-zinc-300" title="{{ $result['statement'] }}">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $result['statement']), 160) }}</code>
                        @if ($result['error'])
                            <flux:badge size="sm" color="red">{{ __('error') }}</flux:badge>
                        @elseif ($result['columns'] !== [])
                            <flux:badge size="sm" color="green">{{ trans_choice(':count row|:count rows', count($result['rows']), ['count' => number_format(count($result['rows']))]) }}{{ $result['truncated'] ? '+' : '' }}</flux:badge>
                        @else
                            <flux:badge size="sm">{{ trans_choice(':count row changed|:count rows changed', (int) $result['affected'], ['count' => number_format((int) $result['affected'])]) }}</flux:badge>
                        @endif
                        <span class="shrink-0 tabular-nums text-zinc-400">{{ $result['ms'] }} ms</span>
                    </div>
                    @if ($result['error'])
                        <pre class="whitespace-pre-wrap px-3 py-2 font-mono text-xs text-red-700 dark:text-red-300">{{ $result['error'] }}</pre>
                    @elseif ($result['columns'] !== [])
                        <div class="max-h-96 overflow-auto">
                            <table class="min-w-full border-separate border-spacing-0 font-mono text-xs">
                                <thead class="sticky top-0 bg-white dark:bg-zinc-800">
                                    <tr>
                                        @foreach ($result['columns'] as $column)
                                            <th class="whitespace-nowrap border-b border-zinc-200 px-2 py-1 text-start font-sans font-medium dark:border-zinc-700">{{ $column }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($result['rows'] as $row)
                                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                                            @foreach ($row as $value)
                                                <td class="max-w-xs truncate whitespace-nowrap border-b border-zinc-100 px-2 py-0.5 dark:border-zinc-800" title="{{ is_string($value) && mb_strlen($value) > 60 ? \Illuminate\Support\Str::limit($value, 500) : '' }}">
                                                    @if ($value === null)
                                                        <span class="italic text-zinc-400">NULL</span>
                                                    @elseif (is_bool($value))
                                                        {{ $value ? 'true' : 'false' }}
                                                    @else
                                                        {{ \Illuminate\Support\Str::limit((string) $value, 200) }}
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($result['truncated'])
                            <div class="border-t border-zinc-200 px-3 py-1 text-[11px] text-zinc-500 dark:border-zinc-700">{{ __('Only the first :count rows are shown: export the result for all of them.', ['count' => number_format(count($result['rows']))]) }}</div>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Saved queries and history --}}
    <aside class="flex shrink-0 flex-col gap-3 overflow-y-auto xl:w-72">
        <section class="space-y-1">
            <flux:heading size="sm">{{ __('Saved queries') }}</flux:heading>
            @forelse ($saved as $query)
                <div wire:key="saved-{{ $query->uuid }}" class="group flex items-center gap-1 rounded-md px-2 py-1 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                    <button type="button" class="min-w-0 flex-1 cursor-pointer text-start" wire:click="loadQuery({{ Js::from($query->uuid) }})">
                        <div class="truncate text-sm">{{ $query->title }}</div>
                        <div class="truncate font-mono text-[11px] text-zinc-500">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $query->sql), 80) }}</div>
                    </button>
                    <button type="button" class="rounded p-0.5 text-zinc-400 opacity-0 hover:text-red-600 group-hover:opacity-100" wire:click="forgetQuery({{ Js::from($query->uuid) }})" wire:confirm="{{ __('Forget this saved query?') }}"><flux:icon.trash variant="micro" class="size-3.5" /></button>
                </div>
            @empty
                <flux:text class="px-2 text-xs">{{ __('Save a query from the editor to find it here.') }}</flux:text>
            @endforelse
        </section>

        <section class="space-y-1">
            <div class="flex items-center justify-between">
                <flux:heading size="sm">{{ __('History') }}</flux:heading>
                @if ($history->isNotEmpty())
                    <flux:button size="xs" variant="ghost" wire:click="clearHistory" wire:confirm="{{ __('Clear your history on this connection?') }}">{{ __('Clear') }}</flux:button>
                @endif
            </div>
            @forelse ($history as $query)
                <button type="button" wire:key="hist-{{ $query->uuid }}" class="block w-full cursor-pointer rounded-md px-2 py-1 text-start hover:bg-zinc-100 dark:hover:bg-zinc-800" wire:click="loadQuery({{ Js::from($query->uuid) }})" title="{{ $query->error }}">
                    <div class="flex items-center gap-1.5">
                        <span @class(['size-1.5 shrink-0 rounded-full', 'bg-green-500' => $query->status === 'ok', 'bg-red-500' => $query->status !== 'ok'])></span>
                        <span class="truncate font-mono text-[11px]">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $query->sql), 70) }}</span>
                    </div>
                    <div class="ps-3 text-[10px] text-zinc-400">{{ $query->created_at->diffForHumans() }} · {{ $query->duration_ms }} ms{{ $query->database ? ' · '.$query->database : '' }}</div>
                </button>
            @empty
                <flux:text class="px-2 text-xs">{{ __('What you run shows up here.') }}</flux:text>
            @endforelse
        </section>
    </aside>
</div>
