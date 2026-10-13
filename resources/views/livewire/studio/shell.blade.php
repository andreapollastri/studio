<div
    class="studio-shell flex h-full min-h-0"
    {{-- The right pane full screen: conversations and chat hide, and keep their state. --}}
    {{-- Below xl, view says which panel a phone shows, and whether the pane slides over on a tablet (resources/css/app.css). --}}
    x-data="{ wide: false, view: @js($conversation ? 'chat' : (auth()->user()->can('chat', $project) ? 'list' : 'pane')) }"
    x-init="
        $watch('wide', value => { if (value) view = 'pane'; $dispatch('studio-pane-wide-changed', { wide: value }) });
        $watch('view', value => { if (value !== 'pane') wide = false; $dispatch('studio-view-changed', { view: value }) });
        $nextTick(() => $dispatch('studio-view-changed', { view }));
    "
    x-bind:data-view="view"
    x-bind:data-wide="wide"
    x-on:studio-pane-toggle.window="wide = ! wide"
    x-on:studio-view.window="view = $event.detail.view === 'pane' && view === 'pane' ? 'chat' : $event.detail.view"
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

    <x-slot:actions>
        {{-- Tablet: the pane is hidden next to the chat, this slides it over; a phone has the tab bar. --}}
        <div class="hidden shrink-0 md:block xl:hidden" x-data="{ open: false }" x-on:studio-view-changed.window="open = $event.detail.view === 'pane'">
            <flux:button size="sm" variant="ghost" icon="window" x-on:click="$dispatch('studio-view', { view: 'pane' })" x-bind:class="open && 'bg-zinc-100 dark:bg-zinc-800'" :aria-label="__('Preview, changes, terminal and files')">{{ __('Workspace') }}</flux:button>
        </div>
    </x-slot:actions>

    <div class="studio-backdrop" x-on:click="view = 'chat'" aria-hidden="true"></div>

    {{-- Conversations --}}
    <aside x-show="! wide" class="studio-list flex w-64 shrink-0 flex-col border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-center justify-between px-3 py-3">
            <flux:heading size="sm">{{ __('Conversations') }}</flux:heading>
            @can('chat', $project)
                @if ($sandboxes)
                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="xs" icon="plus" icon:trailing="chevron-down" variant="subtle">{{ __('New') }}</flux:button>
                        <flux:menu>
                            <flux:menu.item icon="chat-bubble-left-right" wire:click="newConversation">{{ __('Conversation in my workspace') }}</flux:menu.item>
                            <flux:menu.item icon="beaker" wire:click="newSandbox">{{ __('Conversation in a sandbox') }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                @else
                    <flux:button size="xs" icon="plus" variant="subtle" wire:click="newConversation">{{ __('New') }}</flux:button>
                @endif
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
    <section x-show="! wide" class="studio-chat flex min-w-0 flex-1 flex-col">
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
                    <div class="flex flex-wrap justify-center gap-2">
                        <flux:button variant="primary" icon="chat-bubble-left-right" wire:click="newConversation">{{ __('New conversation') }}</flux:button>
                        @if ($sandboxes)
                            <flux:button icon="beaker" wire:click="newSandbox">{{ __('New in a sandbox') }}</flux:button>
                        @endif
                    </div>
                    @if ($sandboxes)
                        <flux:text class="max-w-md text-xs">{{ __('A sandbox is a copy of your workspace on its own branch, with its own database and preview: several can work at once, and you merge the one you like.') }}</flux:text>
                    @endif
                @else
                    <flux:heading size="lg">{{ __('Follow the project') }}</flux:heading>
                    <flux:text class="max-w-md">{{ __('The site on the right is the deploy branch as it runs today. The team works in its own conversations.') }}</flux:text>
                @endcan
            </div>
        @endif
    </section>

    {{-- Preview / changes / terminal / files --}}
    <aside class="studio-pane hidden w-[30rem] shrink-0 border-s border-zinc-200 dark:border-zinc-700 xl:flex" x-bind:class="{ 'flex! w-auto! min-w-0 flex-1 border-s-0': wide }">
        <livewire:studio.side-pane :workspace="$workspace" :sandbox="$paneSandbox" :sandbox-url="$paneSandboxUrl" :key="'pane-'.$workspace->uuid.'-'.$paneSandbox" />
    </aside>

    {{-- Phone: one panel at a time --}}
    <nav class="studio-tabbar" aria-label="{{ __('Project') }}">
        @foreach (['list' => ['icon' => 'queue-list', 'label' => __('Conversations')], 'chat' => ['icon' => 'chat-bubble-left-right', 'label' => __('Chat')], 'pane' => ['icon' => 'window', 'label' => __('Workspace')]] as $key => $entry)
            <button
                type="button"
                class="flex flex-col items-center justify-center gap-0.5 text-[11px] font-medium transition"
                x-on:click="view = @js($key)"
                x-bind:class="view === @js($key) ? 'text-zinc-900 dark:text-white' : 'text-zinc-400 dark:text-zinc-500'"
                x-bind:aria-current="view === @js($key) && 'page'"
            >
                <flux:icon :name="$entry['icon']" class="size-5" />
                {{ $entry['label'] }}
            </button>
        @endforeach
    </nav>
</div>
