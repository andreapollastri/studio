@props(['icon', 'title', 'tone' => 'violet'])

{{-- The head of a confirmation: an icon in the colour of what happens, the question, and what it means --}}
<div {{ $attributes->class('flex items-start gap-4') }}>
    <div @class([
        'flex size-10 shrink-0 items-center justify-center rounded-full',
        'bg-violet-50 text-violet-600 dark:bg-violet-950/50 dark:text-violet-300' => $tone === 'violet',
        'bg-red-50 text-red-600 dark:bg-red-950/50 dark:text-red-400' => $tone === 'red',
        'bg-amber-50 text-amber-600 dark:bg-amber-950/50 dark:text-amber-400' => $tone === 'amber',
    ])>
        <flux:icon :name="$icon" class="size-5" />
    </div>
    <div class="min-w-0 flex-1 pt-1">
        <flux:heading size="lg" class="break-words">{{ $title }}</flux:heading>
        @if ($slot->isNotEmpty())
            <flux:text class="mt-1">{{ $slot }}</flux:text>
        @endif
    </div>
</div>
