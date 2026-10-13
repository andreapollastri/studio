<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Accounts')" :subheading="__('What you bring to your work: your Claude plans and keys, your accounts on the git hosts, your SSH keys. Your workspaces clone and push as you, and run Claude on your own plans.')">
        <div class="my-6 w-full space-y-10">

            <flux:radio.group wire:model.live="tab" variant="segmented" class="max-sm:w-full">
                <flux:radio value="claude" label="Claude" icon="sparkles" />
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
                                                    <flux:button size="sm" :variant="$account->isUsable() ? 'ghost' : 'primary'" icon="sparkles" x-on:click="start({{ Js::from($account->uuid) }}); $flux.modal('claude-login').show()">{{ $account->isUsable() ? __('Sign in again') : __('Sign in with Claude') }}</flux:button>
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
                            {{ __('The official Claude Code sign-in runs in your own home on this server, in a folder of its own for this account: Studio never sees or keeps it. Use a different Claude account for each subscription.') }}
                            @unless ($onServer){{ __('In development Claude Code uses the sign-in of this computer.') }}@endunless
                        </flux:text>
                    @endif
                    <flux:button type="submit" variant="primary" :icon="$new_kind === 'subscription' ? 'sparkles' : 'plus'">{{ $new_kind === 'subscription' ? __('Add and sign in') : __('Save the key') }}</flux:button>
                </form>

                <flux:modal name="claude-login" class="md:w-[48rem]" x-on:close="stop()">
                    <div class="space-y-4">
                        <div>
                            <flux:heading size="lg">{{ __('Sign in with Claude') }}</flux:heading>
                            <flux:text class="mt-1">{{ __('This is Claude Code’s own sign-in, running in your home on the server. Studio only shows the terminal.') }}</flux:text>
                        </div>
                        <ol class="list-decimal space-y-1 ps-5 text-sm text-zinc-600 dark:text-zinc-300">
                            <li>{{ __('If Claude Code asks which account, choose your Claude account (Pro, Max or Team), not the Console.') }}</li>
                            <li>{{ __('Open Anthropic’s sign-in page and approve with the Claude account of this subscription: sign out of claude.ai first if another one is signed in there.') }}</li>
                            <li>{{ __('Copy the code the page shows you and paste it below (or in the terminal).') }}</li>
                        </ol>

                        <div class="flex flex-wrap items-center gap-2">
                            <template x-if="link">
                                <flux:button variant="primary" icon="arrow-top-right-on-square" x-on:click="window.open(link, '_blank', 'noopener')">{{ __('Open the sign-in page') }}</flux:button>
                            </template>
                            <template x-if="! link && state === 'running'">
                                <flux:button icon="link" x-on:click="askLink()">{{ __('Get the sign-in link') }}</flux:button>
                            </template>
                            <form class="flex min-w-64 flex-1 gap-2" x-on:submit.prevent="pasteCode()">
                                <flux:input x-model="code" :placeholder="__('Paste the code here')" class="font-mono" autocomplete="off" x-bind:disabled="state !== 'running'" />
                                <flux:button type="submit" x-bind:disabled="state !== 'running' || ! code.trim()">{{ __('Send') }}</flux:button>
                            </form>
                        </div>

                        <div wire:ignore id="claude-login-terminal" class="h-72 overflow-hidden rounded-lg bg-zinc-950 p-2"></div>

                        <div class="flex items-center justify-between gap-3 text-sm">
                            <span x-show="state === 'connecting'" class="flex items-center gap-2 text-zinc-500"><flux:icon.loading class="size-4" /><span x-text="{ ticket: @js(__('Preparing the sign-in…')), terminal: @js(__('Opening the terminal…')), socket: @js(__('Connecting to the server…')), claude: @js(__('Starting Claude Code…')) }[step] ?? @js(__('Starting Claude Code…'))"></span></span>
                            <span x-show="state === 'done'" class="flex items-center gap-2 text-green-600"><flux:icon.check-circle class="size-4" />{{ __('Done: you are signed in.') }}</span>
                            <span x-show="state === 'error'" class="text-red-600" x-text="error"></span>
                            <span x-show="state === 'running'" class="text-xs text-zinc-500">{{ __('The terminal is live: you can also type in it.') }}</span>
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
