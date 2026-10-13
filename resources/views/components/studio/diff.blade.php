@props(['text' => ''])

{{-- A unified diff, line by line: additions green, removals red, hunks blue, file headers bold --}}
<div {{ $attributes->class('overflow-auto rounded-lg border border-zinc-200 bg-white font-mono text-[11px] leading-[1.45] dark:border-zinc-700 dark:bg-zinc-900') }}>
    @if (trim((string) $text) === '')
        <div class="px-3 py-2 text-zinc-500">{{ __('No differences.') }}</div>
    @else
        <pre class="min-w-max py-1">@foreach (explode("\n", rtrim((string) $text, "\n")) as $line)<span @class([
            'block px-3 whitespace-pre',
            'bg-zinc-100 font-semibold text-zinc-800 dark:bg-zinc-800 dark:text-zinc-100' => str_starts_with($line, 'diff --'),
            'text-zinc-500' => str_starts_with($line, 'index ') || str_starts_with($line, '--- ') || str_starts_with($line, '+++ ') || str_starts_with($line, 'new file') || str_starts_with($line, 'deleted file') || str_starts_with($line, 'similarity') || str_starts_with($line, 'rename '),
            'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300' => str_starts_with($line, '@@'),
            'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300' => str_starts_with($line, '+') && ! str_starts_with($line, '+++ '),
            'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-300' => str_starts_with($line, '-') && ! str_starts_with($line, '--- '),
        ])>{{ $line === '' ? ' ' : $line }}</span>@endforeach</pre>
    @endif
</div>
