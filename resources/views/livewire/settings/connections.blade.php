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
            <div class="space-y-4">
                <div class="flex items-center gap-3">
                    <flux:heading size="lg">Claude</flux:heading>
                    @if ($user->hasClaude())
                        <flux:badge color="green" size="sm">{{ $user->claude_auth_mode->label() }}</flux:badge>
                    @else
                        <flux:badge color="yellow" size="sm">{{ __('not connected') }}</flux:badge>
                    @endif
                </div>
                <flux:text>
                    {{ __('Every conversation runs the Claude Code in your workspace with this credential. Nobody else uses it; usage and cost are yours.') }}
                </flux:text>
                <flux:callout icon="information-circle" variant="secondary">
                    <flux:callout.heading>{{ __('Subscription or API key') }}</flux:callout.heading>
                    <flux:callout.text>
                        <ul class="list-disc space-y-1 ps-5">
                            <li>{{ __('Claude Pro or Max: on your computer, with Claude Code installed and signed in, run') }} <code class="font-mono">claude setup-token</code> {{ __('and paste the long-lived token here.') }}</li>
                            <li>{{ __('API key: create one in the Anthropic Console and paste it here; the workspace sets ANTHROPIC_API_KEY.') }}</li>
                        </ul>
                    </flux:callout.text>
                </flux:callout>
                <form wire:submit="saveClaude" class="space-y-3">
                    <div class="grid gap-3 sm:grid-cols-[14rem_1fr]">
                        <flux:select wire:model="claude_auth_mode" :label="__('Kind')">
                            <flux:select.option value="subscription">{{ __('Claude subscription (setup-token)') }}</flux:select.option>
                            <flux:select.option value="api_key">{{ __('Anthropic API key') }}</flux:select.option>
                        </flux:select>
                        <flux:input wire:model="claude_token" type="password" viewable :label="__('Token')" :placeholder="$user->hasClaude() ? __('Paste a new credential to replace the current one') : 'sk-ant-… or the setup-token value'" autocomplete="off" />
                    </div>
                    <div class="flex gap-2">
                        <flux:button type="submit" variant="primary">{{ $user->hasClaude() ? __('Replace') : __('Connect Claude') }}</flux:button>
                        @if ($user->hasClaude())
                            <flux:button variant="ghost" wire:click="forgetClaude" wire:confirm="{{ __('Remove the Claude credential? Your conversations stop until you add one again.') }}">{{ __('Remove') }}</flux:button>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    </x-pages::settings.layout>
</section>
