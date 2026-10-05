@props(['message', 'result' => null, 'showTools' => false])

@php
    use App\Models\Message;

    $inSubagent = filled($message->payload['parent_tool_use_id'] ?? null);

    // One line per tool call: the command, the file, the pattern, or what a subagent was asked to do.
    $toolSummary = function (Message $message): string {
        $input = (array) ($message->payload['input'] ?? []);

        return (string) match ($message->content) {
            'Bash' => $input['command'] ?? '',
            'Read', 'Edit', 'Write', 'MultiEdit' => $input['file_path'] ?? '',
            'Grep', 'Glob' => $input['pattern'] ?? '',
            'Task', 'Agent' => trim(($input['description'] ?? '').(isset($input['subagent_type']) ? ' · '.$input['subagent_type'] : '')),
            'AskUserQuestion' => implode(' · ', array_filter(array_column((array) ($input['questions'] ?? []), 'header'))),
            default => \Illuminate\Support\Str::limit(json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 120),
        };
    };
@endphp

@if ($message->kind === Message::KIND_TOOL_RESULT)
    {{-- folded under its tool call --}}
@elseif ($inSubagent && in_array($message->kind, [Message::KIND_TEXT, Message::KIND_TOOL_USE], true))
    <div class="ms-4 border-s-2 border-zinc-200 ps-3 dark:border-zinc-700">
        <div class="mb-1 text-[10px] uppercase tracking-wide text-zinc-400">{{ __('subagent') }}</div>
        @if ($message->kind === Message::KIND_TEXT)
            <div class="prose prose-sm max-w-3xl dark:prose-invert">{!! \Illuminate\Support\Str::markdown((string) $message->content, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
        @else
            <div class="flex items-center gap-2 text-xs text-zinc-600 dark:text-zinc-300"><flux:icon.command-line class="size-3.5 shrink-0" /><span class="font-medium">{{ $message->content }}</span> <span class="truncate font-mono text-zinc-500">{{ $toolSummary($message) }}</span></div>
        @endif
    </div>
@elseif ($message->isFromUser())
    <div class="flex justify-end">
        <div class="max-w-2xl whitespace-pre-wrap rounded-2xl rounded-br-sm bg-zinc-900 px-4 py-2 text-sm text-white dark:bg-zinc-100 dark:text-zinc-900">{{ $message->content }}</div>
    </div>
@elseif ($message->kind === Message::KIND_TEXT)
    <div class="prose prose-sm max-w-3xl dark:prose-invert prose-pre:bg-zinc-100 prose-pre:text-zinc-900 dark:prose-pre:bg-zinc-800 dark:prose-pre:text-zinc-100">
        {!! \Illuminate\Support\Str::markdown((string) $message->content, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
    </div>
@elseif ($message->kind === Message::KIND_THINKING)
    <details class="max-w-3xl text-xs text-zinc-500">
        <summary class="cursor-pointer select-none">{{ __('Reasoning') }}</summary>
        <div class="mt-1 whitespace-pre-wrap">{{ $message->content }}</div>
    </details>
@elseif ($message->kind === Message::KIND_TOOL_USE)
    @php
        $input = $message->payload['input'] ?? [];
        $summary = $toolSummary($message);
    @endphp
    <details class="max-w-3xl rounded-lg border border-zinc-200 text-xs dark:border-zinc-700" @if($showTools) open @endif>
        <summary class="flex cursor-pointer select-none items-center gap-2 px-3 py-1.5 text-zinc-600 dark:text-zinc-300">
            <flux:icon.command-line class="size-3.5 shrink-0" />
            <span class="font-medium">{{ $message->content }}</span>
            <span class="truncate font-mono text-zinc-500">{{ $summary }}</span>
            @if ($result?->payload['is_error'] ?? false)
                <flux:badge size="sm" color="red" class="ms-auto">{{ __('error') }}</flux:badge>
            @endif
        </summary>
        <div class="space-y-2 border-t border-zinc-200 px-3 py-2 dark:border-zinc-700">
            <pre class="max-h-48 overflow-auto whitespace-pre-wrap font-mono text-[11px]">{{ json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            @if ($result)
                <div class="text-[10px] uppercase tracking-wide text-zinc-500">{{ __('Result') }}</div>
                <pre class="max-h-64 overflow-auto whitespace-pre-wrap font-mono text-[11px]">{{ $result->content }}</pre>
            @endif
        </div>
    </details>
@elseif ($message->kind === Message::KIND_NOTICE)
    <flux:callout :variant="($message->payload['level'] ?? 'info') === 'error' ? 'danger' : 'secondary'" icon="information-circle" class="max-w-3xl">
        <flux:callout.text>{{ $message->content }}</flux:callout.text>
    </flux:callout>
@endif
