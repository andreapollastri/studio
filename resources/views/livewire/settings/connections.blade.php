<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Accounts')" :subheading="__('What you bring to your work: your Claude plans and keys, your Cursor subscriptions, your accounts on the git hosts, your SSH keys. Your workspaces clone and push as you, and each conversation runs on the agent you choose.')">
        <div class="my-6 w-full space-y-10">

            <flux:radio.group wire:model.live="agent" :label="__('New conversations')" :description="__('Your default. Every person chooses their own, and a conversation can still switch in Model & mode.')">
                <flux:radio value="claude" label="Claude" />
                <flux:radio value="cursor" label="Cursor" />
            </flux:radio.group>

            <flux:radio.group wire:model.live="tab" variant="segmented" class="max-sm:w-full">
                <flux:radio value="claude" label="Claude" icon="sparkles" />
                <flux:radio value="cursor" label="Cursor" icon="command-line" />
                <flux:radio value="git" :label="__('Git')" icon="code-bracket-square" />
                <flux:radio value="ssh" :label="__('SSH keys')" icon="key" />
            </flux:radio.group>

            @if ($tab === 'git')
                <livewire:settings.git-accounts />
                @if ($user->handle)
                    <flux:text class="text-xs">{{ __('Your previews live at :host', ['host' => $user->handle.'-<project>.'.config('studio.domain')]) }}</flux:text>
                @endif
            @elseif ($tab === 'ssh')
                <livewire:settings.ssh-keys />
            @elseif ($tab === 'cursor')
            {{-- Cursor subscriptions: the official CLI, in the person's home or, in development, on this computer. --}}
            @php($onServer = \App\Cursor\CursorLogin::onServer())
            <div class="space-y-4" x-data="cursorLogin" @if ($onServer && $cursorAccounts->isNotEmpty()) wire:init="refreshCursorSubscriptions" @endif>
                <div class="flex flex-wrap items-center gap-3">
                    <flux:heading size="lg">Cursor</flux:heading>
                    <flux:badge size="sm" :color="$user->hasCursor() ? 'green' : 'yellow'">{{ trans_choice(':count subscription|:count subscriptions', $cursorAccounts->count()) }}</flux:badge>
                </div>
                <flux:text>
                    @if ($onServer)
                        {{ __('Your Cursor subscriptions, as many as you have, each one yours alone. Signing in runs Cursor’s own agent login in a folder of your home on this server: Studio never sees or keeps it, and the use is billed to that subscription. A subscription is never shared with a colleague. A conversation runs on Cursor when you choose it.') }}
                    @else
                        {{ __('Your Cursor subscriptions, as many as you have, each one yours alone. Signing in runs Cursor’s own agent login in a terminal on this computer, in that subscription’s folder: Studio never sees or keeps it, and the use is billed to that subscription. A subscription is never shared with a colleague.') }}
                    @endif
                </flux:text>

                @if ($cursorAccounts->isNotEmpty())
                    <div class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                        @foreach ($cursorAccounts as $account)
                            <div class="px-4 py-3" wire:key="cursor-{{ $account->uuid }}">
                                @if ($editing_cursor === $account->uuid)
                                    <form wire:submit="saveCursor" class="flex flex-col gap-3">
                                        <flux:input wire:model="edit_cursor_name" :label="__('Name')" />
                                        <div class="flex gap-2">
                                            <flux:button type="submit" size="sm" variant="primary">{{ __('Save') }}</flux:button>
                                            <flux:button size="sm" variant="ghost" wire:click="cancelCursor">{{ __('Cancel') }}</flux:button>
                                        </div>
                                    </form>
                                @else
                                    <div class="flex flex-wrap items-center gap-3">
                                        <flux:icon name="command-line" class="size-5 shrink-0 text-sky-500" />
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="font-medium">{{ $account->name }}</span>
                                                <flux:badge size="sm" color="sky">{{ __('subscription') }}</flux:badge>
                                                @if ($account->signed_in)
                                                    <flux:badge size="sm" color="green">{{ __('signed in') }}</flux:badge>
                                                @else
                                                    <flux:badge size="sm" color="yellow">{{ __('not signed in') }}</flux:badge>
                                                @endif
                                            </div>
                                            @if ($account->checked_at)
                                                <div class="text-xs text-zinc-500">{{ __('checked :when', ['when' => $account->checked_at->diffForHumans()]) }}</div>
                                            @endif
                                        </div>
                                        <div class="flex flex-wrap items-center gap-1">
                                            <flux:button size="sm" :variant="$account->signed_in ? 'ghost' : 'primary'" icon="command-line" x-on:click="start({{ Js::from($account->uuid) }}); $flux.modal('cursor-login').show()">{{ $account->signed_in ? __('Sign in again') : __('Sign in to Cursor') }}</flux:button>
                                            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refreshCursor({{ Js::from($account->uuid) }})" :aria-label="__('Check')" />
                                            <flux:dropdown position="bottom" align="end">
                                                <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Actions')" />
                                                <flux:menu>
                                                    <flux:menu.item icon="pencil" wire:click="editCursor({{ Js::from($account->uuid) }})">{{ __('Rename') }}</flux:menu.item>
                                                    @if ($account->signed_in)
                                                        <flux:menu.item icon="arrow-right-start-on-rectangle" wire:click="logoutCursor({{ Js::from($account->uuid) }})" wire:confirm="{{ __('Sign out of :account on this server?', ['account' => $account->name]) }}">{{ __('Sign out') }}</flux:menu.item>
                                                    @endif
                                                    <flux:menu.separator />
                                                    <flux:menu.item icon="trash" variant="danger" wire:click="removeCursor({{ Js::from($account->uuid) }})" wire:confirm="{{ __('Remove :account? Its sign-in on this server goes with it.', ['account' => $account->name]) }}">{{ __('Remove') }}</flux:menu.item>
                                                </flux:menu>
                                            </flux:dropdown>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                <form class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
                    x-on:submit.prevent="$wire.addCursor().then((uuid) => { if (uuid) { start(uuid); $flux.modal('cursor-login').show() } })">
                    <flux:heading size="sm">{{ __('Add a Cursor subscription') }}</flux:heading>
                    <flux:input wire:model="new_cursor_name" :label="__('Name')" :placeholder="__('Personal, Work…')" />
                    <flux:text class="text-xs">
                        {{ __('The official Cursor sign-in runs in a folder of its own for this subscription: Studio never sees or keeps it. Your own plan, used by you alone and billed to you. An API key is not this: it is billed apart from the subscription, and Studio does not keep one.') }}
                        @unless ($onServer){{ __('On this computer the terminal below is where agent login runs.') }}@endunless
                    </flux:text>
                    <flux:button type="submit" variant="primary" icon="command-line">{{ __('Add and sign in') }}</flux:button>
                </form>

                <flux:modal name="cursor-login" class="md:w-[48rem]" x-on:close="stop()">
                    <div class="space-y-4">
                        <div>
                            <flux:heading size="lg">{{ __('Sign in to Cursor') }}</flux:heading>
                            <flux:text class="mt-1">{{ $onServer
                                ? __('This is Cursor’s own agent login, in a terminal in your home on the server. Studio shows the terminal and takes no part in the sign-in: it goes to Cursor, never through Studio.')
                                : __('This is Cursor’s own agent login, in a terminal on this computer, in that subscription’s folder. Studio shows the terminal and takes no part in the sign-in: it goes to Cursor, never through Studio.') }}</flux:text>
                        </div>
                        <ol class="list-decimal space-y-1 ps-5 text-sm text-zinc-600 dark:text-zinc-300">
                            <li>{{ __('Open the sign-in page the terminal prints.') }}</li>
                            <li>{{ __('Approve with the Cursor account of this subscription. Sign out of cursor.com first if another account is signed in there.') }}</li>
                            <li>{{ __('Come back here. The terminal finishes on its own when Cursor has accepted the sign-in. Nothing is pasted into Studio.') }}</li>
                        </ol>

                        <div class="flex flex-wrap items-center gap-2">
                            <template x-if="link">
                                <flux:button variant="primary" icon="arrow-top-right-on-square" x-on:click="window.open(link, '_blank', 'noopener'); focusTerminal()">{{ __('Open the sign-in page') }}</flux:button>
                            </template>
                            <flux:text class="text-xs" x-show="state === 'running' && ! link">{{ __('The link appears here when Cursor prints it in the terminal. Clicking it there works too.') }}</flux:text>
                        </div>

                        <div wire:ignore id="cursor-login-terminal" class="h-72 overflow-hidden rounded-lg bg-zinc-950 p-2" x-on:click="focusTerminal()"></div>

                        <div class="flex items-center justify-between gap-3 text-sm">
                            <span x-show="state === 'connecting'" class="flex items-center gap-2 text-zinc-500"><flux:icon.loading class="size-4" /><span x-text="{ ticket: @js(__('Preparing the sign-in…')), terminal: @js(__('Opening the terminal…')), socket: @js(__('Connecting to the server…')), cursor: @js(__('Starting Cursor…')) }[step] ?? @js(__('Starting Cursor…'))"></span></span>
                            <span x-show="state === 'done'" class="flex items-center gap-2 text-green-600"><flux:icon.check-circle class="size-4" />{{ __('Done: you are signed in.') }}</span>
                            <span x-show="state === 'error'" class="text-red-600" x-text="error"></span>
                            <span x-show="state === 'running'" class="text-xs text-zinc-500">{{ __('The terminal is live: Cursor is waiting for you to finish in the browser.') }}</span>
                            <div class="ms-auto flex gap-2">
                                <flux:button size="sm" variant="ghost" x-show="state === 'error'" x-on:click="start()">{{ __('Try again') }}</flux:button>
                                <flux:modal.close><flux:button size="sm" variant="primary">{{ __('Close') }}</flux:button></flux:modal.close>
                            </div>
                        </div>
                    </div>
                </flux:modal>
            </div>
            @else
            {{-- Claude accounts --}}
            @php($onServer = \App\Claude\ClaudeLogin::onServer())
            <div class="space-y-4" x-data="claudeLogin" @if ($onServer && $claudeAccounts->contains(fn ($a) => $a->isSubscription())) wire:init="refreshSubscriptions" @endif>
                <div class="flex flex-wrap items-center gap-3">
                    <flux:heading size="lg">Claude</flux:heading>
                    <flux:badge size="sm" :color="$user->hasClaude() ? 'green' : 'yellow'">{{ trans_choice(':count account|:count accounts', $claudeAccounts->count()) }}</flux:badge>
                </div>
                <flux:text>
                    {{ __('Your subscriptions (Pro, Max, Team) and API keys, as many as you have: a personal plan for your own projects, the company’s for work. The default runs every project unless its group or the project itself picks another.') }}
                </flux:text>

                @if ($claudeAccounts->isNotEmpty())
                    <div class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                        @foreach ($claudeAccounts as $account)
                            @php($default = $user->claude_account_id === $account->id)
                            @php($used = $claudeUsage[$account->id] ?? null)
                            <div class="px-4 py-3" wire:key="claude-{{ $account->uuid }}">
                                @if ($editing === $account->uuid)
                                    <form wire:submit="saveEdit" class="flex flex-col gap-3">
                                        <flux:input wire:model="edit_name" :label="__('Name')" />
                                        @unless ($account->isSubscription())
                                            <flux:input wire:model="edit_token" type="password" viewable :label="__('API key')" :placeholder="__('Paste a new key to replace the current one')" autocomplete="off" />
                                        @endunless
                                        <div class="flex gap-2">
                                            <flux:button type="submit" size="sm" variant="primary">{{ __('Save') }}</flux:button>
                                            <flux:button size="sm" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                                        </div>
                                    </form>
                                @else
                                    <div class="flex flex-wrap items-center gap-3">
                                        <flux:icon :name="$account->isSubscription() ? 'sparkles' : 'key'" @class(['size-5 shrink-0', 'text-violet-500' => $account->isSubscription(), 'text-amber-500' => ! $account->isSubscription()]) />
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="font-medium">{{ $account->name }}</span>
                                                <flux:badge size="sm" :color="$account->isSubscription() ? 'violet' : 'amber'">{{ $account->isSubscription() ? __('subscription') : __('API key') }}</flux:badge>
                                                @if ($default)<flux:badge size="sm" color="blue" icon="star">{{ __('default') }}</flux:badge>@endif
                                                @if ($account->isUsable())
                                                    <flux:badge size="sm" color="green">{{ $account->isSubscription() ? __('signed in') : __('ready') }}</flux:badge>
                                                @else
                                                    <flux:badge size="sm" color="yellow">{{ __('not signed in') }}</flux:badge>
                                                @endif
                                            </div>
                                            <div class="text-xs text-zinc-500">
                                                {{ collect([
                                                    $account->isSubscription() && filled($account->usage['plan'] ?? null) ? ucfirst((string) $account->usage['plan']) : null,
                                                    $account->checked_at && $account->isSubscription() ? __('checked :when', ['when' => $account->checked_at->diffForHumans()]) : null,
                                                    $used && $used['groups'] ? __('default of :groups', ['groups' => implode(', ', $used['groups'])]) : null,
                                                    $used && $used['projects'] ? trans_choice('chosen for :count project|chosen for :count projects', $used['projects']) : null,
                                                ])->filter()->implode(' · ') }}
                                            </div>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-1">
                                            @if ($account->isSubscription())
                                                @if ($onServer)
                                                    <flux:button size="sm" :variant="$account->isUsable() ? 'ghost' : 'primary'" icon="command-line" x-on:click="start({{ Js::from($account->uuid) }}); $flux.modal('claude-login').show()">{{ $account->isUsable() ? __('Sign in again') : __('Sign in to Claude Code') }}</flux:button>
                                                @endif
                                                <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refreshClaude({{ Js::from($account->uuid) }})" :aria-label="__('Check')" />
                                            @endif
                                            <flux:dropdown position="bottom" align="end">
                                                <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Actions')" />
                                                <flux:menu>
                                                    @unless ($default)
                                                        <flux:menu.item icon="star" wire:click="makeDefault({{ Js::from($account->uuid) }})">{{ __('Make it the default') }}</flux:menu.item>
                                                    @endunless
                                                    <flux:menu.item icon="pencil" wire:click="edit({{ Js::from($account->uuid) }})">{{ $account->isSubscription() ? __('Rename') : __('Rename or replace the key') }}</flux:menu.item>
                                                    @if ($account->isSubscription() && $account->signed_in)
                                                        <flux:menu.item icon="arrow-right-start-on-rectangle" wire:click="logoutClaude({{ Js::from($account->uuid) }})" wire:confirm="{{ __('Sign out of :account on this server? The projects that use it stop until you sign in again.', ['account' => $account->name]) }}">{{ __('Sign out') }}</flux:menu.item>
                                                    @endif
                                                    <flux:menu.separator />
                                                    <flux:menu.item icon="trash" variant="danger" wire:click="removeClaude({{ Js::from($account->uuid) }})" wire:confirm="{{ __('Remove :account? Groups and projects that use it go back to your default.', ['account' => $account->name]) }}">{{ __('Remove') }}</flux:menu.item>
                                                </flux:menu>
                                            </flux:dropdown>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Add one: a subscription opens its sign-in right away --}}
                <form class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
                    x-on:submit.prevent="$wire.addClaude().then((uuid) => { if (uuid && $wire.new_kind === 'subscription') { start(uuid); $flux.modal('claude-login').show() } })">
                    <flux:heading size="sm">{{ __('Add a Claude account') }}</flux:heading>
                    <flux:radio.group wire:model.live="new_kind" variant="segmented">
                        <flux:radio value="subscription" :label="__('Subscription')" icon="sparkles" />
                        <flux:radio value="api_key" :label="__('API key')" icon="key" />
                    </flux:radio.group>
                    <flux:input wire:model="new_name" :label="__('Name')" :placeholder="$new_kind === 'subscription' ? __('Personal Max, Company Team…') : __('Company key, Client X…')" />
                    @if ($new_kind === 'api_key')
                        <flux:input wire:model="new_token" type="password" viewable :label="__('API key')" placeholder="sk-ant-…" autocomplete="off" />
                        <flux:text class="text-xs">{{ __('From the Anthropic Console, billed on that Console account. Studio keeps it encrypted and hands it to Claude Code with each message of the projects that use it.') }}</flux:text>
                    @else
                        <flux:text class="text-xs">
                            {{ __('The official Claude Code sign-in runs in your own home on this server, in a folder of its own for this account: Studio never sees or keeps it. Your own plan, used by you alone and billed to you: a subscription is never shared with a colleague, and each subscription is a Claude account of its own.') }}
                            @unless ($onServer){{ __('In development Claude Code uses the sign-in of this computer.') }}@endunless
                        </flux:text>
                    @endif
                    <flux:button type="submit" variant="primary" :icon="$new_kind === 'subscription' ? 'command-line' : 'plus'">{{ $new_kind === 'subscription' ? __('Add and sign in') : __('Save the key') }}</flux:button>
                </form>

                <flux:modal name="claude-login" class="md:w-[48rem]" x-on:close="stop()">
                    <div class="space-y-4">
                        <div>
                            <flux:heading size="lg">{{ __('Sign in to Claude Code') }}</flux:heading>
                            <flux:text class="mt-1">{{ __('This is Claude Code’s own claude auth login, in a terminal in your home on the server. Studio shows the terminal and takes no part in the sign-in: the code goes to Claude Code, never through Studio.') }}</flux:text>
                        </div>
                        <ol class="list-decimal space-y-1 ps-5 text-sm text-zinc-600 dark:text-zinc-300">
                            <li>{{ __('If Claude Code asks which account, choose your Claude account (Pro, Max or Team), not the Console.') }}</li>
                            <li>{{ __('Open Anthropic’s sign-in page and approve with the Claude account of this subscription: sign out of claude.ai first if another one is signed in there.') }}</li>
                            <li>{{ __('Copy the code the page gives you and paste it in the terminal below (⌘V or Ctrl+V), where Claude Code waits for it.') }}</li>
                        </ol>

                        <div class="flex flex-wrap items-center gap-2">
                            <template x-if="link">
                                <flux:button variant="primary" icon="arrow-top-right-on-square" x-on:click="window.open(link, '_blank', 'noopener'); focusTerminal()">{{ __('Open the sign-in page') }}</flux:button>
                            </template>
                            <template x-if="! link && state === 'running'">
                                <flux:button icon="link" x-on:click="askLink()">{{ __('Get the sign-in link') }}</flux:button>
                            </template>
                            <flux:text class="text-xs" x-show="state === 'running'">{{ __('The link is the one Claude Code printed in the terminal; clicking it there works too.') }}</flux:text>
                        </div>

                        <div wire:ignore id="claude-login-terminal" class="h-72 overflow-hidden rounded-lg bg-zinc-950 p-2" x-on:click="focusTerminal()"></div>

                        <div class="flex items-center justify-between gap-3 text-sm">
                            <span x-show="state === 'connecting'" class="flex items-center gap-2 text-zinc-500"><flux:icon.loading class="size-4" /><span x-text="{ ticket: @js(__('Preparing the sign-in…')), terminal: @js(__('Opening the terminal…')), socket: @js(__('Connecting to the server…')), claude: @js(__('Starting Claude Code…')) }[step] ?? @js(__('Starting Claude Code…'))"></span></span>
                            <span x-show="state === 'done'" class="flex items-center gap-2 text-green-600"><flux:icon.check-circle class="size-4" />{{ __('Done: you are signed in.') }}</span>
                            <span x-show="state === 'error'" class="text-red-600" x-text="error"></span>
                            <span x-show="state === 'running'" class="text-xs text-zinc-500">{{ __('The terminal is live: paste the code there, or type as you would in a shell.') }}</span>
                            <div class="ms-auto flex gap-2">
                                <flux:button size="sm" variant="ghost" x-show="state === 'error'" x-on:click="start()">{{ __('Try again') }}</flux:button>
                                <flux:modal.close><flux:button size="sm" variant="primary">{{ __('Close') }}</flux:button></flux:modal.close>
                            </div>
                        </div>
                    </div>
                </flux:modal>
            </div>
            @endif
        </div>
    </x-pages::settings.layout>
</section>
