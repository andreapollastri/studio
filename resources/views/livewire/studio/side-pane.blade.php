<div class="flex h-full w-full min-h-0 flex-col"
    x-data="{ ask: {}, confirm() { this.$flux.modal('git-confirm').close(); this.$wire.call(this.ask.method, ...(this.ask.args ?? [])) } }"
    x-on:git-confirm="ask = $event.detail; $flux.modal('git-confirm').show()">
    <div class="flex items-center gap-0.5 overflow-x-auto border-b border-zinc-200 px-2 py-2 dark:border-zinc-700">
        @foreach (array_filter(['doc' => $hasWorkspace ? __('Doc') : null, 'preview' => __('Preview'), 'changes' => $hasWorkspace ? __('Git') : null, 'terminal' => $canUseTerminal ? __('Terminal') : null, 'files' => $canUseTerminal ? __('Files') : null, 'db' => $canUseTerminal ? __('Db') : null]) as $key => $label)
            <button
                type="button"
                wire:click="setTab('{{ $key }}')"
                @class([
                    'shrink-0 rounded-md px-2.5 py-1 text-sm transition',
                    'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' => $tab === $key,
                    'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => $tab !== $key,
                ])
            >{{ $label }}</button>
        @endforeach
        <flux:spacer />
        @if ($sandbox)
            <flux:tooltip :content="__('This conversation works in its sandbox: the preview, changes, terminal and files are the sandbox\'s.')">
                <flux:badge size="sm" color="violet" icon="beaker" class="me-1 shrink-0">{{ __('Sandbox') }}</flux:badge>
            </flux:tooltip>
        @endif
        @if ($tab === 'preview' && ($workspace->isRunning() ? $appUrl : $previewUrl))
            <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square" :href="$workspace->isRunning() ? $appUrl : $previewUrl" target="_blank">{{ $workspace->isRunning() ? __('Open') : __('Open the site') }}</flux:button>
        @elseif ($tab === 'changes')
            <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="setChangesView(@js($changesView))">{{ __('Refresh') }}</flux:button>
        @endif
        <div class="studio-pane-wide-toggle shrink-0" x-data="{ wide: false }" x-on:studio-pane-wide-changed.window="wide = $event.detail.wide">
            <flux:button size="xs" variant="ghost" icon="arrows-pointing-out" x-show="! wide" x-on:click="$dispatch('studio-pane-toggle')" :aria-label="__('Full screen')" :title="__('Full screen')" />
            <flux:button size="xs" variant="ghost" icon="arrows-pointing-in" x-show="wide" x-cloak x-on:click="$dispatch('studio-pane-toggle')" :aria-label="__('Exit full screen')" :title="__('Exit full screen')" />
        </div>
    </div>

    <div @class(['relative min-h-0 flex-1', 'overflow-hidden' => in_array($tab, ['files', 'db'], true), 'overflow-auto' => ! in_array($tab, ['files', 'db'], true)])>
        @if ($filesMounted)
            {{-- Mounted on first use and kept, hidden, so open editor tabs survive switching panes. --}}
            <div @class(['absolute inset-0', 'hidden' => $tab !== 'files'])>
                <livewire:studio.files :workspace="$workspace" :sandbox="$sandbox" :key="'files-'.$workspace->id.'-'.$sandbox" />
            </div>
        @endif
        @if ($dbMounted && $canUseTerminal)
            {{-- The database client of Tools → Databases on the mode's database, kept, hidden, while the pane shows something else. --}}
            <div @class(['absolute inset-0 flex flex-col', 'hidden' => $tab !== 'db'])>
                <div class="border-b border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    @include('livewire.studio.partials.db-modes')
                </div>
                <div class="min-h-0 flex-1">
                    @if ($dbMode !== 'local' && ! $this->remoteConfirmed())
                        <div class="flex flex-wrap items-center gap-2 p-4 text-sm">
                            <flux:text>{{ __('As for Databases, confirm your password to open this database.') }}</flux:text>
                            <flux:button size="xs" variant="primary" icon="lock-closed" wire:click="confirmPassword">{{ __('Confirm') }}</flux:button>
                        </div>
                    @elseif ($dbError)
                        <div class="space-y-2 p-4 text-sm">
                            <flux:text>{{ $dbError }}</flux:text>
                            <flux:button size="xs" icon="arrow-path" wire:click="setDbMode('{{ $dbMode }}')">{{ __('Try again') }}</flux:button>
                        </div>
                    @elseif ($dbOpen && $dbOpen->driver->isRedis())
                        <livewire:studio.pane-redis :connection="$dbOpen" :key="'pane-db-'.$dbOpen->uuid" />
                    @elseif ($dbOpen)
                        <livewire:studio.pane-database :connection="$dbOpen" :key="'pane-db-'.$dbOpen->uuid" />
                    @endif
                </div>
            </div>
        @endif
        @if ($shellMounted && $canUseTerminal)
            {{-- The workspace's shell, on a real terminal through the gateway, kept alive while the pane shows something else. --}}
            <div x-data="workspaceShell" @class(['absolute inset-0 flex flex-col', 'hidden' => $tab !== 'terminal' || $terminalMode !== 'local'])>
                <div class="border-b border-zinc-200 p-3 dark:border-zinc-700">
                    @include('livewire.studio.partials.terminal-modes')
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($shortcuts as $shortcut)
                            <flux:button size="xs" variant="filled" x-on:click="type({{ \Illuminate\Support\Js::from($shortcut['command']) }})" :title="$shortcut['command']">{{ $shortcut['label'] }}</flux:button>
                        @endforeach
                    </div>
                    <flux:text class="mt-1 text-xs">{{ $sandbox
                        ? __('A shell in the sandbox, as you: prompts, passwords and Ctrl-C work as over SSH. A shortcut types its command into it.')
                        : __('A shell in your workspace, as you: prompts, passwords and Ctrl-C work as over SSH. A shortcut types its command into it.') }}</flux:text>
                </div>
                <div class="relative min-h-0 flex-1 bg-zinc-950">
                    <div wire:ignore x-ref="box" class="absolute inset-0 p-2"></div>
                    <div x-show="state === 'connecting'" x-cloak class="absolute inset-x-0 top-3 text-center text-xs text-zinc-500">{{ __('Opening a shell…') }}</div>
                    <div x-show="['ended', 'stopped', 'error'].includes(state)" x-cloak class="absolute inset-x-0 bottom-0 flex flex-wrap items-center gap-2 border-t border-zinc-800 bg-zinc-900/95 px-3 py-2 text-xs text-zinc-300">
                        <span class="min-w-0 flex-1">
                            <span x-show="state === 'ended'">{{ __('The shell ended') }}<span x-show="exitCode !== null" x-text="' (exit ' + exitCode + ')'"></span>.</span>
                            <span x-show="state === 'stopped'">{{ __('The workspace is not running: start it, then open a shell.') }}</span>
                            <span x-show="state === 'error'" class="text-red-400" x-text="error"></span>
                        </span>
                        <flux:button size="xs" variant="primary" icon="command-line" x-on:click="restart()">{{ __('New shell') }}</flux:button>
                    </div>
                </div>
                @include('livewire.ssh.partials.keybar', ['visible' => "state === 'ready'"])
            </div>
        @endif
        @if ($developMounted && $canUseDevelop)
            {{-- The project's own site (the deploy branch's), as its user: a shell through the gateway and the helper --}}
            <div @class(['absolute inset-0 flex flex-col', 'hidden' => $tab !== 'terminal' || $terminalMode !== 'develop'])>
                <div class="border-b border-zinc-200 p-3 dark:border-zinc-700">
                    @include('livewire.studio.partials.terminal-modes')
                    @if (! $this->remoteConfirmed())
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <flux:text>{{ __('As for the SSH hosts, confirm your password to open a shell on the site.') }}</flux:text>
                            <flux:button size="xs" variant="primary" icon="lock-closed" wire:click="confirmPassword">{{ __('Confirm') }}</flux:button>
                        </div>
                    @else
                        <flux:text class="text-xs">
                            {{ __('A shell on the site of :project, :host, as its user :user, in its app, with its PHP :php. What changes here is live for whoever opens the site; your own workspace is Local.', ['project' => $workspace->project->name, 'host' => $workspace->project->siteHost(), 'user' => 'prj-'.$workspace->project->slug, 'php' => $workspace->project->php_version]) }}
                        </flux:text>
                    @endif
                </div>
                @if ($this->remoteConfirmed())
                    <div x-data="workspaceShell('developTicket')" class="flex min-h-0 flex-1 flex-col">
                        <div class="relative min-h-0 flex-1 bg-zinc-950">
                            <div wire:ignore x-ref="box" class="absolute inset-0 p-2"></div>
                            <div x-show="state === 'connecting'" x-cloak class="absolute inset-x-0 top-3 text-center text-xs text-zinc-500">{{ __('Opening a shell on the site…') }}</div>
                            <div x-show="['ended', 'stopped', 'error'].includes(state)" x-cloak class="absolute inset-x-0 bottom-0 flex flex-wrap items-center gap-2 border-t border-zinc-800 bg-zinc-900/95 px-3 py-2 text-xs text-zinc-300">
                                <span class="min-w-0 flex-1">
                                    <span x-show="state === 'ended'">{{ __('The shell ended') }}<span x-show="exitCode !== null" x-text="' (exit ' + exitCode + ')'"></span>.</span>
                                    <span x-show="state === 'stopped'">{{ __('Confirm your password again to open a shell on the site.') }}</span>
                                    <span x-show="state === 'error'" class="text-red-400" x-text="error"></span>
                                </span>
                                <flux:button size="xs" variant="primary" icon="command-line" x-on:click="restart()">{{ __('New shell') }}</flux:button>
                            </div>
                        </div>
                        @include('livewire.ssh.partials.keybar', ['visible' => "state === 'ready'"])
                    </div>
                @endif
            </div>
        @endif
        @if ($remoteMounted && $canUseRemote)
            {{-- The remote terminals: the project's SSH hosts through the gateway, kept alive while the pane shows something else. --}}
            <div x-data="sshTerminals" @class(['absolute inset-0 flex flex-col', 'hidden' => $tab !== 'terminal' || $terminalMode !== 'remote'])>
                <div class="border-b border-zinc-200 p-3 dark:border-zinc-700">
                    @include('livewire.studio.partials.terminal-modes')
                    @if (! $this->remoteConfirmed())
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <flux:text>{{ __('As on the SSH page, confirm your password to open a session.') }}</flux:text>
                            <flux:button size="xs" variant="primary" icon="lock-closed" wire:click="confirmPassword">{{ __('Confirm') }}</flux:button>
                        </div>
                    @elseif ($this->remoteConnections->isEmpty())
                        <flux:text class="text-sm">{{ __('No SSH connection tied to this project. Add one on the SSH page (with the project chosen), or from a Cipi app in Infrastructure.') }}</flux:text>
                    @else
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($this->remoteConnections as $row)
                                <flux:button size="xs" :variant="$row['ready'] ? 'filled' : 'ghost'" icon="command-line" x-on:click="open('{{ $row['connection']->uuid }}')" :title="$row['connection']->address()" wire:key="remote-{{ $row['connection']->uuid }}">
                                    {{ $row['connection']->name }}@unless ($row['ready']) · {{ __('no credential') }}@endunless
                                </flux:button>
                            @endforeach
                        </div>
                        <flux:text class="mt-1 text-xs">{{ __('A full shell on the host, with your own key or password. Each button opens one more session.') }}</flux:text>
                    @endif
                </div>
                @include('livewire.ssh.partials.terminals', ['class' => 'min-h-0', 'empty' => __('Pick a host above to open a terminal on it.')])
            </div>
        @endif
        @if ($tab === 'doc')
            @php($docPage = $this->doc)
            @if ($docPage['path'] !== null)
                <div class="sticky top-0 z-10 flex items-center gap-1 border-b border-zinc-200 bg-white/95 px-3 py-1 backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95">
                    @if (count($this->docs) > 1)
                        <flux:dropdown position="bottom" align="start">
                            <flux:button size="xs" variant="ghost" icon="document-text" icon:trailing="chevron-down" class="font-mono">{{ $docPage['path'] }}</flux:button>
                            <flux:menu>
                                @foreach ($this->docs as $docName)
                                    <flux:menu.item wire:click="setDoc({{ \Illuminate\Support\Js::from($docName) }})" :icon="$docName === $docPage['path'] ? 'check' : null" class="font-mono">{{ $docName }}</flux:menu.item>
                                @endforeach
                            </flux:menu>
                        </flux:dropdown>
                    @else
                        <span class="flex items-center gap-1 px-2 font-mono text-xs text-zinc-500"><flux:icon.document-text variant="micro" class="size-3.5" />{{ $docPage['path'] }}</span>
                    @endif
                    <flux:spacer />
                    <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="setDoc({{ \Illuminate\Support\Js::from((string) $docPage['path']) }})" :title="__('Refresh')" />
                </div>
            @endif
            @if ($docPage['html'] !== null)
                <article class="studio-doc prose prose-sm max-w-none px-6 py-5 dark:prose-invert prose-pre:bg-zinc-100 prose-pre:text-zinc-900 prose-img:inline prose-img:my-0 dark:prose-pre:bg-zinc-800 dark:prose-pre:text-zinc-100">
                    {!! $docPage['html'] !!}
                </article>
            @else
                <div class="p-6 text-sm text-zinc-500">{{ $docPage['error'] }}</div>
            @endif
        @elseif ($tab === 'preview')
            @if ($workspace->isRunning() && $appUrl)
                <iframe src="{{ $appUrl }}" title="{{ $sandbox ? __('Sandbox preview') : __('Workspace preview') }}" class="h-full w-full bg-white" referrerpolicy="no-referrer" sandbox="allow-same-origin allow-scripts allow-forms allow-popups"></iframe>
            @elseif ($previewUrl)
                <iframe src="{{ $previewUrl }}" title="{{ __('Project site') }}" class="h-full w-full bg-white" referrerpolicy="no-referrer" sandbox="allow-same-origin allow-scripts allow-forms allow-popups"></iframe>
            @else
                <div class="p-6 text-sm text-zinc-500">
                    {{ $workspace->isRunning() ? __('This workspace does not expose a preview URL.') : __('The preview appears when the workspace is running.') }}
                </div>
            @endif
        @elseif ($tab === 'changes')
            <div class="flex h-full min-h-0 flex-col text-sm">
                <div class="flex flex-wrap items-center gap-1 border-b border-zinc-200 px-3 py-1.5 dark:border-zinc-700">
                    @foreach (['tree' => __('Working tree'), 'history' => __('History'), 'branches' => __('Branches'), 'tags' => __('Tags'), 'stashes' => __('Stashes')] as $key => $label)
                        <button type="button" wire:click="setChangesView('{{ $key }}')" @class(['inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs', 'bg-zinc-200 font-medium dark:bg-zinc-700' => $changesView === $key, 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => $changesView !== $key])>
                            {{ $label }}
                            @if ($key === 'tree' && $git && count($git['files'] ?? []) > 0)<span class="rounded-full bg-amber-100 px-1.5 text-[10px] font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-300">{{ count($git['files']) }}</span>@endif
                            @if ($key === 'stashes' && $git && ($git['stashes'] ?? 0) > 0)<span class="rounded-full bg-zinc-200 px-1.5 text-[10px] font-medium dark:bg-zinc-600">{{ $git['stashes'] }}</span>@endif
                        </button>
                    @endforeach
                    @if ($git && $git['branch'])
                        <span class="ml-auto flex items-center gap-1.5">
                            <span class="font-mono text-xs text-zinc-500" title="{{ $git['upstream'] ? '→ '.$git['upstream'] : __('Not pushed yet') }}">{{ $git['branch'] }}</span>
                            @if ($git['behind'] > 0)<span class="text-xs text-amber-600" title="{{ __('Commits to pull') }}">↓{{ $git['behind'] }}</span>@endif
                            @if ($git['ahead'] > 0)<span class="text-xs text-blue-600" title="{{ __('Commits to push') }}">↑{{ $git['ahead'] }}</span>@endif
                            @if ($canUseTerminal)
                                <flux:button size="xs" variant="ghost" icon="arrow-down" wire:click="pullRemote" wire:loading.attr="disabled" wire:target="pullRemote,pushRemote,forcePush,fetch" :title="__('Pull: bring in the commits of the remote branch')">{{ __('Pull') }}</flux:button>
                                <flux:button size="xs" variant="ghost" icon="arrow-up" wire:click="pushRemote" wire:loading.attr="disabled" wire:target="pullRemote,pushRemote,forcePush,fetch" :title="$git['upstream'] ? __('Push: send the commits of this branch to the remote') : __('Publish the branch on the remote')">{{ $git['upstream'] ? __('Push') : __('Publish') }}</flux:button>
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="xs" variant="ghost" icon="ellipsis-vertical" :aria-label="__('More')" />
                                    <flux:menu>
                                        <flux:menu.item icon="arrow-down-tray" wire:click="fetch">{{ __('Fetch') }}</flux:menu.item>
                                        @if ($git['upstream'])
                                            <flux:menu.item icon="exclamation-triangle" x-on:click="$dispatch('git-confirm', @js(['method' => 'forcePush', 'icon' => 'push', 'danger' => true, 'title' => __('Force push :branch?', ['branch' => $git['branch']]), 'body' => __('The remote branch is replaced by this one, for example after an amend or a reset. Commits only on the remote are lost; if somebody pushed meanwhile, git refuses.'), 'subject' => $git['upstream'], 'confirm' => __('Force push')]))">{{ __('Force push…') }}</flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </span>
                    @elseif ($log && $log['branch'])
                        <span class="ml-auto font-mono text-xs text-zinc-500">{{ $log['branch'] }}</span>
                    @endif
                </div>
                <div class="min-h-0 flex-1 overflow-auto p-3">
                    @if ($gitError)
                        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-3"><flux:callout.text class="whitespace-pre-wrap">{{ $gitError }}</flux:callout.text></flux:callout>
                    @elseif ($gitNotice)
                        <flux:callout variant="success" icon="check-circle" class="mb-3"><flux:callout.text>{{ $gitNotice }}</flux:callout.text></flux:callout>
                    @endif

                    @include('livewire.studio.partials.git.'.$changesView)
                </div>
            </div>
        @endif
    </div>
    {{-- The confirmations and dialogs of the git actions --}}
    @if ($canUseTerminal && $tab === 'changes')
        @include('livewire.studio.partials.git.modals')
    @endif
</div>
