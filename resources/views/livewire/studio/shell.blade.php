<div
    class="flex h-full min-h-0"
    {{-- The right pane full screen: conversations and chat hide, and keep their state. --}}
    x-data="{ wide: false }"
    x-init="$watch('wide', value => $dispatch('studio-pane-wide-changed', { wide: value }))"
    x-on:studio-pane-toggle.window="wide = ! wide"
>
    <x-slot:header>
        <div class="flex min-w-0 items-center gap-3">
            <span class="truncate text-sm font-medium">{{ $project->name }}</span>
            @can('chat', $project)
                {{-- Its own component: the header is drawn by the layout, outside this page's component. --}}
                <livewire:studio.workspace-status :workspace="$workspace" :key="'status-'.$workspace->uuid" />
            @endcan
        </div>
    </x-slot:header>

    {{-- Conversations --}}
    <aside x-show="! wide" class="flex w-64 shrink-0 flex-col border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-center justify-between px-3 py-3">
            <flux:heading size="sm">{{ __('Conversations') }}</flux:heading>
            @can('chat', $project)
                <flux:button size="xs" icon="plus" variant="subtle" wire:click="newConversation">{{ __('New') }}</flux:button>
            @endcan
        </div>
        <livewire:studio.conversation-list :project="$project" :workspace="$workspace" :current="$conversation?->uuid" :key="'conversations-'.$workspace->uuid.'-'.$conversation?->uuid" />
        <div class="border-t border-zinc-200 p-3 text-xs text-zinc-500 dark:border-zinc-700">
            @if ($project->dashboardUrl())
                <flux:link :href="$project->dashboardUrl()" target="_blank" class="text-xs">{{ __('Larapilot dashboard on :host', ['host' => $project->siteHost()]) }}</flux:link>
            @else
                {{ __('The project site is not online yet.') }}
            @endif
        </div>
    </aside>

    {{-- Chat --}}
    <section x-show="! wide" class="flex min-w-0 flex-1 flex-col">
        @if ($missing)
            <div class="flex flex-1 flex-col items-center justify-center gap-4 p-10 text-center">
                <flux:icon.link class="size-8 text-zinc-400" />
                <flux:heading size="lg">{{ __('Connect your accounts first') }}</flux:heading>
                <flux:text class="max-w-md">
                    @if (in_array('git', $missing, true))
                        {{ __('Your workspace clones and pushes with your own :provider token.', ['provider' => \App\Git\Providers\GitProvider::current()->label()]) }}
                    @endif
                    @if (in_array('claude', $missing, true))
                        {{ __('The chat runs Claude Code on your own subscription or API key.') }}
                    @endif
                </flux:text>
                <flux:button :href="route('connections.edit')" variant="primary" icon="link" wire:navigate>{{ __('Open Settings → Connections') }}</flux:button>
            </div>
        @elseif ($conversation)
            <livewire:studio.chat :conversation="$conversation" :key="'chat-'.$conversation->uuid" />
        @else
            <div class="flex flex-1 flex-col items-center justify-center gap-4 p-10 text-center">
                @can('chat', $project)
                    <flux:heading size="lg">{{ __('Talk to the project') }}</flux:heading>
                    <flux:text class="max-w-md">
                        {{ __('Every conversation is a Claude Code session in your workspace, with the Larapilot skills. Ask, approve, watch the preview next to it.') }}
                    </flux:text>
                    <flux:button variant="primary" icon="chat-bubble-left-right" wire:click="newConversation">{{ __('New conversation') }}</flux:button>
                @else
                    <flux:heading size="lg">{{ __('Follow the project') }}</flux:heading>
                    <flux:text class="max-w-md">{{ __('The site on the right is the deploy branch as it runs today. The team works in its own conversations.') }}</flux:text>
                @endcan
            </div>
        @endif
    </section>

    {{-- Preview / changes / terminal / files --}}
    <aside class="hidden w-[30rem] shrink-0 border-s border-zinc-200 dark:border-zinc-700 xl:flex" x-bind:class="{ 'flex! w-auto! min-w-0 flex-1 border-s-0': wide }">
        <livewire:studio.side-pane :workspace="$workspace" :key="'pane-'.$workspace->uuid" />
    </aside>
</div>
