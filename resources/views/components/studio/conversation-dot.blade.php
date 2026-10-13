@props(['conversation'])

@php
    // working: amber, pulsing; asking (a question or a permission): blue, with a ring that calls;
    // failed: red; done (finished since the person last opened it): green; quiet: grey
    $signal = $conversation->signal();
    $color = match ($signal) {
        'working' => 'bg-amber-500',
        'asking' => 'bg-blue-500',
        'failed' => 'bg-red-500',
        'done' => 'bg-green-500',
        default => 'bg-zinc-300 dark:bg-zinc-600',
    };
@endphp

<span {{ $attributes->class('relative inline-flex size-2 shrink-0') }} title="{{ $conversation->signalLabel() }}" data-signal="{{ $signal }}">
    @if ($signal === 'asking')
        <span class="absolute inline-flex size-full animate-ping rounded-full bg-blue-400 opacity-75"></span>
    @endif
    <span @class(['relative inline-flex size-full rounded-full', $color, 'animate-pulse' => $signal === 'working'])></span>
</span>
