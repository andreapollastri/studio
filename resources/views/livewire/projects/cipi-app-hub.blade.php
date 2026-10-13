<div class="space-y-5" @if ($job && in_array($job['status'], ['pending', 'running'], true)) wire:poll.3s="pollJob" @endif>
    @php($details = $this->details)
    @php($summary = $this->summary)

    {{-- The app, its address and what is wrong --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <flux:icon name="rocket-launch" class="size-5 text-zinc-400" />
                <flux:heading size="lg">{{ $item->label }}</flux:heading>
                @if ($summary['status'])
                    <flux:badge size="sm" :color="$summary['status']['color']">{{ $summary['status']['label'] }}</flux:badge>
                @endif
                @if (! empty($details['type']))<flux:badge size="sm">{{ $details['type'] }}</flux:badge>@endif
            </div>
            <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500">
                @if (! empty($details['domain']))
                    <a href="https://{{ $details['domain'] }}" target="_blank" rel="noopener" class="font-mono hover:underline">{{ $details['domain'] }}</a>
                @endif
                <span>Cipi · {{ $item->account->name }} · <span class="font-mono">{{ $item->resource_id }}</span></span>
                @if (! empty($details['php']))<span>PHP {{ $details['php'] }}</span>@endif
                @if (! empty($details['branch']))<span class="font-mono">{{ $details['branch'] }}</span>@endif
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($this->can('deploy'))
                <flux:button size="sm" variant="primary" icon="rocket-launch" wire:click="deploy" wire:confirm="{{ __('Deploy :app now?', ['app' => $item->label]) }}">{{ __('Deploy') }}</flux:button>
            @endif
            @if ($this->can('rollback'))
                <flux:button size="sm" icon="arrow-uturn-left" wire:click="rollback" wire:confirm="{{ __('Go back to the previous release of :app?', ['app' => $item->label]) }}">{{ __('Roll back') }}</flux:button>
            @endif
        </div>
    </div>

    @foreach ($summary['alerts'] as $alert)
        <flux:callout variant="warning" icon="exclamation-triangle"><flux:callout.text>{{ $alert }}</flux:callout.text></flux:callout>
    @endforeach
    @if ($summary['error'])
        <flux:callout variant="danger" icon="exclamation-triangle"><flux:callout.text>{{ $summary['error'] }}</flux:callout.text></flux:callout>
    @endif

    {{-- A job of the server being watched --}}
    @if ($job)
        <div @class([
            'rounded-xl border p-3',
            'border-sky-200 bg-sky-50 dark:border-sky-900 dark:bg-sky-950/40' => in_array($job['status'], ['pending', 'running'], true),
            'border-green-200 bg-green-50 dark:border-green-900 dark:bg-green-950/40' => $job['status'] === 'completed',
            'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/40' => $job['status'] === 'failed',
        ])>
            <div class="flex items-center gap-2 text-sm">
                @if (in_array($job['status'], ['pending', 'running'], true))<flux:icon.loading class="size-4" />@endif
                <span class="font-medium">{{ $job['label'] }}</span>
                <span class="text-zinc-500">· {{ __($job['status']) }}</span>
                <flux:spacer />
                @unless (in_array($job['status'], ['pending', 'running'], true))
                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="closeJob" :aria-label="__('Close')" />
                @endunless
            </div>
            @if ($job['output'] !== '')
                <pre class="mt-2 max-h-64 overflow-auto rounded-lg bg-zinc-950 p-2 font-mono text-[11px] leading-snug whitespace-pre-wrap text-zinc-100">{{ \App\Support\Ansi::toHtml($job['output']) }}</pre>
            @endif
        </div>
    @endif

    {{-- Tabs --}}
    <div class="flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-700">
        @foreach ($tabs as $key)
            <button type="button" wire:click="setTab('{{ $key }}')" @class([
                '-mb-px flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2 text-sm whitespace-nowrap transition',
                'border-zinc-900 font-medium text-zinc-900 dark:border-white dark:text-white' => $tab === $key,
                'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $tab !== $key,
            ])>
                <flux:icon :name="['deploys' => 'rocket-launch', 'logs' => 'document-text', 'env' => 'variable', 'database' => 'circle-stack', 'agent' => 'cpu-chip', 'actions' => 'bolt'][$key]" variant="micro" class="size-4 opacity-80" />
                {{ ['deploys' => __('Releases'), 'logs' => __('Logs'), 'env' => __('.env'), 'database' => __('Database'), 'agent' => __('Agent & access'), 'actions' => __('All actions')][$key] }}
            </button>
        @endforeach
    </div>

    {{-- ===================================================== Releases --}}
    @if ($tab === 'deploys')
        <div class="grid gap-5 lg:grid-cols-[1fr_18rem]">
            <div class="min-w-0 space-y-5">
                @php($releases = $this->releases)
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <div class="flex items-center justify-between gap-2 border-b border-zinc-200 px-4 py-2 dark:border-zinc-700">
                        <flux:heading size="sm">{{ __('Releases on the server') }}</flux:heading>
                        @if (is_array($releases) && ! isset($releases['error']))
                            <span class="text-xs text-zinc-500">
                                @if ($releases['in_progress'])<flux:badge size="sm" color="sky" icon="arrow-path">{{ __('deploying') }}</flux:badge>@elseif ($releases['pending'])<flux:badge size="sm" color="amber">{{ __('deploy queued') }}</flux:badge>@endif
                                {{ $releases['branch'] }}
                            </span>
                        @endif
                    </div>
                    @if ($releases === null)
                        <div class="p-4 text-sm text-zinc-500">{{ __('Your role does not see the releases.') }}</div>
                    @elseif (isset($releases['error']))
                        <div class="p-4 text-sm text-zinc-500">{{ $releases['error'] }}</div>
                    @elseif ($releases['releases'] === [])
                        <div class="p-4 text-sm text-zinc-500">{{ __('No release on disk yet.') }}</div>
                    @else
                        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($releases['releases'] as $release)
                                <li class="flex items-center gap-3 px-4 py-2 text-sm" wire:key="release-{{ $release['name'] }}">
                                    <span class="w-10 shrink-0 font-mono text-zinc-500">#{{ $release['name'] }}</span>
                                    <span class="font-mono text-xs">{{ $release['commit'] ? substr($release['commit'], 0, 10) : '—' }}</span>
                                    <span class="text-xs text-zinc-500">{{ $release['created_at'] ? \Illuminate\Support\Carbon::parse($release['created_at'])->diffForHumans() : '' }}</span>
                                    <flux:spacer />
                                    @if ($release['current'])
                                        <flux:badge size="sm" color="green">{{ __('live') }}</flux:badge>
                                    @elseif ($loop->index === 1 && $this->can('rollback'))
                                        <flux:button size="xs" variant="ghost" icon="arrow-uturn-left" wire:click="rollback" wire:confirm="{{ __('Go back to release #:release?', ['release' => $release['name']]) }}">{{ __('Roll back here') }}</flux:button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <div class="border-t border-zinc-200 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-700">{{ __('Cipi rolls back one release at a time, to the one before the live one.') }}</div>
                    @endif
                </div>

                @php($audit = $this->audit)
                @if ($audit !== [])
                    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <div class="border-b border-zinc-200 px-4 py-2 dark:border-zinc-700"><flux:heading size="sm">{{ __('Deploy history') }}</flux:heading></div>
                        @if (isset($audit['error']))
                            <div class="p-4 text-sm text-zinc-500">{{ $audit['error'] }}</div>
                        @else
                            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($audit as $record)
                                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-sm">
                                        <flux:badge size="sm" :color="match ($record['event'] ?? '') { 'published' => 'green', 'failed' => 'red', 'rollback' => 'amber', default => 'zinc' }">{{ $record['event'] ?? '?' }}</flux:badge>
                                        <span class="font-mono text-xs">#{{ $record['release'] ?? '?' }} · {{ substr((string) ($record['commit'] ?? ''), 0, 10) }}</span>
                                        <span class="text-xs text-zinc-500">{{ collect([$record['operator'] ?? null, data_get($record, 'claimed.actor'), $record['trigger'] ?? null])->filter()->implode(' · ') }}</span>
                                        <flux:spacer />
                                        <span class="text-xs text-zinc-500" title="{{ $record['ts'] ?? '' }}">{{ isset($record['ts']) ? \Illuminate\Support\Carbon::parse($record['ts'])->diffForHumans() : '' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            </div>

            {{-- The server and the app's share of it --}}
            <div class="space-y-4">
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:heading size="sm">{{ __('Server') }}</flux:heading>
                    @forelse ($summary['meters'] as $meter)
                        <div class="mt-3">
                            <div class="flex justify-between text-xs"><span class="text-zinc-500">{{ $meter['label'] }}</span><span class="font-medium tabular-nums">{{ $meter['text'] }}</span></div>
                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div @class(['h-full rounded-full', 'bg-emerald-500' => $meter['percent'] < 70, 'bg-amber-500' => $meter['percent'] >= 70 && $meter['percent'] < 90, 'bg-red-500' => $meter['percent'] >= 90]) style="width: {{ min(100, max(1, $meter['percent'])) }}%"></div>
                            </div>
                        </div>
                    @empty
                        <flux:text class="mt-2 text-xs">{{ __('No figures from the server.') }}</flux:text>
                    @endforelse
                    <dl class="mt-4 space-y-1 text-xs">
                        @foreach ($summary['facts'] as $label => $value)
                            <div class="flex justify-between gap-3"><dt class="text-zinc-500">{{ $label }}</dt><dd class="truncate text-end" title="{{ $value }}">{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                </div>
                @if ($this->can('unlock'))
                    <flux:button size="sm" variant="ghost" icon="lock-open" class="w-full" wire:click="unlock" wire:confirm="{{ __('Only when a deploy is stuck. Unlock?') }}">{{ __('Unlock a stuck deploy') }}</flux:button>
                @endif
            </div>
        </div>
    @endif

    {{-- ===================================================== Logs --}}
    @if ($tab === 'logs')
        @php($logs = $this->logs)
        <div class="space-y-3" @if ($logFollow) wire:poll.10s @endif>
            <div class="flex flex-wrap items-end gap-2">
                <flux:select wire:model.live="logType" size="sm" class="w-40" :label="__('Log')">
                    @foreach (['laravel' => 'Laravel', 'nginx' => 'nginx', 'php' => 'PHP-FPM', 'worker' => __('Workers'), 'deploy' => __('Deploy'), 'all' => __('All')] as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="logLines" size="sm" class="w-28" :label="__('Lines')">
                    @foreach ([100, 200, 500, 1000] as $count)
                        <flux:select.option value="{{ $count }}">{{ $count }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model.live.debounce.400ms="logSearch" size="sm" icon="magnifying-glass" :placeholder="__('Filter lines')" class="w-56" />
                <flux:checkbox wire:model.live="logFollow" :label="__('Refresh every 10s')" class="mb-1.5" />
                <flux:spacer />
                <div class="flex items-center gap-1">
                    <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="newerLogs" :disabled="$logPage <= 1">{{ __('Newer') }}</flux:button>
                    <span class="text-xs text-zinc-500">{{ __('page :page', ['page' => $logPage]) }}</span>
                    <flux:button size="sm" variant="ghost" icon:trailing="chevron-right" wire:click="olderLogs">{{ __('Older') }}</flux:button>
                </div>
            </div>
            @if (isset($logs['error']))
                <flux:callout variant="danger" icon="exclamation-triangle"><flux:callout.text>{{ $logs['error'] }}</flux:callout.text></flux:callout>
            @elseif ($logs['files'] === [])
                <flux:text>{{ __('No log lines.') }}</flux:text>
            @else
                @foreach ($logs['files'] as $file)
                    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700" wire:key="log-{{ md5($file['path']) }}">
                        <div class="flex items-center justify-between gap-2 bg-zinc-50 px-3 py-1.5 text-xs dark:bg-zinc-800">
                            <span class="truncate font-mono">{{ $file['path'] }}</span>
                            <span class="shrink-0 text-zinc-500">{{ trans_choice(':count line|:count lines', $file['total_lines']) }} · {{ __('page :page of :pages', ['page' => $file['page'], 'pages' => max(1, $file['total_pages'])]) }}</span>
                        </div>
                        <pre class="max-h-[32rem] overflow-auto bg-zinc-950 p-3 font-mono text-[11px] leading-snug whitespace-pre-wrap text-zinc-100">@foreach ($file['lines'] as $line)<span @class(['text-red-300' => preg_match('/\b(ERROR|CRITICAL|EMERGENCY|ALERT|Fatal|\s5\d\d\s)/', $line), 'text-amber-200' => preg_match('/\b(WARNING|warn)/i', $line)])>{{ $line }}</span>
@endforeach</pre>
                    </div>
                @endforeach
            @endif
        </div>
    @endif

    {{-- ===================================================== .env --}}
    @if ($tab === 'env')
        @php($env = $this->env)
        <div class="grid gap-5 lg:grid-cols-[1fr_22rem]">
            <div class="min-w-0">
                @if (! $this->can('env'))
                    <flux:text>{{ __('Your role changes the .env without reading it.') }}</flux:text>
                @elseif (isset($env['error']) && count($env) === 1)
                    <flux:callout variant="danger" icon="exclamation-triangle"><flux:callout.text>{{ $env['error'] }}</flux:callout.text></flux:callout>
                @else
                    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700" x-data="{ shown: {}, filter: '' }">
                        <div class="border-b border-zinc-200 p-2 dark:border-zinc-700">
                            <flux:input size="sm" icon="magnifying-glass" x-model="filter" :placeholder="__('Find a key')" />
                        </div>
                        <table class="w-full text-xs">
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($env as $key => $value)
                                    @php($secret = \App\Livewire\Projects\CipiAppHub::isSecret($key))
                                    <tr x-show="! filter || @js(strtolower($key)).includes(filter.toLowerCase())" wire:key="env-{{ $key }}">
                                        <td class="px-3 py-1.5 font-mono font-medium whitespace-nowrap">{{ $key }}</td>
                                        <td class="w-full px-3 py-1.5 font-mono break-all">
                                            @if ($secret)
                                                <span x-show="! shown[@js($key)]" class="text-zinc-400">••••••••</span>
                                                <span x-show="shown[@js($key)]" x-cloak>{{ $value }}</span>
                                            @else
                                                {{ $value }}
                                            @endif
                                        </td>
                                        <td class="px-2 py-1.5 text-end whitespace-nowrap">
                                            @if ($secret)
                                                <flux:button size="xs" variant="ghost" icon="eye" x-on:click="shown[@js($key)] = ! shown[@js($key)]" :aria-label="__('Show')" />
                                            @endif
                                            @if ($this->can('env-set'))
                                                <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="editEnv(@js($key))" :aria-label="__('Change')" />
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            @if ($this->can('env-set'))
                <form wire:submit="saveEnv" class="space-y-3 self-start rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:heading size="sm">{{ __('Change the .env') }}</flux:heading>
                    <flux:textarea wire:model="envSet" rows="6" class="font-mono text-xs" :label="__('Set')" placeholder="APP_DEBUG=false&#10;MAIL_FROM_ADDRESS=hello@example.com" :description="__('KEY=value, one per line; existing keys are replaced.')" />
                    <flux:input wire:model="envUnset" class="font-mono text-xs" :label="__('Remove')" placeholder="OLD_KEY, OTHER_KEY" />
                    <flux:button type="submit" variant="primary" size="sm" class="w-full">{{ __('Save to the server') }}</flux:button>
                    <flux:text class="text-xs">{{ __('Applied at the next deploy or config cache clear.') }}</flux:text>
                </form>
            @endif
        </div>
    @endif

    {{-- ===================================================== Database --}}
    @if ($tab === 'database')
        @php($anonymizer = $this->anonymizer)
        <div class="grid gap-5 lg:grid-cols-2">
            @if ($this->can('anonymize'))
                <form wire:submit="requestAnonymizedDump" class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex items-center gap-2">
                        <flux:icon name="eye-slash" class="size-5 text-zinc-400" />
                        <flux:heading size="sm">{{ __('Anonymized copy of the database') }}</flux:heading>
                    </div>
                    <flux:text class="text-sm">{{ __('The Cipi agent dumps the production database, replaces names, emails, phones and passwords as anonymization.json says, and mails a download link (valid :ttl minutes). Real data never leaves the server as it is.', ['ttl' => $anonymizer['ttl']]) }}</flux:text>
                    @if ($anonymizer['reason'])
                        <flux:callout variant="warning" icon="information-circle"><flux:callout.text>{{ $anonymizer['reason'] }}</flux:callout.text></flux:callout>
                    @endif
                    <flux:input wire:model="anonymizeEmail" type="email" :label="__('Send the link to')" />
                    <flux:checkbox wire:model="anonymizeGzip" :label="__('Compressed (.sql.gz)')" />
                    <flux:button type="submit" variant="primary" size="sm" icon="paper-airplane" :disabled="(bool) $anonymizer['reason']">{{ __('Request the copy') }}</flux:button>
                </form>
            @endif
            @if ($this->can('db-backup'))
                <div class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex items-center gap-2">
                        <flux:icon name="archive-box" class="size-5 text-zinc-400" />
                        <flux:heading size="sm">{{ __('Backup on the server') }}</flux:heading>
                    </div>
                    <flux:text class="text-sm">{{ __('A dump of :db next to Cipi\'s own, kept on the server. For copies elsewhere on a schedule, use the project\'s Backups.', ['db' => $item->resource_id]) }}</flux:text>
                    <flux:button size="sm" icon="archive-box" wire:click="backupDatabase" wire:confirm="{{ __('Dump the app\'s database on the server now?') }}">{{ __('Back up now') }}</flux:button>
                </div>
            @endif
        </div>

        @if ($this->can('anonymizer-config') || $this->can('anonymizer-audit'))
            <div class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="max-w-2xl">
                        <flux:heading size="sm">{{ __('The anonymization file') }}</flux:heading>
                        <flux:text class="mt-1 text-sm">{{ __('~/.db/anonymization.json on the server, outside the repository: which column becomes what, which tables are emptied. Start from the file on the server, from the live schema, or from a Laravel app; change the rules; write it back and audit it.') }}</flux:text>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" icon="cloud-arrow-down" wire:click="loadAnonymizer('server')">{{ __('From the server') }}</flux:button>
                        <flux:button size="sm" icon="magnifying-glass" wire:click="loadAnonymizer('scan')">{{ __('From the schema') }}</flux:button>
                        <flux:button size="sm" variant="ghost" icon="document" wire:click="loadAnonymizer('starter')">{{ __('A Laravel start') }}</flux:button>
                    </div>
                </div>

                @if ($anonLoaded)
                    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-xs">
                            <thead class="bg-zinc-50 text-zinc-500 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-2 py-1.5 text-start">{{ __('Table') }}</th>
                                    <th class="px-2 py-1.5 text-start">{{ __('Column') }}</th>
                                    <th class="px-2 py-1.5 text-start">{{ __('Becomes') }}</th>
                                    <th class="px-2 py-1.5 text-center" title="{{ __('No two rows get the same value') }}">{{ __('Unique') }}</th>
                                    <th class="px-2 py-1.5 text-center" title="{{ __('The same input gives the same output everywhere') }}">{{ __('Consistent') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($anonRules as $i => $rule)
                                    <tr wire:key="anon-{{ $i }}">
                                        <td class="px-2 py-1"><flux:input size="sm" class="font-mono" wire:model.blur="anonRules.{{ $i }}.table" /></td>
                                        <td class="px-2 py-1"><flux:input size="sm" class="font-mono" wire:model.blur="anonRules.{{ $i }}.column" /></td>
                                        <td class="px-2 py-1">
                                            <flux:input size="sm" class="font-mono" wire:model.blur="anonRules.{{ $i }}.rule" list="anon-transformations" />
                                        </td>
                                        <td class="px-2 py-1 text-center"><input type="checkbox" wire:model="anonRules.{{ $i }}.unique" class="rounded"></td>
                                        <td class="px-2 py-1 text-center"><input type="checkbox" wire:model="anonRules.{{ $i }}.consistent" class="rounded"></td>
                                        <td class="px-2 py-1 text-end"><flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeAnonRule({{ $i }})" :aria-label="__('Remove')" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <datalist id="anon-transformations">
                            @foreach ($transformations as $group => $names)
                                @foreach ($names as $name)<option value="{{ $name }}">{{ $group }}</option>@endforeach
                            @endforeach
                        </datalist>
                    </div>
                    @foreach ($errors->get('anonRules.*') as $messages)
                        <flux:text class="text-xs text-red-600">{{ $messages[0] }}</flux:text>
                    @endforeach
                    <flux:button size="sm" icon="plus" wire:click="addAnonRule">{{ __('Add a column') }}</flux:button>

                    <div class="grid gap-3 sm:grid-cols-[2fr_1fr_1fr]">
                        <flux:input wire:model="anonTruncate" class="font-mono text-xs" :label="__('Tables emptied')" :description="__('Separated by commas: sessions, jobs, tokens…')" />
                        <flux:input wire:model="anonLocale" class="font-mono text-xs" :label="__('Fake data locale')" placeholder="it_IT" />
                        <flux:input wire:model="anonPassword" class="font-mono text-xs" :label="__('Everyone\'s password')" />
                    </div>
                    <flux:checkbox wire:model="anonConsistent" :label="__('Consistent everywhere: the same email becomes the same fake email in every table')" />

                    <div class="flex flex-wrap items-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        @if ($this->can('anonymizer-config'))
                            <flux:select wire:model="anonConnection" size="sm" class="w-72" :label="__('Write it as')">
                                <flux:select.option value="">{{ __('Pick an SSH connection…') }}</flux:select.option>
                                @foreach ($this->sshConnections as $connection)
                                    <flux:select.option value="{{ $connection->uuid }}">{{ $connection->name }} · {{ $connection->address() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:button size="sm" variant="primary" icon="cloud-arrow-up" wire:click="saveAnonymizer">{{ __('Write to the server') }}</flux:button>
                        @endif
                        <flux:button size="sm" icon="arrow-down-tray" wire:click="downloadAnonymizer">{{ __('Download') }}</flux:button>
                        <flux:button size="sm" variant="ghost" icon="shield-check" wire:click="auditAnonymizer">{{ __('Audit') }}</flux:button>
                    </div>
                    @error('anonConnection')<flux:text class="text-xs text-red-600">{{ $message }}</flux:text>@enderror
                    @if ($this->can('anonymizer-config') && $this->sshConnections->isEmpty())
                        <flux:text class="text-xs">{{ __('No SSH connection of the project you can open: an administrator adds the app\'s user in Agent & access, then you set your credential on the SSH page. Or download the file and put it at ~/.db/anonymization.json, or let Cipi write one from the schema:') }}</flux:text>
                    @endif
                @else
                    <flux:text class="text-sm">{{ __('Pick where to start from.') }}</flux:text>
                @endif

                @if ($this->can('anonymizer-config'))
                    <div class="flex flex-wrap items-center gap-2 text-xs text-zinc-500">
                        <flux:button size="xs" variant="ghost" icon="sparkles" wire:click="generateAnonymizerConfig" wire:confirm="{{ __('Write ~/.db/anonymization.json on the server from the live schema (cipi:init-anonymize --scan)? An existing file is replaced.') }}">{{ __('Let Cipi write it from the schema') }}</flux:button>
                        <span>{{ __('runs php artisan cipi:init-anonymize --scan --force on the server') }}</span>
                    </div>
                @endif

                @if ($anonAudit)
                    <div>
                        <flux:heading size="sm">{{ __('Audit') }}</flux:heading>
                        <pre class="mt-2 max-h-80 overflow-auto rounded-lg bg-zinc-950 p-3 font-mono text-[11px] whitespace-pre-wrap text-zinc-100">{{ $anonAudit }}</pre>
                    </div>
                @endif
            </div>
        @endif
    @endif

    {{-- ===================================================== Agent & access --}}
    @if ($tab === 'agent')
        @php($agent = $this->agent)
        <div class="grid gap-5 lg:grid-cols-2">
            <div class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <flux:icon name="cpu-chip" class="size-5 text-zinc-400" />
                        <flux:heading size="sm">{{ __('The Cipi agent in the app') }}</flux:heading>
                    </div>
                    @if ($agent && $agent['reason'] === null)
                        <flux:badge size="sm" color="green">{{ __('MCP on') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="amber">{{ __('not ready') }}</flux:badge>
                    @endif
                </div>
                <flux:text class="text-sm">{{ __('Its MCP server reaches the chats of this project as :name, with no setup: health, deploys and releases, logs, Artisan, database queries and schema, the anonymizer. Those who act on the app get it.', ['name' => $appName]) }}</flux:text>
                <div class="rounded-lg bg-zinc-50 p-2 font-mono text-xs break-all dark:bg-zinc-800/60">{{ $agent['url'] ?? '—' }}</div>
                @if ($agent === null)
                    <flux:callout variant="warning" icon="information-circle"><flux:callout.text>{{ __('The app could not be read.') }}</flux:callout.text></flux:callout>
                @elseif ($agent['reason'])
                    <flux:callout variant="warning" icon="information-circle"><flux:callout.text>{{ $agent['reason'] }}</flux:callout.text></flux:callout>
                    <div class="rounded-lg bg-zinc-950 p-3 font-mono text-[11px] leading-relaxed text-zinc-100">
                        <div class="text-zinc-500"># {{ __('in the app, on the server or through the repository') }}</div>
                        composer require cipi/agent<br>
                        php artisan cipi:service mcp --enable<br>
                        php artisan cipi:generate-token mcp<br>
                        php artisan cipi:service anonymize --enable<br>
                        php artisan cipi:generate-token anonymize
                    </div>
                @endif
                <flux:button size="sm" icon="signal" wire:click="testAgent">{{ __('Check the MCP server') }}</flux:button>
            </div>

            <div class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-center gap-2">
                    <flux:icon name="server-stack" class="size-5 text-zinc-400" />
                    <flux:heading size="sm">{{ __('The Cipi server') }}</flux:heading>
                </div>
                <flux:text class="text-sm">{{ __('The server\'s own MCP server (:name) drives every app of :account, not only this one: off unless an administrator turns it on here. It needs an API token with the mcp-access ability.', ['name' => $serverName, 'account' => $item->account->name]) }}</flux:text>
                <div class="rounded-lg bg-zinc-50 p-2 font-mono text-xs break-all dark:bg-zinc-800/60">{{ \App\Infrastructure\Cipi\CipiApp::fromResource($item)->serverMcp()['url'] }}</div>
                <flux:text class="text-xs">{{ __('Roles that get these servers: those that act on the app (:roles), and the administrators.', ['roles' => collect($item->act_roles)->map(fn ($r) => \App\Enums\UserRole::from($r)->label())->implode(', ') ?: __('none')]) }}</flux:text>
            </div>
        </div>

        @if ($this->isAdmin())
            <form wire:submit="saveAgent" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading size="sm">{{ __('For the administrators') }}</flux:heading>
                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:switch wire:model="mcpApp" :label="__('The app\'s agent goes to the chats (:name)', ['name' => $appName])" />
                    <flux:switch wire:model="mcpServer" :label="__('The whole server goes to the chats (:name)', ['name' => $serverName])" />
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <flux:input wire:model="appUrl" :label="__('App address')" class="font-mono text-xs" :placeholder="__('from APP_URL')" />
                    <flux:input wire:model="mcpToken" type="password" :label="__('MCP token')" :placeholder="$item->setting('mcp_token') ? __('typed in · - clears') : __('from the .env')" />
                    <flux:input wire:model="anonymizerToken" type="password" :label="__('Anonymizer token')" :placeholder="$item->setting('anonymizer_token') ? __('typed in · - clears') : __('from the .env')" />
                </div>
                <flux:text class="text-xs">{{ __('Tokens are read from the app\'s .env through the Cipi API; type them in when the API hides them. Stored encrypted, never shown again.') }}</flux:text>
                <flux:button type="submit" size="sm" variant="primary">{{ __('Save') }}</flux:button>
            </form>
        @endif

        <div class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex items-center gap-2">
                <flux:icon name="command-line" class="size-5 text-zinc-400" />
                <flux:heading size="sm">{{ __('SSH as the app\'s user') }}</flux:heading>
            </div>
            <flux:text class="text-sm">{{ __('The project\'s SSH connections open in the workspace\'s terminal (Remote) and on the SSH page, each person with a credential of their own.') }}</flux:text>
            @forelse ($this->sshConnections as $connection)
                <div class="flex items-center gap-2 text-sm"><flux:icon name="server" variant="micro" class="size-4 text-zinc-400" />{{ $connection->name }} <span class="font-mono text-xs text-zinc-500">{{ $connection->address() }}</span></div>
            @empty
                <flux:text class="text-xs">{{ __('None you can open yet.') }}</flux:text>
            @endforelse
            @if ($this->isAdmin())
                <form wire:submit="addSshConnection" class="flex flex-wrap items-end gap-2 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                    <flux:select wire:model.live="sshAuth" size="sm" class="w-40" :label="__('Add :user@server with', ['user' => $item->resource_id])">
                        <flux:select.option value="password">{{ __('its password') }}</flux:select.option>
                        <flux:select.option value="key">{{ __('a system key') }}</flux:select.option>
                    </flux:select>
                    @if ($sshAuth === 'password')
                        <flux:input wire:model="sshPassword" type="password" size="sm" class="w-56" :placeholder="__('cipi app reset-password :app', ['app' => $item->resource_id])" />
                    @else
                        <flux:select wire:model="sshKey" size="sm" class="w-56">
                            <flux:select.option value="">{{ __('Pick…') }}</flux:select.option>
                            @foreach ($this->systemKeys as $key)
                                <flux:select.option value="{{ $key->uuid }}">{{ $key->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                    <flux:button type="submit" size="sm" icon="plus">{{ __('Add to the project') }}</flux:button>
                </form>
            @endif
        </div>
    @endif

    {{-- ===================================================== every action of the connector --}}
    @if ($tab === 'actions')
        <livewire:projects.resource-console :resource="$item->uuid" :key="'resource-console-'.$item->uuid" />
    @endif
</div>
