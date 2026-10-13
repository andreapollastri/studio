{{-- Followed by systemWatch (resources/js/system-watch.js), not wire:poll: it keeps going through the
     maintenance of an update's switch and reloads onto the new release when the update is done. --}}
<div class="flex h-full w-full flex-1 flex-col gap-8" x-data="systemWatch({ url: @js(route('admin.system.status')), running: @js($running || $serverRunning), finishedAt: @js($state['finished_at'] ?? null), serverFinishedAt: @js($server['finished_at'] ?? null) })">
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

        {{-- An update under way, followed live (also through the maintenance of the switch). --}}
        <div x-show="live" x-transition class="rounded-xl border border-sky-300 dark:border-sky-800" style="display: none">
            <div class="flex flex-wrap items-center gap-2 border-b border-sky-200 px-4 py-2 dark:border-sky-900">
                <flux:icon.loading class="size-4 text-sky-500" />
                <span class="text-sm font-medium" x-text="maintenance ? @js(__('Studio is switching to the new release…')) : (message || @js(__('The update is starting…')))"></span>
            </div>
            <pre x-show="log.length" class="max-h-72 overflow-auto px-4 py-3 font-mono text-xs leading-5 text-zinc-600 dark:text-zinc-300" x-text="log.join('\n')" x-effect="log; $nextTick(() => $el.scrollTop = $el.scrollHeight)"></pre>
            <flux:text class="border-t border-sky-200 px-4 py-2 text-xs dark:border-sky-900">{{ __('This page follows the update and reloads itself when it is done.') }}</flux:text>
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

    {{-- The server itself --}}
    <section class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="lg">{{ __('Server') }}</flux:heading>
            <flux:button size="sm" icon="arrow-path" wire:click="updateServer" :disabled="! $managed || $running || $serverRunning"
                wire:confirm="{{ __('Update every package on the server to its newest version now? Ubuntu, PHP, Caddy, MariaDB, PostgreSQL, redis, Node, Composer, Claude Code and the git provider\'s CLI; Studio\'s services restart for a moment at the end.') }}">
                {{ __('Update the server now') }}
            </flux:button>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text class="text-xs uppercase tracking-wide">{{ __('Last server update') }}</flux:text>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    @if ($serverRunning)
                        <flux:badge size="sm" color="blue">{{ __('updating…') }}</flux:badge>
                    @elseif (! empty($server['finished_at']))
                        <span class="text-lg">{{ \Illuminate\Support\Carbon::parse($server['finished_at'])->diffForHumans() }}</span>
                        <flux:badge size="sm" :color="match ($server['status'] ?? '') { 'ok', 'up-to-date' => 'green', 'failed' => 'red', default => 'zinc' }">{{ match ($server['status'] ?? '') { 'ok' => __('updated'), 'up-to-date' => __('up to date'), 'failed' => __('failed'), default => (string) ($server['status'] ?? '') } }}</flux:badge>
                    @else
                        <span class="text-lg text-zinc-400">{{ __('not yet') }}</span>
                    @endif
                </div>
                @if (! empty($server['message']) && ! $serverRunning)
                    <flux:text class="text-xs">{{ $server['message'] }}</flux:text>
                @endif
            </div>
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text class="text-xs uppercase tracking-wide">{{ __('Reboot') }}</flux:text>
                <div class="mt-1">
                    @if (($server['reboot_required'] ?? false) === true)
                        <flux:badge size="sm" color="yellow">{{ __('reboot required') }}</flux:badge>
                        <flux:text class="mt-1 text-xs">{{ __('A new kernel or system library is installed: it runs from the next reboot of the server.') }}</flux:text>
                    @else
                        <span class="text-lg">{{ __('not needed') }}</span>
                    @endif
                </div>
            </div>
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text class="text-xs uppercase tracking-wide">{{ __('Nightly server updates') }}</flux:text>
                <div class="mt-2">
                    <flux:switch wire:model.live="serverAutomatic" :disabled="! $managed" :label="__('Every night, every package to its newest version')" />
                </div>
            </div>
        </div>

        @if (! empty($server['changes']) || ! empty($server['log']))
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
                @if (! empty($server['changes']))
                    <div class="border-b border-zinc-200 px-4 py-2 dark:border-zinc-700">
                        <flux:text class="text-xs uppercase tracking-wide">{{ __(':count changed', ['count' => count((array) $server['changes'])]) }}</flux:text>
                    </div>
                    <pre class="max-h-56 overflow-auto px-4 py-3 font-mono text-xs leading-5 text-zinc-600 dark:text-zinc-300">{{ implode("\n", (array) $server['changes']) }}</pre>
                @endif
                @if (! empty($server['log']))
                    <details class="border-t border-zinc-200 dark:border-zinc-700">
                        <summary class="cursor-pointer px-4 py-2 text-xs">{{ __('Log') }}</summary>
                        <pre class="max-h-72 overflow-auto px-4 py-3 font-mono text-xs leading-5 text-zinc-600 dark:text-zinc-300">{{ implode("\n", (array) $server['log']) }}</pre>
                    </details>
                @endif
            </div>
        @endif

        <flux:text class="text-xs">
            {{ __('Every night, an hour before Studio\'s own update, the server upgrades every package to its newest version: Ubuntu and its security fixes, every installed PHP and its extensions, Caddy, MariaDB, PostgreSQL, redis, Node, Composer, Claude Code and the git provider\'s CLI. It stays on what is installed: a new PHP version comes with a release of Studio; a new Ubuntu release or a new major version of a database is a decision for a person.') }}
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
