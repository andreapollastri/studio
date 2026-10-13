{{-- Followed by systemWatch (resources/js/system-watch.js), not wire:poll: it keeps going through the
     maintenance of an update's switch and reloads onto the new release when the update is done. --}}
<div class="flex h-full w-full flex-1 flex-col gap-8" x-data="systemWatch({ url: @js(route('admin.system.status')), running: @js($running || $serverRunning), finishedAt: @js($state['finished_at'] ?? null), serverFinishedAt: @js($server['finished_at'] ?? null) })">
    <div>
        <flux:heading size="xl">{{ __('System') }}</flux:heading>
        <flux:text class="mt-1">{{ __('This installation of Studio: the release it runs, its updates, its SSH keys, and the git provider every project lives on.') }}</flux:text>
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

        {{-- TLS certificates: Caddy renews them; the nightly check renews the late ones and reports the rest --}}
        @php($certificates = is_array($server['certificates'] ?? null) ? $server['certificates'] : null)
        @if ($certificates && ! empty($certificates['problems']))
            <flux:callout variant="danger" icon="lock-open">
                <flux:callout.heading>{{ __('Caddy could not renew these certificates') }}</flux:callout.heading>
                <flux:callout.text>
                    <ul class="mt-1 space-y-0.5 font-mono text-xs">
                        @foreach ((array) $certificates['problems'] as $problem)
                            <li>{{ $problem['host'] ?? '' }} · {{ ($problem['state'] ?? '') === 'expired' ? __('expired') : __(':days days left', ['days' => $problem['days'] ?? 0]) }}</li>
                        @endforeach
                    </ul>
                    <span class="mt-2 block">{{ __('Check that the DNS record still points at this server and read journalctl -u caddy there. Once a certificate expires, visitors of that host see a security error.') }}</span>
                </flux:callout.text>
            </flux:callout>
        @elseif ($certificates)
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-xl border border-zinc-200 px-4 py-3 text-sm dark:border-zinc-700">
                <flux:icon.lock-closed class="size-4 shrink-0 text-green-600 dark:text-green-400" />
                @if ((int) ($certificates['total'] ?? 0) === 0)
                    <span>{{ __('No TLS certificates yet: Caddy issues each one on the first visit of its host.') }}</span>
                @else
                    <span>{{ __(':count TLS certificates, all valid', ['count' => (int) $certificates['total']]) }}</span>
                    @if (! empty($certificates['next']['host']))
                        <flux:text class="text-xs">· {{ __('the next to expire: :host in :days days', ['host' => $certificates['next']['host'], 'days' => $certificates['next']['days'] ?? 0]) }}</flux:text>
                    @endif
                    @if (! empty($certificates['renewed']))
                        <flux:text class="text-xs">· {{ __('renewed by the last check: :hosts', ['hosts' => implode(', ', (array) $certificates['renewed'])]) }}</flux:text>
                    @endif
                @endif
                @if (! empty($certificates['checked_at']))
                    <flux:text class="ms-auto text-xs">{{ __('certificates checked :when', ['when' => \Illuminate\Support\Carbon::parse($certificates['checked_at'])->diffForHumans()]) }}</flux:text>
                @endif
            </div>
        @endif

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
            {{ __('Every night, an hour before Studio\'s own update, the server upgrades every package to its newest version: Ubuntu and its security fixes, every installed PHP and its extensions, Caddy, MariaDB, PostgreSQL, redis, Node, Composer, Claude Code and the git provider\'s CLI. Then it checks every TLS certificate: Caddy renews them by itself, and one it did not renew in time gets renewed now or shows up here. It stays on what is installed: a new PHP version comes with a release of Studio; a new Ubuntu release or a new major version of a database is a decision for a person.') }}
        </flux:text>
    </section>

    <flux:separator />

    {{-- SSH keys of the installation, shared with projects --}}
    <section id="keys" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="lg">{{ __('Keys') }}</flux:heading>
            <flux:button size="sm" icon="plus" wire:click="newKey">{{ __('New key') }}</flux:button>
        </div>

        @if ($this->keys->isEmpty())
            <flux:text>{{ __('Generate a key here (Ed25519 or RSA) and copy its public half to the server, or import one you already use.') }}</flux:text>
        @else
            <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($this->keys as $key)
                    <div wire:key="system-key-{{ $key->uuid }}" class="flex flex-wrap items-center gap-3 px-4 py-3">
                        <flux:icon.key variant="micro" class="size-4 shrink-0 text-zinc-400" />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="truncate text-sm font-medium">{{ $key->name }}</span>
                                <flux:badge size="sm">{{ $key->label() }}</flux:badge>
                            </div>
                            <div class="truncate font-mono text-[11px] text-zinc-500" title="{{ $key->fingerprint }}">{{ $key->fingerprint }}</div>
                            <div class="mt-1 flex flex-wrap gap-1">
                                @forelse ($key->projects as $project)
                                    <flux:badge size="sm" :color="$project->pivot->developers ? 'green' : 'zinc'" :icon="$project->pivot->developers ? 'users' : 'shield-check'">{{ $project->name }}</flux:badge>
                                @empty
                                    <flux:text class="text-xs">{{ __('Not shared with any project: only administrators use it.') }}</flux:text>
                                @endforelse
                            </div>
                        </div>
                        <flux:button size="xs" variant="ghost" icon="share" wire:click="editSharing('{{ $key->uuid }}')">{{ __('Projects') }}</flux:button>
                        <flux:button size="xs" variant="ghost" icon="clipboard-document" :tooltip="__('Public key')" wire:click="showKey('{{ $key->uuid }}')" />
                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteKey('{{ $key->uuid }}')" wire:confirm="{{ $key->connections_count ? __('Remove the key :name? :count connection(s) use it and will need another one.', ['name' => $key->name, 'count' => $key->connections_count]) : __('Remove the key :name?', ['name' => $key->name]) }}" />
                    </div>
                @endforeach
            </div>
        @endif

        <flux:text class="text-xs">
            {{ __('Keys of this Studio, not of a person: administrators use them on any SSH connection. Shared with a project, they show up there; with its developers too, they may connect with them to that project\'s hosts, without ever seeing the private half. Personal keys stay on the SSH page.') }}
        </flux:text>
    </section>

    <flux:separator />

    {{-- Git hosts --}}
    <section class="space-y-3">
        <flux:heading size="lg">{{ __('Git hosts') }}</flux:heading>
        <div class="flex flex-wrap gap-2">
            @foreach (\App\Infrastructure\Git\Forges::all() as $forge)
                <flux:badge size="lg" :icon="$forge->icon()">{{ $forge->label() }}</flux:badge>
            @endforeach
        </div>
        <flux:text>
            {{ __('A project\'s repository may live on any of them, and a person may have accounts on all of them. The command-line tool the agents open pull requests with (gh, glab, az) is put in place on the server the first time a workspace of that host needs it. Service accounts for deploying the sites are in Administration → Connectors.') }}
        </flux:text>
    </section>

    @include('livewire.ssh.partials.key-modals')

    {{-- The projects a key is shared with --}}
    <flux:modal name="ssh-key-sharing" class="md:w-[36rem]">
        @if ($sharingKey)
            <form wire:submit="saveSharing" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Share :name', ['name' => $sharingKey->name]) }}</flux:heading>
                    <flux:text class="mt-1">{{ __('For each project: not shared, its administrators only, or its developers too.') }}</flux:text>
                </div>

                <div class="max-h-[50vh] overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                    @forelse ($projects as $project)
                        <div wire:key="share-{{ $project->uuid }}" class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 px-3 py-2 last:border-b-0 dark:border-zinc-700">
                            <span class="truncate text-sm">{{ $project->name }}</span>
                            <flux:radio.group wire:model="share.{{ $project->uuid }}" variant="segmented" size="sm">
                                <flux:radio value="off" :label="__('No')" />
                                <flux:radio value="admins" :label="__('Admins')" />
                                <flux:radio value="developers" :label="__('Admins and devs')" />
                            </flux:radio.group>
                        </div>
                    @empty
                        <flux:text class="px-3 py-2 text-sm">{{ __('There are no projects yet.') }}</flux:text>
                    @endforelse
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
