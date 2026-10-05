<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        @vite('resources/js/editor.js')
    </head>
    <body class="h-screen overflow-hidden bg-white text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100">
        <div class="flex h-full flex-col">
            <header class="flex h-12 shrink-0 items-center gap-3 border-b border-zinc-200 px-3 dark:border-zinc-700">
                <a href="{{ route('projects.index') }}" class="flex items-center gap-2" wire:navigate>
                    <x-app-logo-icon class="size-6" />
                    <span class="text-sm font-semibold">Studio</span>
                </a>

                <flux:separator vertical class="my-2" />

                {{ $header ?? '' }}

                <flux:spacer />

                <flux:dropdown position="bottom" align="end">
                    <flux:profile :name="auth()->user()->name" :initials="auth()->user()->initials()" :avatar="auth()->user()->avatar_url" icon:trailing="chevron-down" class="!py-1" />
                    <flux:menu>
                        <flux:menu.item :href="route('projects.index')" icon="folder" wire:navigate>{{ __('Projects') }}</flux:menu.item>
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
