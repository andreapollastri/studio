<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        @vite('resources/js/editor.js')
    </head>
    <body class="h-screen overflow-hidden max-xl:h-dvh bg-white text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100">
        <div class="flex h-full flex-col">
            <header class="flex h-12 shrink-0 items-center gap-3 border-b border-zinc-200 px-3 dark:border-zinc-700 max-md:gap-2 max-md:px-2">
                <a href="{{ route('home') }}" class="flex items-center gap-2 max-md:shrink-0" wire:navigate>
                    <x-app-logo-icon class="size-6" />
                    <span class="text-sm font-semibold max-md:hidden">Studio</span>
                </a>

                <flux:separator vertical class="my-2" />

                {{ $header ?? '' }}

                <flux:spacer />

                {{ $actions ?? '' }}

                <flux:dropdown position="bottom" align="end">
                    {{-- A phone has no room for the name and the chevron next to the project and its workspace: the avatar alone --}}
                    <flux:profile :name="auth()->user()->name" :initials="auth()->user()->initials()" :avatar="auth()->user()->avatar_url" icon:trailing="chevron-down" class="!py-1 max-md:[&>div:not(:first-child)]:hidden max-md:[&>span]:hidden" />
                    <flux:menu>
                        <flux:menu.item :href="route('home')" icon="home" wire:navigate>{{ __('Home') }}</flux:menu.item>
                        <flux:menu.item :href="route('projects.index')" icon="folder" wire:navigate>{{ __('Projects') }}</flux:menu.item>
                        <flux:menu.item :href="route('git')" icon="code-bracket-square" wire:navigate>{{ __('Git') }}</flux:menu.item>
                        <flux:menu.separator />
                        <flux:menu.item :href="route('connections.edit')" icon="user-circle" wire:navigate>{{ __('My accounts') }}</flux:menu.item>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                        <flux:menu.separator />
                        <form method="POST" action="{{ route('logout') }}" class="w-full">
                            @csrf
                            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer">{{ __('Log out') }}</flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            </header>

            <main class="min-h-0 flex-1">
                {{ $slot }}
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
