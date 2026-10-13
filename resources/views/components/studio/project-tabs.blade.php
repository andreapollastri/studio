@props(['project', 'current' => 'workspace'])

{{--
    The tabs of a project, on a row of their own under the header of each of its pages:
    the workspace (chat, preview, changes), its repository on the git host, the
    infrastructure attached to it, the passwords, SSH connections and folders tied to
    it, its backups and its settings. A tab shows only to who has something there. Narrow screens
    scroll the row sideways, with the open tab brought into view.
--}}
@php
    $user = auth()->user();
    $tabs = array_filter([
        'workspace' => ['label' => __('Workspace'), 'icon' => 'chat-bubble-left-right', 'route' => route('studio.show', $project)],
        'repository' => $user->can('chat', $project) ? ['label' => __('Repository'), 'icon' => 'code-bracket-square', 'route' => route('projects.repository', $project)] : null,
        'infrastructure' => $user->isAdmin() || $project->resources()->visibleTo($user)->exists() ? ['label' => __('Infrastructure'), 'icon' => 'server-stack', 'route' => route('projects.infrastructure', $project)] : null,
        'access' => ['label' => __('Access'), 'icon' => 'key', 'route' => route('projects.access', $project)],
        'backups' => $user->isAdmin() ? ['label' => __('Backups'), 'icon' => 'archive-box-arrow-down', 'route' => route('projects.backups', $project)] : null,
        'settings' => $user->can('manage', $project) ? ['label' => __('Settings'), 'icon' => 'cog-6-tooth', 'route' => route('projects.settings', $project)] : null,
    ]);
@endphp

<nav
    class="studio-subnav flex h-10 items-stretch gap-1 overflow-x-auto px-3 max-md:px-1"
    aria-label="{{ __('Project') }}"
    x-data="{
        edges() {
            const start = this.$el.scrollLeft > 4, end = this.$el.scrollLeft + this.$el.clientWidth < this.$el.scrollWidth - 4;
            this.$el.dataset.overflow = start && end ? 'both' : (start ? 'start' : (end ? 'end' : ''));
        },
    }"
    x-init="$el.querySelector('[aria-current]')?.scrollIntoView({ inline: 'nearest', block: 'nearest' }); $nextTick(() => edges())"
    x-on:scroll.passive="edges()"
    x-on:resize.window.debounce.100ms="edges()"
>
    @foreach ($tabs as $key => $tab)
        <a href="{{ $tab['route'] }}" wire:navigate @class([
            '-mb-px flex shrink-0 items-center gap-1.5 border-b-2 px-2.5 text-sm whitespace-nowrap transition',
            'border-zinc-900 font-medium text-zinc-900 dark:border-white dark:text-white' => $key === $current,
            'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-800 dark:hover:border-zinc-600 dark:hover:text-zinc-200' => $key !== $current,
        ]) @if ($key === $current) aria-current="page" @endif>
            <flux:icon :name="$tab['icon']" variant="micro" class="size-4 opacity-80" />{{ $tab['label'] }}
        </a>
    @endforeach
</nav>
