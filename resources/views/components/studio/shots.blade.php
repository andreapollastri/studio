@props(['images' => [], 'size' => 'h-28'])

{{-- Screenshots as thumbnails; a click opens one full size in the chat's lightbox (studio-shot). --}}
@if ($images !== [])
    <div {{ $attributes->class('mt-1.5 flex flex-wrap gap-2') }}>
        @foreach ($images as $image)
            <button
                type="button"
                class="group relative overflow-hidden rounded-md border border-zinc-200 bg-white shadow-xs transition hover:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-500"
                x-on:click="$dispatch('studio-shot', { src: @js($image['url']) })"
                aria-label="{{ __('Open the screenshot') }}"
            >
                <img src="{{ $image['url'] }}" alt="{{ __('Screenshot') }}" loading="lazy" class="{{ $size }} w-auto max-w-[16rem] object-cover object-top transition group-hover:opacity-90">
                <span class="absolute inset-x-0 bottom-0 flex justify-end bg-gradient-to-t from-black/40 to-transparent p-1 opacity-0 transition group-hover:opacity-100">
                    <flux:icon.magnifying-glass-plus class="size-4 text-white" />
                </span>
            </button>
        @endforeach
    </div>
@endif
