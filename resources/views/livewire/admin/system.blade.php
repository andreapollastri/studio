<div class="flex h-full w-full flex-1 flex-col gap-8" @if ($running) wire:poll.5s @endif>
    <div>
        <flux:heading size="xl">{{ __('System') }}</flux:heading>
        <flux:text class="mt-1">{{ __('This installation of Studio: the release it runs, its updates, and the git provider every project lives on.') }}</flux:text>
    </div>

    @if ($error)
        <flux:callout variant="danger" icon="exclamation-triangle">
            <flux:callout.text>{{ $error }}</flux:callout.text>
        </flux:callout>
    @endif

    {{-- Updates --}}
    <section class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="lg">{{ __('Updates') }}</flux:heading>
            <div class="flex flex-wrap gap-2">
                <flux:button size="sm" icon="arrow-path" wire:click="check" :disabled="! $managed || $running">{{ __('Check now') }}</flux:button>
                <flux:button size="sm" icon="bolt" wire:click="forceUpdate" :disabled="! $managed || $running"
                    wire:confirm="{{ __('Force an update now? Studio builds the newest build of its channel and installs it, even when it is the one already installed, with the same database backup and rollback as every update. It never goes back to an older build.') }}">
                    {{ __('Force update') }}
                </flux:button>
                @if ($available)
                    <flux:button size="sm" variant="primary" icon="arrow-up-circle" wire:click="update" :disabled="! $managed || $running"
                        wire:confirm="{{ __('Update Studio to :version now? It builds the new release next to the running one, backs up the database, migrates, switches, and puts everything back if a step fails. Studio is in maintenance for a moment.', ['version' => $available]) }}">
                        {{ __('Update to :version', ['version' => $available]) }}
                    </flux:button>
                @endif
            </div>
        </div>

        @unless ($managed)
            <flux:callout icon="information-circle" variant="secondary">
                <flux:callout.text>{{ __('Updates run on the server, where studio-update installs every new release. This Studio is a local installation: update it with git.') }}</flux:callout.text>
            </flux:callout>
        @endunless

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text class="text-xs uppercase tracking-wide">{{ __('Installed') }}</flux:text>
                <div class="mt-1 font-mono text-lg">{{ $state['current'] ?? 'v'.$version }}</div>
                @if (! empty($state['previous']))
                    <flux:text class="text-xs">{{ __('before: :version', ['version' => $state['previous']]) }}</flux:text>
                @endif
            </div>
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text class="text-xs uppercase tracking-wide">{{ $channel === 'beta' ? __('Newest on main') : __('Newest release') }}</flux:text>
                @php($newest = $channel === 'beta' ? ($state['main'] ?? null) : ($state['latest'] ?? null))
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    @if (! empty($newest))
                        <a href="{{ $releaseUrl($newest) }}" target="_blank" rel="noopener" class="font-mono text-lg underline decoration-zinc-400 underline-offset-4">{{ $newest }}</a>
                    @else
                        <span class="font-mono text-lg text-zinc-400">—</span>
                    @endif
                    @if ($running)
                        <flux:badge size="sm" color="blue">{{ __('updating…') }}</flux:badge>
                    @elseif ($available)
                        <flux:badge size="sm" color="yellow">{{ __('update available') }}</flux:badge>
                    @elseif (! empty($newest) && ! $waiting)
                        <flux:badge size="sm" color="green">{{ __('up to date') }}</flux:badge>
                    @endif
                </div>
                @if (! empty($state['checked_at']))
                    <flux:text class="text-xs">{{ __('checked :when', ['when' => \Illuminate\Support\Carbon::parse($state['checked_at'])->diffForHumans()]) }}</flux:text>
                @endif
            </div>
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text class="text-xs uppercase tracking-wide">{{ __('Automatic updates') }}</flux:text>
                <div class="mt-2">
                    <flux:switch wire:model.live="automatic" :disabled="! $managed" :label="__('Every night, to the newest build')" />
                </div>
            </div>
        </div>

        @if ($waiting && ! $running)
            <flux:callout icon="clock" variant="secondary">
                <flux:callout.text>{{ $waiting }}</flux:callout.text>
            </flux:callout>
        @endif

        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 max-w-2xl">
                    <flux:heading>{{ __('Channel') }}</flux:heading>
                    <flux:text class="mt-1 text-sm">
                        @if ($channel === 'beta')
                            {{ __('Beta tester mode: Studio follows the main branch and installs every new commit on it, before it becomes a release. For a server you test on, not for the team\'s daily work.') }}
                        @else
                            {{ __('Stable: Studio installs the release tags (vMAJOR.MINOR.PATCH) and nothing in between.') }}
                        @endif
                    </flux:text>
                </div>
                <flux:radio.group wire:model.live="channel" variant="segmented" size="sm">
                    <flux:radio value="stable" :label="__('Stable releases')" :disabled="! $managed || $running" />
                    <flux:radio value="beta" :label="__('Beta (main branch)')" :disabled="! $managed || $running" />
                </flux:radio.group>
            </div>
            <flux:text class="mt-3 text-xs">{{ __('Neither channel goes backwards: back on stable after beta, Studio keeps its main build until a release contains it.') }}</flux:text>
        </div>

        @if (! empty($state['status']) && ! in_array($state['status'], ['installed'], true))
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div class="flex flex-wrap items-center gap-2 border-b border-zinc-200 px-4 py-2 dark:border-zinc-700">
                    <flux:badge size="sm" :color="match ($state['status']) { 'ok', 'up-to-date' => 'green', 'failed' => 'red', 'running' => 'blue', default => 'zinc' }">{{ match ($state['status']) { 'ok' => __('updated'), 'up-to-date' => __('up to date'), 'failed' => __('failed'), 'running' => __('running'), default => $state['status'] } }}</flux:badge>
                    <span class="text-sm">{{ $state['message'] ?? '' }}</span>
                    @if (! empty($state['finished_at']))
                        <flux:text class="ms-auto text-xs">{{ \Illuminate\Support\Carbon::parse($state['finished_at'])->diffForHumans() }}</flux:text>
                    @endif
                </div>
                @if (! empty($state['log']))
                    <pre class="max-h-72 overflow-auto px-4 py-3 font-mono text-xs leading-5 text-zinc-600 dark:text-zinc-300">{{ implode("\n", (array) $state['log']) }}</pre>
                @endif
                @if (($state['status'] ?? '') === 'failed')
                    <flux:text class="border-t border-zinc-200 px-4 py-2 text-xs dark:border-zinc-700">{{ __('The previous release and its database are back in place. The full log is in /var/log/studio-update.log on the server.') }}</flux:text>
                @endif
            </div>
        @endif

        <flux:text class="text-xs">
            {{ __('Each update builds the new release next to the running one, installs what it needs on the server (a new PHP, Node, packages, the provider\'s CLI) as root, backs up the database, migrates, switches, and checks that Studio answers; if any step fails, the previous release and the database come back.') }}
        </flux:text>
    </section>

    <flux:separator />

    {{-- Git provider --}}
    <section class="space-y-3">
        <flux:heading size="lg">{{ __('Git provider') }}</flux:heading>
        <div class="flex flex-wrap items-center gap-3">
            <flux:badge size="lg" icon="code-bracket">{{ $git->label() }}</flux:badge>
            <span class="font-mono text-sm">{{ $git->baseUrl() }}</span>
            <flux:text class="text-xs">{{ $git->cli() ? __('agents use :cli', ['cli' => $git->cli()]) : __('Larapilot uses the REST API') }}</flux:text>
        </div>
        <flux:text>
            {{ __('Chosen once, in the installer: every project is a repository there and every person connects a token for it. Studio never mixes providers and keeps no history of a previous one.') }}
        </flux:text>
        <flux:text class="text-xs">
            @if ($projectCount === 0)
                {{ __('There are no projects yet, so the provider can still change on the server:') }}
                <code class="font-mono">php artisan studio:git-provider gitlab --url=https://git.example.com</code>
            @else
                {{ __('There are projects on :provider, so the provider stays: another provider means another Studio.', ['provider' => $git->label()]) }}
            @endif
        </flux:text>
    </section>
</div>
