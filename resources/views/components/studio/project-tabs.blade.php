@props(['project', 'current' => 'workspace'])

{{--
    The tabs of a project, in the header of each of its pages: the workspace (chat,
    preview, changes), its repository on the git host, the infrastructure attached
    to it, the passwords, SSH connections and folders tied to it, its settings.
    A tab shows only to who has something there.
--}}
@php
    $user = auth()->user();
    $tabs = array_filter([
        'workspace' => ['label' => __('Workspace'), 'icon' => 'chat-bubble-left-right', 'route' => route('studio.show', $project)],
        'repository' => $user->can('chat', $project) ? ['label' => __('Repository'), 'icon' => 'code-bracket-square', 'route' => route('projects.repository', $project)] : null,
        'infrastructure' => $user->isAdmin() || $project->resources()->visibleTo($user)->exists() ? ['label' => __('Infrastructure'), 'icon' => 'server-stack', 'route' => route('projects.infrastructure', $project)] : null,
        'access' => ['label' => __('Access'), 'icon' => 'key', 'route' => route('projects.access', $project)],
        'settings' => $user->can('manage', $project) ? ['label' => __('Settings'), 'icon' => 'cog-6-tooth', 'route' => route('projects.settings', $project)] : null,
    ]);
@endphp

<nav class="flex min-w-0 items-center" aria-label="{{ __('Project') }}">
    {{-- Desktop and tablet: the tabs in a row --}}
    <div class="flex items-center gap-0.5 max-md:hidden">
        @foreach ($tabs as $key => $tab)
            <a href="{{ $tab['route'] }}" wire:navigate @class([
                'flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium whitespace-nowrap transition',
                'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white' => $key === $current,
                'text-zinc-500 hover:bg-zinc-50 hover:text-zinc-800 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-200' => $key !== $current,
            ]) @if ($key === $current) aria-current="page" @endif>
                <flux:icon :name="$tab['icon']" variant="micro" class="size-3.5" />{{ $tab['label'] }}
            </a>
        @endforeach
    </div>

    {{-- Phone: the tab open, and the others in a menu --}}
    <div class="md:hidden">
        <flux:dropdown position="bottom" align="start">
            <flux:button size="xs" variant="ghost" :icon="$tabs[$current]['icon'] ?? 'squares-2x2'" icon:trailing="chevron-down">{{ $tabs[$current]['label'] ?? __('Project') }}</flux:button>
            <flux:menu>
                @foreach ($tabs as $key => $tab)
                    <flux:menu.item :icon="$tab['icon']" :href="$tab['route']" wire:navigate>{{ $tab['label'] }}</flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    </div>
</nav>
