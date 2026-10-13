@props(['call', 'result' => null, 'steps', 'results', 'conversation', 'showTools' => false])

@php
    use App\Models\Message;

    // A subagent's card: what it was asked, how it is doing, what it touched and saw, what it reported.
    $input = (array) ($call->payload['input'] ?? []);
    $task = (array) ($call->payload['task'] ?? []);
    $background = (bool) ($input['run_in_background'] ?? false);
    $tools = $steps->where('kind', Message::KIND_TOOL_USE)->values();

    $status = match (true) {
        in_array($task['status'] ?? null, ['failed', 'error'], true), (bool) ($result?->payload['is_error'] ?? false) => 'failed',
        in_array($task['status'] ?? null, ['stopped', 'killed', 'cancelled'], true) => 'stopped',
        isset($task['finished_at']), $result !== null && ! $background => 'done',
        $conversation->isBusy() || $background => 'running',
        default => 'stopped',
    };

    $end = match (true) {
        isset($task['finished_at']) => \Illuminate\Support\Carbon::parse($task['finished_at']),
        $result !== null && ! $background => $result->created_at,
        $status === 'running' => now(),
        default => $steps->last()?->created_at ?? $call->created_at,
    };
    $seconds = max(0, (int) $call->created_at->diffInSeconds($end));
    $duration = $seconds < 60 ? $seconds.'s' : intdiv($seconds, 60).'m '.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT).'s';

    $images = $tools->flatMap(fn (Message $tool) => $results->get($tool->tool_use_id)?->images($conversation) ?? [])->values()->all();
    $latest = $tools->last();
    $report = trim(preg_replace('/^agentId: .*$/m', '', (string) $result?->content) ?? '');
@endphp

<div class="min-w-0 rounded-xl border border-zinc-200 bg-white p-3 shadow-xs dark:border-zinc-700 dark:bg-zinc-900" wire:key="agent-{{ $call->id }}">
    <div class="flex items-start gap-2.5">
        <div class="mt-0.5 shrink-0">
            @switch($status)
                @case('running') <flux:icon.loading class="size-4 text-sky-600" /> @break
                @case('done') <flux:icon.check-circle variant="mini" class="size-4 text-emerald-600" /> @break
                @case('failed') <flux:icon.x-circle variant="mini" class="size-4 text-red-600" /> @break
                @default <flux:icon.stop-circle variant="mini" class="size-4 text-zinc-400" />
            @endswitch
        </div>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="truncate text-sm font-medium">{{ $input['description'] ?? __('Subagent') }}</span>
                <flux:badge size="sm" :color="($input['subagent_type'] ?? '') === 'browser-qa' ? 'sky' : 'zinc'" :icon="($input['subagent_type'] ?? '') === 'browser-qa' ? 'globe-alt' : 'cpu-chip'">{{ $input['subagent_type'] ?? 'general-purpose' }}</flux:badge>
                @if ($background)
                    <flux:badge size="sm" color="zinc">{{ __('background') }}</flux:badge>
                @endif
            </div>
            <div class="mt-0.5 text-xs text-zinc-500">
                {{ trans_choice(':count step|:count steps', $tools->count()) }} · {{ $duration }}
                @if ($status === 'running' && $latest)
                    · <span class="font-mono">{{ $latest->toolLabel() }}</span> <span class="font-mono text-zinc-400">{{ \Illuminate\Support\Str::limit($latest->toolSummary(), 60) }}</span>
                @elseif ($status === 'stopped')
                    · {{ __('stopped') }}
                @endif
            </div>
        </div>
    </div>

    <x-studio.shots :images="array_slice($images, -6)" size="h-24" class="mt-2.5" />

    @if ($steps->isNotEmpty() || filled($input['prompt'] ?? null))
        <details class="mt-2 text-xs" @if ($showTools) open @endif>
            <summary class="cursor-pointer select-none text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('What it did') }}</summary>
            <div class="mt-1.5 space-y-1.5 border-s-2 border-zinc-200 ps-3 dark:border-zinc-700">
                @if (filled($input['prompt'] ?? null))
                    <div class="whitespace-pre-wrap text-zinc-500"><span class="font-medium text-zinc-600 dark:text-zinc-300">{{ __('Asked') }}:</span> {{ \Illuminate\Support\Str::limit($input['prompt'], 600) }}</div>
                @endif
                @foreach ($steps as $step)
                    @if ($step->kind === Message::KIND_TOOL_USE)
                        @php($stepResult = $results->get($step->tool_use_id))
                        <div class="flex min-w-0 items-center gap-1.5 text-zinc-600 dark:text-zinc-300">
                            @if (str_starts_with((string) $step->content, 'mcp__browser__'))
                                <flux:icon.globe-alt class="size-3 shrink-0 text-sky-600" />
                            @else
                                <flux:icon.command-line class="size-3 shrink-0" />
                            @endif
                            <span class="shrink-0 font-medium">{{ $step->toolLabel() }}</span>
                            <span class="truncate font-mono text-zinc-500">{{ $step->toolSummary() }}</span>
                            @if ($stepResult?->payload['is_error'] ?? false)
                                <flux:badge size="sm" color="red" class="ms-auto">{{ __('error') }}</flux:badge>
                            @endif
                        </div>
                    @elseif ($step->kind === Message::KIND_TEXT)
                        <div class="prose prose-xs max-w-none text-xs dark:prose-invert">{!! \Illuminate\Support\Str::markdown((string) $step->content, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
                    @elseif ($step->kind === Message::KIND_THINKING)
                        <div class="whitespace-pre-wrap italic text-zinc-400">{{ \Illuminate\Support\Str::limit((string) $step->content, 400) }}</div>
                    @endif
                @endforeach
            </div>
        </details>
    @endif

    @if ($report !== '')
        <details class="mt-1.5 text-xs" @if ($status === 'done' && $tools->count() === 0) open @endif>
            <summary class="cursor-pointer select-none text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Report') }}</summary>
            <div class="prose prose-sm mt-1.5 max-w-none dark:prose-invert">{!! \Illuminate\Support\Str::markdown($report, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
        </details>
    @elseif (filled($task['summary'] ?? null))
        <div class="mt-1.5 text-xs text-zinc-500">{{ $task['summary'] }}</div>
    @endif
</div>
