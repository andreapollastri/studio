{{-- The imports and exports of a connection, with their log; the list follows them while they run. --}}
@use('App\Databases\Values')
@use('Illuminate\Support\Js')
<section class="space-y-2" @if ($transfers->contains(fn ($t) => ! $t->isFinished())) wire:poll.2s @endif>
    <flux:heading size="sm">{{ __('Recent imports and exports') }}</flux:heading>
    @forelse ($transfers as $transfer)
        <div wire:key="transfer-{{ $transfer->uuid }}" class="rounded-lg border border-zinc-200 dark:border-zinc-700" x-data="{ open: {{ $transfer->isFinished() ? 'false' : 'true' }} }">
            <div class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                <flux:icon :name="$transfer->kind === 'export' ? 'arrow-up-tray' : 'arrow-down-tray'" variant="micro" class="size-4 text-zinc-400" />
                <span class="font-medium">{{ $transfer->kind === 'export' ? __('Export') : __('Import') }}</span>
                <flux:badge size="sm">{{ strtoupper($transfer->format) }}</flux:badge>
                <span class="min-w-0 truncate text-zinc-500">{{ $transfer->summary() }}</span>
                <flux:badge size="sm" :color="match ($transfer->status) { 'success' => 'green', 'failed' => 'red', 'running' => 'sky', default => 'zinc' }" :icon="$transfer->isFinished() ? null : 'arrow-path'">{{ match ($transfer->status) { 'success' => __('done'), 'failed' => __('failed'), 'running' => __('running'), default => __('queued') } }}</flux:badge>
                <span class="text-xs text-zinc-400">{{ $transfer->created_at->diffForHumans() }}{{ $transfer->user && $transfer->user_id !== auth()->id() ? ' · '.$transfer->user->name : '' }}</span>
                <div class="ms-auto flex items-center gap-1">
                    @if ($transfer->rows !== null)
                        <span class="text-xs text-zinc-500">{{ number_format($transfer->rows) }}</span>
                    @endif
                    @if ($transfer->bytes !== null)
                        <span class="text-xs text-zinc-500">· {{ Values::bytes($transfer->bytes) }}</span>
                    @endif
                    @if ($transfer->hasFile())
                        <flux:button size="xs" icon="arrow-down-tray" :href="route('databases.download', $transfer)">{{ __('Download') }}</flux:button>
                        <flux:button size="xs" variant="ghost" icon="cloud" wire:click="saveToDrive({{ Js::from($transfer->uuid) }})" :tooltip="__('Save to my drive')" />
                    @endif
                    <flux:button size="xs" variant="ghost" icon="command-line" x-on:click="open = ! open" :tooltip="__('Log')" />
                    @if ($transfer->isFinished())
                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteTransfer({{ Js::from($transfer->uuid) }})" />
                    @endif
                </div>
            </div>
            @if ($transfer->error)
                <div class="border-t border-zinc-200 px-3 py-1.5 font-mono text-xs text-red-700 dark:border-zinc-700 dark:text-red-300">{{ $transfer->error }}</div>
            @endif
            <pre x-show="open" x-cloak class="max-h-60 overflow-auto border-t border-zinc-200 bg-zinc-50 px-3 py-2 font-mono text-[11px] leading-relaxed dark:border-zinc-700 dark:bg-zinc-900">{{ $transfer->output ?: __('Waiting for the databases worker…') }}</pre>
            @if ($transfer->expires_at && $transfer->hasFile())
                <div class="border-t border-zinc-200 px-3 py-1 text-[11px] text-zinc-400 dark:border-zinc-700">{{ __('The file is kept until :date.', ['date' => $transfer->expires_at->isoFormat('LLL')]) }}</div>
            @endif
        </div>
    @empty
        <flux:text class="text-xs">{{ __('Nothing yet. Exports stay a few days to download or save to your drive.') }}</flux:text>
    @endforelse
</section>
