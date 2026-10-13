<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Connections')" :subheading="__('Your workspaces clone and push as you, and run Claude on your own plan or key.')">
        <div class="my-6 w-full space-y-10">

            {{-- The git provider of this installation --}}
            <div class="space-y-4">
                <div class="flex items-center gap-3">
                    <flux:heading size="lg">{{ $git->label() }}</flux:heading>
                    @if ($user->hasGit())
                        <flux:badge color="green" size="sm">{{ __('connected as :login', ['login' => $user->git_login]) }}</flux:badge>
                    @else
                        <flux:badge color="yellow" size="sm">{{ __('not connected') }}</flux:badge>
                    @endif
                </div>
                <flux:text>
                    {{ __('A personal token lets your workspace clone the repository, push your branches and open pull requests in your name. Studio stores it encrypted and only hands it to your own workspaces.') }}
                    @if ($git->key() !== 'github')
                        {{ __('Every project of this Studio lives on :url.', ['url' => $git->baseUrl()]) }}
                    @endif
                </flux:text>
                <flux:callout icon="information-circle" variant="secondary">
                    <flux:callout.heading>{{ __('How to create the token') }}</flux:callout.heading>
                    <flux:callout.text>
                        @include('livewire.settings.partials.git-token-help', ['git' => $git, 'admin' => $user->isAdmin()])
                    </flux:callout.text>
                </flux:callout>
                <form wire:submit="saveGit" class="flex flex-col gap-3 sm:flex-row sm:items-start">
                    <div class="min-w-0 flex-1">
                        <flux:input wire:model="git_token" type="password" viewable :placeholder="$user->hasGit() ? __('Paste a new token to replace the current one') : $git->tokenPlaceholder()" autocomplete="off" />
                    </div>
                    <flux:button type="submit" variant="primary">{{ $user->hasGit() ? __('Replace') : __('Connect :provider', ['provider' => $git->label()]) }}</flux:button>
                    @if ($user->hasGit())
                        <flux:button variant="ghost" wire:click="forgetGit" wire:confirm="{{ __('Remove the :provider token? Your workspaces will not be able to push until you add one again.', ['provider' => $git->label()]) }}">{{ __('Remove') }}</flux:button>
                    @endif
                </form>
                @if ($user->handle)
                    <flux:text class="text-xs">{{ __('Your previews live at :host', ['host' => $user->handle.'-<project>.'.config('studio.domain')]) }}</flux:text>
                @endif
            </div>

            <flux:separator />

            {{-- Claude --}}
            @php($onServer = \App\Claude\ClaudeLogin::onServer())
            <div class="space-y-4" @if ($onServer && $user->claude_auth_mode === \App\Enums\ClaudeAuthMode::Subscription) wire:init="refreshClaude(true)" @endif>
                <div class="flex items-center gap-3">
                    <flux:heading size="lg">Claude</flux:heading>
                    @if ($user->hasClaude())
                        <flux:badge color="green" size="sm">{{ $user->claude_auth_mode->label() }}</flux:badge>
                    @else
                        <flux:badge color="yellow" size="sm">{{ __('not connected') }}</flux:badge>
                    @endif
                </div>
                <flux:text>
                    {{ __('Every conversation runs the Claude Code in your workspace with your own Claude: nobody else uses it; usage and cost are yours.') }}
                </flux:text>

                <flux:radio.group wire:model.live="claude_auth_mode" variant="segmented">
                    <flux:radio value="subscription" :label="__('Claude subscription')" icon="sparkles" />
                    <flux:radio value="api_key" :label="__('API key')" icon="key" />
                </flux:radio.group>

                @if ($claude_auth_mode === 'subscription')
                    <div class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" x-data="claudeLogin">
                        @if ($user->claude_auth_mode === \App\Enums\ClaudeAuthMode::Subscription)
                            <div class="flex flex-wrap items-center gap-3">
                                <flux:icon.check-circle class="size-5 text-green-500" />
                                <flux:text class="flex-1">
                                    {{ __('Signed in with your Claude subscription in your home on this server.') }}
                                    @if ($user->claude_checked_at)<span class="text-xs text-zinc-500">{{ __('Checked :when.', ['when' => $user->claude_checked_at->diffForHumans()]) }}</span>@endif
                                </flux:text>
                                <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="refreshClaude">{{ __('Check') }}</flux:button>
                                <flux:button size="sm" variant="ghost" x-on:click="start(); $flux.modal('claude-login').show()" :disabled="! $onServer">{{ __('Sign in again') }}</flux:button>
                                <flux:button size="sm" variant="ghost" icon="arrow-right-start-on-rectangle" wire:click="logoutClaude" wire:confirm="{{ __('Sign out of Claude on this server? Your conversations stop until you sign in again.') }}">{{ __('Sign out') }}</flux:button>
                            </div>
                        @else
                            <flux:text>
                                {{ __('Sign in once with your Claude Pro or Max account: the official Claude Code sign-in runs in your own home on this server, every project of yours uses it, and Studio never sees or keeps it.') }}
                            </flux:text>
                            @if ($onServer)
                                <div class="flex flex-wrap gap-2">
                                    <flux:button variant="primary" icon="sparkles" x-on:click="start(); $flux.modal('claude-login').show()">{{ __('Sign in with Claude') }}</flux:button>
                                    <flux:button variant="ghost" icon="arrow-path" wire:click="refreshClaude">{{ __('Check') }}</flux:button>
                                </div>
                            @else
                                <flux:callout icon="computer-desktop" variant="secondary">
                                    <flux:callout.text>{{ __('In development Claude Code uses the sign-in of this computer: run') }} <code class="font-mono">claude auth login</code> {{ __('in your terminal, then') }} <flux:link as="button" wire:click="refreshClaude">{{ __('check') }}</flux:link>.</flux:callout.text>
                                </flux:callout>
                            @endif
                        @endif

                        <flux:modal name="claude-login" class="md:w-[48rem]" x-on:close="reset()">
                            <div class="space-y-4">
                                <div>
                                    <flux:heading size="lg">{{ __('Sign in with Claude') }}</flux:heading>
                                    <flux:text class="mt-1">{{ __('This is Claude Code’s own sign-in, running in your home on the server. Studio only shows the terminal.') }}</flux:text>
                                </div>
                                <ol class="list-decimal space-y-1 ps-5 text-sm text-zinc-600 dark:text-zinc-300">
                                    <li>{{ __('Open Anthropic’s sign-in page and approve with your Claude account.') }}</li>
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

                                <div wire:ignore class="h-72 overflow-hidden rounded-lg bg-zinc-950 p-2" x-ref="term"></div>

                                <div class="flex items-center justify-between gap-3 text-sm">
                                    <span x-show="state === 'connecting'" class="flex items-center gap-2 text-zinc-500"><flux:icon.loading class="size-4" />{{ __('Starting Claude Code…') }}</span>
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
                @else
                    <form wire:submit="saveClaude" class="space-y-3">
                        <flux:text>{{ __('An API key from the Anthropic Console, billed on your Console account: the workspace sets ANTHROPIC_API_KEY.') }}</flux:text>
                        <flux:input wire:model="claude_token" type="password" viewable :label="__('API key')" :placeholder="$user->claude_auth_mode === \App\Enums\ClaudeAuthMode::ApiKey ? __('Paste a new key to replace the current one') : 'sk-ant-…'" autocomplete="off" />
                        <div class="flex gap-2">
                            <flux:button type="submit" variant="primary">{{ $user->claude_auth_mode === \App\Enums\ClaudeAuthMode::ApiKey ? __('Replace') : __('Save the key') }}</flux:button>
                            @if ($user->claude_auth_mode === \App\Enums\ClaudeAuthMode::ApiKey)
                                <flux:button variant="ghost" wire:click="forgetClaude" wire:confirm="{{ __('Remove the API key? Your conversations stop until you connect Claude again.') }}">{{ __('Remove') }}</flux:button>
                            @endif
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </x-pages::settings.layout>
</section>
