@props([
    // The Livewire method that answers a query with [{id, label, sub}].
    'search',
    // The Livewire method called with the chosen id (null when cleared).
    'select',
    // What is chosen now: ['id' => …, 'label' => …] or null.
    'selected' => null,
    'placeholder' => __('Search…'),
    // Keep the choice in the field (a project) or empty it after each pick (adding people).
    'keep' => true,
    'clearable' => true,
])

{{-- A search field with a list that fills from the server as you type: arrows, Enter, Escape. --}}
<div
    x-data="searchPicker({ search: @js($search), select: @js($select), selected: @js($selected), keep: @js((bool) $keep) })"
    x-on:click.outside="close()"
    {{ $attributes->class('relative') }}
>
    <div class="relative">
        <flux:icon.magnifying-glass variant="micro" class="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
        <input
            type="text"
            x-ref="input"
            x-model="query"
            x-on:focus="openList()"
            x-on:input.debounce.200ms="fetch()"
            x-on:keydown.arrow-down.prevent="move(1)"
            x-on:keydown.arrow-up.prevent="move(-1)"
            x-on:keydown.enter.prevent="pick(results[highlight])"
            x-on:keydown.escape="close()"
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            class="w-full rounded-lg border border-zinc-200 bg-white py-1.5 ps-8 pe-8 text-sm shadow-xs outline-none focus:border-zinc-400 dark:border-white/10 dark:bg-white/10 dark:focus:border-white/30"
        />
        @if ($clearable)
            <button type="button" x-show="selected" x-on:click="clear()" class="absolute end-2 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200" title="{{ __('Clear') }}">
                <flux:icon.x-mark variant="micro" class="size-4" />
            </button>
        @endif
    </div>
    <div
        x-show="open"
        x-transition.opacity.duration.100ms
        class="absolute z-50 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-600 dark:bg-zinc-800"
        style="display: none"
    >
        <template x-for="(result, index) in results" :key="result.id">
            <button
                type="button"
                x-on:click="pick(result)"
                x-on:mouseenter="highlight = index"
                class="flex w-full flex-col items-start rounded-md px-2 py-1.5 text-start"
                :class="highlight === index ? 'bg-zinc-100 dark:bg-zinc-700' : ''"
            >
                <span class="text-sm" x-text="result.label"></span>
                <span class="text-xs text-zinc-500" x-text="result.sub" x-show="result.sub"></span>
            </button>
        </template>
        <div x-show="!loading && results.length === 0" class="px-2 py-1.5 text-xs text-zinc-500">{{ __('No results') }}</div>
        <div x-show="loading && results.length === 0" class="px-2 py-1.5 text-xs text-zinc-500">{{ __('Searching…') }}</div>
    </div>
</div>
