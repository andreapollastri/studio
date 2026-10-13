@props(['message', 'result' => null, 'showTools' => false, 'conversation' => null])

@php
    use App\Models\Message;
@endphp

@if ($message->kind === Message::KIND_TOOL_RESULT)
    {{-- folded under its tool call --}}
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
        $browser = str_starts_with((string) $message->content, 'mcp__browser__');
    @endphp
    <div class="max-w-3xl">
        <details class="rounded-lg border border-zinc-200 text-xs dark:border-zinc-700" @if($showTools) open @endif>
            <summary class="flex cursor-pointer select-none items-center gap-2 px-3 py-1.5 text-zinc-600 dark:text-zinc-300">
                @if ($browser)
                    <flux:icon.globe-alt class="size-3.5 shrink-0 text-sky-600" />
                @else
                    <flux:icon.command-line class="size-3.5 shrink-0" />
                @endif
                <span class="shrink-0 font-medium">{{ $message->toolLabel() }}</span>
                <span class="truncate font-mono text-zinc-500">{{ $message->toolSummary() }}</span>
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
        {{-- screenshots stay in sight: they are the proof of what the agent looked at --}}
        <x-studio.shots :images="$result?->images($conversation) ?? []" />
    </div>
@elseif ($message->kind === Message::KIND_NOTICE)
    <flux:callout :variant="($message->payload['level'] ?? 'info') === 'error' ? 'danger' : 'secondary'" icon="information-circle" class="max-w-3xl">
        <flux:callout.text>{{ $message->content }}</flux:callout.text>
    </flux:callout>
@endif
