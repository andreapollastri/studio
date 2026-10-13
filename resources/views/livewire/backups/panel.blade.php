{{--
    The backups panel: Tools → Backups (everything) and a project's Backups tab (its own).
    $project is null in Tools.
--}}
<div class="space-y-6 max-sm:space-y-4"
    @if ($active) wire:poll.5s @endif
    x-on:backup-download.window="window.open($event.detail.url, '_blank', 'noopener')">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
            <flux:heading size="xl">{{ __('Backups') }}</flux:heading>
            <flux:text class="mt-1">
                @if ($project)
                    {{ __('The backups of :project: its folders over SSH (the site, uploads, a database dump) or its buckets, copied to a bucket or another host on as many schedules as you like, with so many daily, weekly and monthly snapshots kept. Every backup of every project is in Tools → Backups.', ['project' => $project->name]) }}
                @else
                    {{ __('Every backup in one place: a folder over SSH or a bucket, copied to a bucket or a folder on another host, in any direction (SSH → S3, S3 → S3, S3 → SSH), on as many schedules as you like. Studio runs them from this server with a worker of its own, keeps so many daily, weekly and monthly snapshots and deletes the rest.') }}
                @endif
            </flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:dropdown position="bottom" align="end">
                <flux:button icon="circle-stack" icon:trailing="chevron-down">{{ __('Add storage') }}</flux:button>
                <flux:menu>
                    <flux:menu.item icon="archive-box" wire:click="newTarget('s3')">{{ __('S3 bucket') }}</flux:menu.item>
                    <flux:menu.item icon="server" wire:click="newTarget('ssh')">{{ __('Folder over SSH') }}</flux:menu.item>
                    @if ($johnnyReady)
                        <flux:menu.separator />
                        <flux:menu.item icon="sparkles" wire:click="newJohnnyBucket">{{ __('New Johnny bucket with its key') }}</flux:menu.item>
                    @endif
                </flux:menu>
            </flux:dropdown>
            <flux:button variant="primary" icon="plus" wire:click="newJob" :disabled="$this->targets->isEmpty()">{{ __('New backup') }}</flux:button>
        </div>
    </div>

    {{-- Where things stand --}}
    @php($stats = $this->stats)
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Backups') }}</div>
            <div class="mt-1 text-2xl font-semibold tabular-nums">{{ $stats['jobs'] }}</div>
            <div @class(['mt-1 text-xs', 'text-red-600 dark:text-red-400' => $stats['failing'] > 0, 'text-zinc-500' => $stats['failing'] === 0])>
                {{ $stats['failing'] > 0 ? trans_choice(':count failing|:count failing', $stats['failing']) : __('none failing') }}
            </div>
        </div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Snapshots kept') }}</div>
            <div class="mt-1 text-2xl font-semibold tabular-nums">{{ $stats['kept'] }}</div>
            <div class="mt-1 text-xs text-zinc-500">{{ \Illuminate\Support\Number::fileSize($stats['bytes'], 1) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Last 24 hours') }}</div>
            <div class="mt-1 text-2xl font-semibold tabular-nums">{{ $stats['last24'] }}</div>
            <div @class(['mt-1 text-xs', 'text-red-600 dark:text-red-400' => $stats['failed24'] > 0, 'text-zinc-500' => $stats['failed24'] === 0])>
                {{ $stats['failed24'] > 0 ? trans_choice(':count failed|:count failed', $stats['failed24']) : __('all fine') }}
            </div>
        </div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Next run') }}</div>
            @php($next = $this->week['upcoming'][0] ?? null)
            <div class="mt-1 truncate text-2xl font-semibold">{{ $next ? $next['at']->format('H:i') : '—' }}</div>
            <div class="mt-1 truncate text-xs text-zinc-500">{{ $next ? $next['at']->diffForHumans().' · '.$next['job']->name : __('nothing scheduled') }}</div>
        </div>
    </div>

    <flux:radio.group wire:model.live="tab" variant="segmented" class="max-sm:w-full">
        <flux:radio value="jobs" :label="__('Backups')" icon="archive-box-arrow-down" />
        <flux:radio value="schedule" :label="__('Schedule')" icon="calendar-days" />
        <flux:radio value="runs" :label="__('Runs')" icon="queue-list" />
        <flux:radio value="storage" :label="__('Storage')" icon="circle-stack" />
    </flux:radio.group>

    @if ($this->targets->isEmpty() && $tab !== 'storage')
        <div class="rounded-xl border border-dashed border-zinc-300 p-6 dark:border-zinc-700">
            <flux:heading>{{ __('Two steps to the first backup') }}</flux:heading>
            <ol class="mt-3 list-decimal space-y-2 ps-5 text-sm text-zinc-600 dark:text-zinc-300">
                <li>{{ __('Add the storage: the host the files are on (SSH, with one of the system keys of Administration → System) and the bucket they go to (AWS, Johnny or any S3).') }}</li>
                <li>{{ __('Create the backup: what to copy, where, when, and how many snapshots to keep.') }}</li>
            </ol>
            <div class="mt-4 flex flex-wrap gap-2">
                <flux:button size="sm" icon="server" wire:click="newTarget('ssh')">{{ __('Folder over SSH') }}</flux:button>
                <flux:button size="sm" icon="archive-box" wire:click="newTarget('s3')">{{ __('S3 bucket') }}</flux:button>
                @if ($johnnyReady)
                    <flux:button size="sm" icon="sparkles" wire:click="newJohnnyBucket">{{ __('New Johnny bucket') }}</flux:button>
                @endif
            </div>
        </div>
    @endif

    {{-- ===================================================== Backups --}}
    @if ($tab === 'jobs' && $this->targets->isNotEmpty())
        @if ($this->jobs->isEmpty())
            <flux:text>{{ __('No backup yet. “New backup” picks what to copy, where, when and how many snapshots to keep.') }}</flux:text>
        @else
            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="table-cards w-full text-sm">
                    <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-2 text-start">{{ __('Backup') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('From → to') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('When') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Keeps') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Last run') }}</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->jobs as $job)
                            @php($last = $job->latestRun)
                            <tr wire:key="job-{{ $job->uuid }}" @class(['opacity-60' => ! $job->enabled])>
                                <td class="px-4 py-3 align-top" data-cell="main">
                                    <div class="font-medium">{{ $job->name }}</div>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <flux:badge size="sm">{{ \App\Models\BackupJob::modeLabel($job->mode) }}</flux:badge>
                                        @if (! $project && $job->project)
                                            <flux:badge size="sm" color="indigo" :href="route('projects.backups', $job->project)" wire:navigate>{{ $job->project->name }}</flux:badge>
                                        @endif
                                        @unless ($job->enabled)<flux:badge size="sm" color="amber">{{ __('off') }}</flux:badge>@endunless
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top text-xs" data-cell="wide" data-label="{{ __('From → to') }}">
                                    <div class="flex items-center gap-1.5">
                                        <flux:icon :name="$job->source->isSsh() ? 'server' : 'archive-box'" variant="micro" class="size-3.5 shrink-0 text-zinc-400" />
                                        <span class="truncate font-mono" title="{{ $job->source->address() }}">{{ $job->sourceLabel() }}</span>
                                    </div>
                                    <div class="mt-0.5 flex items-center gap-1.5">
                                        <flux:icon name="arrow-down" variant="micro" class="size-3.5 shrink-0 text-zinc-400" />
                                        <span class="truncate font-mono" title="{{ $job->destination->address() }}">{{ $job->destinationLabel() }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top text-xs" data-cell="wide" data-label="{{ __('When') }}">
                                    @forelse ($job->schedules->where('enabled', true) as $schedule)
                                        <div>{{ $schedule->label() }}</div>
                                    @empty
                                        <div class="text-zinc-500">{{ __('only when started') }}</div>
                                    @endforelse
                                    @if ($nextAt = $job->nextRunAt())
                                        <div class="mt-1 text-zinc-500">{{ __('next :when', ['when' => $nextAt->diffForHumans()]) }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top text-xs" data-cell="wide" data-label="{{ __('Keeps') }}">
                                    <div>{{ $job->retentionLabel() }}</div>
                                    @if ($job->keepsVersions())
                                        <div class="mt-1 text-zinc-500">{{ trans_choice(':count snapshot|:count snapshots', $job->snapshots_count) }}@if ($job->snapshots_bytes) · {{ \Illuminate\Support\Number::fileSize((int) $job->snapshots_bytes, 1) }}@endif</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top text-xs" data-cell="wide" data-label="{{ __('Last run') }}">
                                    @if ($last)
                                        <flux:badge size="sm" :color="\App\Models\BackupRun::statusColor($last->status)">{{ __($last->status) }}</flux:badge>
                                        <div class="mt-1 text-zinc-500">{{ ($last->started_at ?? $last->created_at)->diffForHumans() }}@if ($last->size()) · {{ $last->size() }}@endif</div>
                                        @if ($last->status === 'failed' && $last->error)
                                            <div class="mt-1 line-clamp-2 text-red-600 dark:text-red-400" title="{{ $last->error }}">{{ $last->error }}</div>
                                        @endif
                                    @else
                                        <span class="text-zinc-500">{{ __('never') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-end align-top whitespace-nowrap" data-cell="actions">
                                    <flux:button size="xs" icon="play" wire:click="runNow('{{ $job->uuid }}')" :disabled="in_array($last?->status, ['queued', 'running'], true)">{{ __('Run now') }}</flux:button>
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil-square" wire:click="editJob('{{ $job->uuid }}')">{{ __('Edit') }}</flux:menu.item>
                                            <flux:menu.item icon="queue-list" wire:click="showRuns('{{ $job->uuid }}')">{{ __('Runs and snapshots') }}</flux:menu.item>
                                            <flux:menu.item icon="document-duplicate" wire:click="duplicateJob('{{ $job->uuid }}')">{{ __('Duplicate') }}</flux:menu.item>
                                            <flux:menu.item :icon="$job->enabled ? 'pause' : 'play'" wire:click="toggleJob('{{ $job->uuid }}')">{{ $job->enabled ? __('Turn off the schedules') : __('Turn on the schedules') }}</flux:menu.item>
                                            <flux:menu.separator />
                                            <flux:menu.item icon="trash" variant="danger" wire:click="deleteJob('{{ $job->uuid }}')" wire:confirm="{{ __('Delete :name and its history? The snapshots stay at the destination.', ['name' => $job->name]) }}">{{ __('Delete') }}</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif

    {{-- ===================================================== Schedule --}}
    @if ($tab === 'schedule')
        @php($week = $this->week)
        <div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
            <div class="min-w-0 space-y-6">
                {{-- The coming week, hour by hour --}}
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <flux:heading>{{ __('The coming seven days') }}</flux:heading>
                        <flux:text class="text-xs">{{ __('Each square is an hour; the darker, the more backups start in it. Times in :tz.', ['tz' => config('app.timezone')]) }}</flux:text>
                    </div>
                    <div class="mt-3 overflow-x-auto">
                        <div class="inline-grid min-w-full grid-cols-[4.5rem_repeat(24,minmax(1.1rem,1fr))] gap-0.5 text-[10px]">
                            <div></div>
                            @for ($h = 0; $h < 24; $h++)
                                <div class="text-center text-zinc-400">{{ $h % 3 === 0 ? sprintf('%02d', $h) : '' }}</div>
                            @endfor
                            @foreach ($week['days'] as $day)
                                <div class="truncate pe-1 text-xs text-zinc-500">{{ $day['date']->isToday() ? __('Today') : $day['date']->translatedFormat('D j') }}</div>
                                @for ($h = 0; $h < 24; $h++)
                                    @php($names = $day['hours'][$h] ?? [])
                                    @php($level = $names === [] ? 0 : (int) ceil(count($names) / $week['max'] * 4))
                                    <div @class([
                                        'h-5 rounded-sm',
                                        'bg-zinc-100 dark:bg-zinc-800' => $level === 0,
                                        'bg-emerald-200 dark:bg-emerald-900' => $level === 1,
                                        'bg-emerald-300 dark:bg-emerald-700' => $level === 2,
                                        'bg-emerald-500 dark:bg-emerald-500' => $level === 3,
                                        'bg-emerald-700 dark:bg-emerald-300' => $level >= 4,
                                    ]) @if ($names !== []) title="{{ sprintf('%02d:00', $h) }} · {{ collect($names)->countBy()->map(fn ($c, $n) => $c > 1 ? $n.' ×'.$c : $n)->implode(', ') }}" @endif></div>
                                @endfor
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Every schedule --}}
                @if ($this->schedules->isEmpty())
                    <flux:text>{{ __('Nothing scheduled: add a schedule to a backup (Edit) and it runs on its own.') }}</flux:text>
                @else
                    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <table class="table-cards w-full text-sm">
                            <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-4 py-2 text-start">{{ __('Backup') }}</th>
                                    <th class="px-4 py-2 text-start">{{ __('When') }}</th>
                                    <th class="px-4 py-2 text-start">{{ __('Cron') }}</th>
                                    <th class="px-4 py-2 text-start">{{ __('Next run') }}</th>
                                    <th class="px-4 py-2 text-start">{{ __('Last started') }}</th>
                                    <th class="px-4 py-2 text-end">{{ __('On') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($this->schedules as $row)
                                    @php([$schedule, $job, $nextAt] = [$row['schedule'], $row['job'], $row['next']])
                                    <tr wire:key="schedule-{{ $schedule->uuid }}" @class(['opacity-60' => ! $schedule->enabled || ! $job->enabled])>
                                        <td class="px-4 py-2" data-cell="main">
                                            <button type="button" class="font-medium hover:underline" wire:click="editJob('{{ $job->uuid }}')">{{ $job->name }}</button>
                                            <div class="text-xs text-zinc-500">{{ \App\Models\BackupJob::modeLabel($job->mode) }} · {{ $job->retentionLabel() }}</div>
                                        </td>
                                        <td class="px-4 py-2" data-cell="wide" data-label="{{ __('When') }}">
                                            {{ $schedule->label() }}
                                            @if ($job->timezone !== config('app.timezone'))<span class="text-xs text-zinc-500">· {{ $job->timezone }}</span>@endif
                                        </td>
                                        <td class="px-4 py-2 font-mono text-xs" data-cell="wide" data-label="{{ __('Cron') }}">{{ $schedule->expression() }}</td>
                                        <td class="px-4 py-2 text-xs" data-cell="wide" data-label="{{ __('Next run') }}">
                                            @if ($nextAt)
                                                <div class="font-medium">{{ $nextAt->translatedFormat('D j M, H:i') }}</div>
                                                <div class="text-zinc-500">{{ $nextAt->diffForHumans() }}</div>
                                            @else
                                                <span class="text-zinc-500">{{ __('off') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 text-xs text-zinc-500" data-cell="wide" data-label="{{ __('Last started') }}">{{ $schedule->last_dispatched_at?->diffForHumans() ?? __('never') }}</td>
                                        <td class="px-4 py-2 text-end" data-cell="actions">
                                            <flux:switch :checked="$schedule->enabled" wire:click="toggleSchedule('{{ $schedule->uuid }}')" :disabled="! $job->enabled" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- What starts next --}}
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading>{{ __('Up next') }}</flux:heading>
                @forelse ($week['upcoming'] as $item)
                    <div class="mt-3 flex items-start gap-3" wire:key="next-{{ $loop->index }}">
                        <div class="w-14 shrink-0 text-end">
                            <div class="font-mono text-sm font-medium">{{ $item['at']->format('H:i') }}</div>
                            <div class="text-[11px] text-zinc-500">{{ $item['at']->isToday() ? __('today') : ($item['at']->isTomorrow() ? __('tomorrow') : $item['at']->translatedFormat('D j')) }}</div>
                        </div>
                        <div class="min-w-0 border-s border-zinc-200 ps-3 dark:border-zinc-700">
                            <div class="truncate text-sm">{{ $item['job']->name }}</div>
                            <div class="truncate text-xs text-zinc-500">{{ $item['schedule']->label() }}</div>
                        </div>
                    </div>
                @empty
                    <flux:text class="mt-2">{{ __('Nothing in the coming week.') }}</flux:text>
                @endforelse
            </div>
        </div>
    @endif

    {{-- ===================================================== Runs --}}
    @if ($tab === 'runs')
        <div class="flex flex-wrap items-end gap-3">
            <flux:select wire:model.live="runsJob" :label="__('Backup')" class="max-w-xs">
                <flux:select.option value="">{{ __('All') }}</flux:select.option>
                @foreach ($this->jobs as $job)
                    <flux:select.option value="{{ $job->uuid }}">{{ $job->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="runsStatus" :label="__('Showing')" class="max-w-xs">
                <flux:select.option value="">{{ __('Every run') }}</flux:select.option>
                <flux:select.option value="kept">{{ __('Snapshots kept') }}</flux:select.option>
                <flux:select.option value="failed">{{ __('Failed') }}</flux:select.option>
                <flux:select.option value="running">{{ __('Running') }}</flux:select.option>
            </flux:select>
        </div>

        @if ($this->runs->isEmpty())
            <flux:text>{{ __('No run here yet.') }}</flux:text>
        @else
            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="table-cards w-full text-sm">
                    <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-2 text-start">{{ __('Started') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Backup') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Size') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Snapshot') }}</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->runs as $run)
                            <tr wire:key="run-{{ $run->uuid }}">
                                <td class="px-4 py-2 text-xs" data-cell="main">
                                    <div class="font-medium">{{ ($run->started_at ?? $run->created_at)->translatedFormat('D j M, H:i') }}</div>
                                    <div class="text-zinc-500">
                                        {{ match ($run->trigger) { 'schedule' => __('scheduled'), 'restore' => __('restore'), default => __('started by :name', ['name' => $run->user?->name ?? __('someone')]) } }}@if ($run->duration()) · {{ $run->duration() }}@endif
                                    </div>
                                </td>
                                <td class="px-4 py-2" data-cell="wide" data-label="{{ __('Backup') }}">
                                    {{ $run->job->name }}
                                    @if ($run->trigger === 'restore' && $run->restoreOf)
                                        <div class="text-xs text-zinc-500">{{ __('from :path', ['path' => $run->restoreOf->path]) }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-2" data-cell="wide" data-label="{{ __('Status') }}">
                                    <flux:badge size="sm" :color="\App\Models\BackupRun::statusColor($run->status)" :icon="$run->status === 'running' ? 'arrow-path' : null">{{ __($run->status) }}</flux:badge>
                                    @if ($run->error)
                                        <div class="mt-1 line-clamp-2 max-w-sm text-xs text-red-600 dark:text-red-400" title="{{ $run->error }}">{{ $run->error }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-xs" data-cell="wide" data-label="{{ __('Size') }}">
                                    {{ $run->size() ?? '—' }}@if ($run->files) · {{ trans_choice(':count file|:count files', $run->files) }}@endif
                                </td>
                                <td class="px-4 py-2 text-xs" data-cell="wide" data-label="{{ __('Snapshot') }}">
                                    @if ($run->path && $run->trigger !== 'restore')
                                        <div @class(['truncate font-mono max-w-xs', 'line-through text-zinc-400' => $run->pruned_at]) title="{{ $run->path }}">{{ $run->path }}</div>
                                        @if ($run->pruned_at)
                                            <div class="text-zinc-500">{{ __('pruned :when', ['when' => $run->pruned_at->diffForHumans()]) }}</div>
                                        @elseif ($run->kept_by)
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                @foreach ($run->kept_by as $rule)<flux:badge size="sm" color="emerald">{{ __($rule) }}</flux:badge>@endforeach
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-end whitespace-nowrap" data-cell="actions">
                                    <flux:button size="xs" variant="ghost" icon="document-text" wire:click="showLog('{{ $run->uuid }}')" :tooltip="__('Log')" />
                                    @if ($run->hasSnapshot())
                                        @if ($run->isArchive() && $run->job->destination?->isS3())
                                            <flux:button size="xs" variant="ghost" icon="arrow-down-tray" wire:click="download('{{ $run->uuid }}')" :tooltip="__('Download (a link valid for an hour)')" />
                                        @endif
                                        <flux:button size="xs" variant="ghost" icon="arrow-uturn-left" wire:click="askRestore('{{ $run->uuid }}')" :tooltip="__('Restore')" />
                                        @if ($run->job->keepsVersions())
                                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteSnapshot('{{ $run->uuid }}')" wire:confirm="{{ __('Delete this snapshot at the destination? It cannot be restored after.') }}" :tooltip="__('Delete the snapshot')" />
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif

    {{-- ===================================================== Storage --}}
    @if ($tab === 'storage')
        @if ($this->targets->isEmpty())
            <flux:text>{{ __('No storage yet: add the host the files are on and the bucket they go to.') }}</flux:text>
        @else
            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="table-cards w-full text-sm">
                    <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-2 text-start">{{ __('Storage') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Address') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Used by') }}</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->targets as $target)
                            <tr wire:key="target-{{ $target->uuid }}">
                                <td class="px-4 py-2" data-cell="main">
                                    <div class="flex items-center gap-2 font-medium">
                                        <flux:icon :name="$target->isSsh() ? 'server' : 'archive-box'" variant="micro" class="size-4 text-zinc-400" />{{ $target->name }}
                                    </div>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <flux:badge size="sm">{{ $target->isSsh() ? 'SSH' : (\App\Models\BackupTarget::PROVIDERS[$target->setting('provider', 'other')] ?? 'S3') }}</flux:badge>
                                        @if ($target->project)
                                            <flux:badge size="sm" color="indigo">{{ $target->project->name }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('shared') }}</flux:badge>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-xs" data-cell="wide" data-label="{{ __('Address') }}">
                                    <div class="font-mono">{{ $target->address() }}</div>
                                    @if ($target->isSsh())
                                        <div class="mt-0.5 text-zinc-500">{{ __('key :key', ['key' => $target->sshKey()?->name ?? __('gone')]) }} · <span class="font-mono" title="{{ __('Host key accepted') }}">{{ \Illuminate\Support\Str::limit((string) $target->setting('fingerprint'), 30) }}</span></div>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-xs" data-cell="wide" data-label="{{ __('Status') }}">
                                    @if ($target->status)
                                        <span @class(['text-green-600 dark:text-green-400' => $target->isHealthy(), 'text-red-600 dark:text-red-400' => ! $target->isHealthy()])>{{ \Illuminate\Support\Str::limit($target->status, 120) }}</span>
                                        <div class="text-zinc-500">{{ $target->checked_at?->diffForHumans() }}</div>
                                    @else
                                        <span class="text-zinc-500">{{ __('not checked') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-xs text-zinc-500" data-cell="wide" data-label="{{ __('Used by') }}">
                                    {{ trans_choice(':count backup|:count backups', $target->source_of_count + $target->destination_of_count) }}
                                </td>
                                <td class="px-4 py-2 text-end whitespace-nowrap" data-cell="actions">
                                    <flux:button size="xs" variant="ghost" icon="signal" wire:click="testTarget('{{ $target->uuid }}')" :tooltip="__('Check')" />
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil-square" wire:click="editTarget('{{ $target->uuid }}')">{{ __('Edit') }}</flux:menu.item>
                                            @if ($target->isSsh())
                                                <flux:menu.item icon="finger-print" wire:click="rescanHostKey('{{ $target->uuid }}')" wire:confirm="{{ __('Accept the host key :host shows now? Only when you know it changed (the server was reinstalled).', ['host' => $target->setting('host')]) }}">{{ __('Accept a new host key') }}</flux:menu.item>
                                            @endif
                                            <flux:menu.separator />
                                            <flux:menu.item icon="trash" variant="danger" wire:click="deleteTarget('{{ $target->uuid }}')" wire:confirm="{{ __('Remove :name from Studio? Nothing is deleted on the storage.', ['name' => $target->name]) }}">{{ __('Remove') }}</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <flux:text class="text-xs">{{ __('The keys of the buckets are stored encrypted and never shown again. SSH hosts are reached with a system key (Administration → System; add its public half to the host\'s authorized_keys) and their host key is accepted on the first check, then required.') }}</flux:text>
    @endif

    {{-- ===================================================== the backup --}}
    <flux:modal name="backup-job" class="w-full md:w-[48rem]">
        <form wire:submit="saveJob" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $job_id ? __('Edit the backup') : __('New backup') }}</flux:heading>
                <flux:text class="mt-1">{{ __('What to copy, where to, how, when, and what to keep.') }}</flux:text>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="job_name" :label="__('Name')" :placeholder="__('Shop · files and database')" required />
                @if (! $project)
                    <flux:select wire:model="job_project" :label="__('Project')">
                        <flux:select.option value="">{{ __('None') }}</flux:select.option>
                        @foreach ($projects as $item)
                            <flux:select.option value="{{ $item->uuid }}">{{ $item->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>

            {{-- From and to --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:heading size="sm">{{ __('From') }}</flux:heading>
                    <flux:select wire:model.live="job_source" :label="__('Source')">
                        <flux:select.option value="">{{ __('Pick…') }}</flux:select.option>
                        @foreach ($this->targets as $target)
                            <flux:select.option value="{{ $target->uuid }}">{{ $target->isSsh() ? 'SSH' : 'S3' }} · {{ $target->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="job_source_path" :label="__('Folder or prefix')" class="font-mono" placeholder="current" :description="__('On a host: under the user\'s home unless it starts with /. In a bucket: a prefix, empty for all of it.')" />
                </div>
                <div class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:heading size="sm">{{ __('To') }}</flux:heading>
                    <flux:select wire:model.live="job_destination" :label="__('Destination')">
                        <flux:select.option value="">{{ __('Pick…') }}</flux:select.option>
                        @foreach ($this->targets as $target)
                            <flux:select.option value="{{ $target->uuid }}">{{ $target->isSsh() ? 'SSH' : 'S3' }} · {{ $target->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="job_destination_path" :label="__('Folder or prefix')" class="font-mono" placeholder="shop" :description="__('Each run goes under it: shop/shop-files-2026-10-13_030000.tar.gz')" />
                </div>
            </div>

            {{-- How --}}
            <div class="space-y-3">
                <flux:radio.group wire:model.live="job_mode" :label="__('How')" variant="cards" class="max-sm:flex-col">
                    <flux:radio value="archive" :label="__('Archive')" :description="__('A .tar.gz made on the SSH host, streamed to the destination. Best for a site.')" />
                    <flux:radio value="snapshot" :label="__('Snapshot')" :description="__('A dated copy of every file. From a bucket too.')" />
                    <flux:radio value="mirror" :label="__('Mirror')" :description="__('One copy kept identical to the source. No history.')" />
                </flux:radio.group>
                @error('job_mode')<flux:text class="text-sm text-red-600">{{ $message }}</flux:text>@enderror
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:textarea wire:model="job_pre_command" :label="__('First, run on the source (SSH)')" rows="2" class="font-mono text-xs"
                        placeholder="mysqldump --single-transaction shop > ~/current/storage/db.sql"
                        :description="__('Optional: a database dump into the folder, so it lands in the backup. It must succeed for the copy to start.')" />
                    <flux:textarea wire:model="job_excludes" :label="__('Leave out')" rows="2" class="font-mono text-xs" placeholder="storage/logs&#10;node_modules&#10;*.tmp" :description="__('One pattern per line')" />
                </div>
            </div>

            {{-- When --}}
            <div class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="sm">{{ __('When') }}</flux:heading>
                    <div class="flex flex-wrap items-center gap-1">
                        <flux:text class="me-1 text-xs">{{ __('Ready-made:') }}</flux:text>
                        <flux:button size="xs" variant="ghost" wire:click="preset('nightly')">{{ __('Nightly') }}</flux:button>
                        <flux:button size="xs" variant="ghost" wire:click="preset('twice')">{{ __('Twice a day') }}</flux:button>
                        <flux:button size="xs" variant="ghost" wire:click="preset('hourly')">{{ __('Hourly') }}</flux:button>
                        <flux:button size="xs" variant="ghost" wire:click="preset('gfs')">{{ __('Daily + weekly + monthly') }}</flux:button>
                    </div>
                </div>
                <div class="space-y-2">
                    @forelse ($job_schedules as $i => $schedule)
                        <div class="flex flex-wrap items-end gap-2 rounded-lg bg-zinc-50 p-2 dark:bg-zinc-800/60" wire:key="schedule-row-{{ $i }}">
                            <flux:select wire:model.live="job_schedules.{{ $i }}.frequency" size="sm" class="w-36">
                                <flux:select.option value="hourly">{{ __('Every hour') }}</flux:select.option>
                                <flux:select.option value="daily">{{ __('Every day') }}</flux:select.option>
                                <flux:select.option value="weekly">{{ __('Some weekdays') }}</flux:select.option>
                                <flux:select.option value="monthly">{{ __('Every month') }}</flux:select.option>
                                <flux:select.option value="cron">{{ __('Cron') }}</flux:select.option>
                            </flux:select>
                            @switch($schedule['frequency'])
                                @case('weekly')
                                    <div class="flex gap-0.5">
                                        @foreach ([1, 2, 3, 4, 5, 6, 0] as $day)
                                            <label class="cursor-pointer">
                                                <input type="checkbox" class="peer sr-only" value="{{ $day }}" wire:model.live="job_schedules.{{ $i }}.weekdays">
                                                <span class="flex h-8 w-9 items-center justify-center rounded-md border border-zinc-200 text-xs peer-checked:border-zinc-900 peer-checked:bg-zinc-900 peer-checked:text-white dark:border-zinc-600 dark:peer-checked:border-white dark:peer-checked:bg-white dark:peer-checked:text-zinc-900">{{ \App\Models\BackupSchedule::dayName($day) }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @break
                                @case('monthly')
                                    <flux:input type="number" min="1" max="28" wire:model="job_schedules.{{ $i }}.monthday" size="sm" class="w-20" :aria-label="__('Day of the month')" :placeholder="__('day')" />
                                    @break
                            @endswitch
                            @if ($schedule['frequency'] === 'cron')
                                <flux:input wire:model="job_schedules.{{ $i }}.cron" size="sm" class="w-48 font-mono" placeholder="30 2 * * 1-5" :aria-label="__('Cron expression')" />
                            @elseif ($schedule['frequency'] === 'hourly')
                                <div class="flex items-center gap-1 text-sm">
                                    <span class="text-zinc-500">{{ __('at minute') }}</span>
                                    <flux:input type="number" min="0" max="59" wire:model="job_schedules.{{ $i }}.minute" size="sm" class="w-20" />
                                </div>
                            @else
                                <div class="flex items-center gap-1 text-sm">
                                    <span class="text-zinc-500">{{ __('at') }}</span>
                                    <flux:input type="number" min="0" max="23" wire:model="job_schedules.{{ $i }}.hour" size="sm" class="w-16" :aria-label="__('Hour')" />
                                    <span>:</span>
                                    <flux:input type="number" min="0" max="59" wire:model="job_schedules.{{ $i }}.minute" size="sm" class="w-16" :aria-label="__('Minute')" />
                                </div>
                            @endif
                            <flux:spacer />
                            <flux:checkbox wire:model="job_schedules.{{ $i }}.enabled" :label="__('On')" />
                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeSchedule({{ $i }})" :aria-label="__('Remove')" />
                            @error("job_schedules.{$i}.cron")<div class="w-full text-xs text-red-600">{{ $message }}</div>@enderror
                            @error("job_schedules.{$i}.weekdays")<div class="w-full text-xs text-red-600">{{ $message }}</div>@enderror
                            @error("job_schedules.{$i}.monthday")<div class="w-full text-xs text-red-600">{{ $message }}</div>@enderror
                        </div>
                    @empty
                        <flux:text class="text-sm">{{ __('No schedule: it runs only when started from here.') }}</flux:text>
                    @endforelse
                </div>
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <flux:button size="sm" icon="plus" wire:click="addSchedule">{{ __('Add a schedule') }}</flux:button>
                    <flux:select wire:model="job_timezone" :label="__('Timezone')" size="sm" class="w-56">
                        @foreach ($timezones as $timezone)
                            <flux:select.option value="{{ $timezone }}">{{ $timezone }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            {{-- What to keep --}}
            @if ($job_mode !== 'mirror')
                <div class="space-y-2">
                    <flux:heading size="sm">{{ __('Keep') }}</flux:heading>
                    <div class="grid grid-cols-5 gap-2 max-sm:grid-cols-3">
                        @foreach (['last' => __('Latest'), 'daily' => __('Daily'), 'weekly' => __('Weekly'), 'monthly' => __('Monthly'), 'yearly' => __('Yearly')] as $key => $label)
                            <flux:input type="number" min="0" max="1000" wire:model="job_retention.{{ $key }}" :label="$label" size="sm" />
                        @endforeach
                    </div>
                    <flux:text class="text-xs">{{ __('The latest snapshots, plus the newest one of each of the last days, weeks, months and years that have one. After each run Studio deletes the snapshots no rule keeps. The newest always stays.') }}</flux:text>
                </div>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:checkbox wire:model="job_enabled" :label="__('The schedules are on')" />
                <div class="flex gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>

    {{-- ===================================================== the storage --}}
    <flux:modal name="backup-target" class="w-full md:w-[36rem]">
        <form wire:submit="saveTarget" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $target_id ? __('Edit the storage') : ($target_kind === 's3' ? __('S3 bucket') : __('Folder over SSH')) }}</flux:heading>
                <flux:text class="mt-1">{{ $target_kind === 's3' ? __('Amazon S3, a Johnny bucket or any S3-compatible storage: a key that may read, write and delete in the bucket.') : __('A host Studio reaches with one of the system keys: add its public half to ~/.ssh/authorized_keys of the user there. The host key is accepted now and required on every connection.') }}</flux:text>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="target_name" :label="__('Name')" required />
                @if (! $project)
                    <flux:select wire:model="target_project" :label="__('Project')">
                        <flux:select.option value="">{{ __('Shared') }}</flux:select.option>
                        @foreach ($projects as $item)
                            <flux:select.option value="{{ $item->uuid }}">{{ $item->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>

            @if ($target_kind === 's3')
                @if (! $target_id && $this->connectorAccounts->where('connector', 'aws')->isNotEmpty())
                    <flux:select wire:model.live="s3_from_account" :label="__('Use the keys of an AWS account')" :description="__('From Administration → Connectors; better a key of its own, limited to the bucket.')">
                        <flux:select.option value="">{{ __('No, type them') }}</flux:select.option>
                        @foreach ($this->connectorAccounts->where('connector', 'aws') as $account)
                            <flux:select.option value="{{ $account->uuid }}">{{ $account->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model.live="s3_provider" :label="__('Provider')">
                        @foreach (\App\Models\BackupTarget::PROVIDERS as $key => $label)
                            <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="s3_bucket" :label="__('Bucket')" class="font-mono" required />
                </div>
                <div class="grid gap-4 sm:grid-cols-[2fr_1fr]">
                    <flux:input wire:model="s3_endpoint" :label="__('Endpoint')" class="font-mono" :placeholder="$s3_provider === 'aws' ? __('empty for AWS') : 'https://s3.example.com'" />
                    <flux:input wire:model="s3_region" :label="__('Region')" class="font-mono" :placeholder="$s3_provider === 'aws' ? 'eu-central-1' : 'garage'" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="s3_access_key_id" :label="__('Access key ID')" class="font-mono" required />
                    <flux:input wire:model="s3_secret_access_key" type="password" :label="__('Secret access key')" :placeholder="$target_id ? __('unchanged') : ($s3_from_account ? __('the account\'s') : '')" viewable />
                </div>
                <flux:checkbox wire:model="s3_path_style" :label="__('Path-style addresses (endpoint/bucket): Johnny, MinIO, Garage')" />
            @else
                @if ($this->sshConnections->isNotEmpty())
                    <flux:select x-on:change="$wire.fillFromConnection($event.target.value)" :label="__('Fill from a saved SSH connection')">
                        <flux:select.option value="">{{ __('No, type them') }}</flux:select.option>
                        @foreach ($this->sshConnections as $connection)
                            <flux:select.option value="{{ $connection->uuid }}">{{ $connection->name }} · {{ $connection->address() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                <div class="grid gap-4 sm:grid-cols-[2fr_1fr]">
                    <flux:input wire:model="ssh_host" :label="__('Host')" class="font-mono" placeholder="web.example.com" required />
                    <flux:input wire:model="ssh_port" type="number" :label="__('Port')" required />
                </div>
                <flux:input wire:model="ssh_username" :label="__('User')" class="font-mono" placeholder="shop" required />
                <flux:select wire:model="ssh_key" :label="__('System key')" :description="__('Administration → System → Keys. The keys shared with this project come first.')">
                    <flux:select.option value="">{{ __('Pick…') }}</flux:select.option>
                    @foreach ($this->systemKeys as $key)
                        <flux:select.option value="{{ $key->uuid }}">{{ $key->name }} · {{ $key->label() }}{{ $project && $key->projects->contains('id', $project->id) ? ' · '.__('shared with the project') : '' }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($ssh_key && ($picked = $this->systemKeys->firstWhere('uuid', $ssh_key)))
                    <div class="rounded-lg bg-zinc-50 p-2 dark:bg-zinc-800/60">
                        <flux:text class="text-xs">{{ __('Its public half, for authorized_keys on the host:') }}</flux:text>
                        <div class="mt-1 break-all font-mono text-[11px]">{{ $picked->public_key }}</div>
                    </div>
                @endif
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save and check') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- ===================================================== a Johnny bucket --}}
    @if ($johnnyReady)
        <flux:modal name="backup-johnny" class="w-full md:w-[30rem]">
            <form wire:submit="provisionJohnny" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('New Johnny bucket') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Johnny makes the bucket and a key that reaches it alone; Studio keeps the key, encrypted, as storage for the backups.') }}</flux:text>
                </div>
                <flux:select wire:model="johnny_account" :label="__('Johnny panel')">
                    @foreach ($this->connectorAccounts->where('connector', 'johnny') as $account)
                        <flux:select.option value="{{ $account->uuid }}">{{ $account->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="johnny_bucket" :label="__('Bucket name')" class="font-mono" :placeholder="__('empty: a random b-… name')" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" icon="sparkles">{{ __('Create') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    {{-- ===================================================== a restore --}}
    <flux:modal name="backup-restore" class="w-full md:w-[32rem]">
        <form wire:submit="restore" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Restore') }}</flux:heading>
                @if ($restoring)
                    <flux:text class="mt-1">
                        {{ __(':snapshot of :job, from :when.', ['snapshot' => $restoring->path, 'job' => $restoring->job->name, 'when' => ($restoring->started_at ?? $restoring->created_at)->translatedFormat('D j M Y, H:i')]) }}
                        {{ $restoring->isArchive() ? __('On an SSH host the archive is unpacked into the folder; in a bucket it is copied as it is.') : __('The files are copied into the folder.') }}
                    </flux:text>
                @endif
            </div>
            <flux:select wire:model="restore_target" :label="__('Where')">
                @foreach ($this->targets as $target)
                    <flux:select.option value="{{ $target->uuid }}">{{ $target->isSsh() ? 'SSH' : 'S3' }} · {{ $target->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="restore_path" :label="__('Folder')" class="font-mono" :description="__('A new folder is safest: compare, then swap. Files with the same name are overwritten.')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="arrow-uturn-left">{{ __('Restore') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- ===================================================== a run's log --}}
    <flux:modal name="backup-log" class="w-full md:w-[48rem]">
        @if ($log)
            <div class="space-y-3">
                <div>
                    <flux:heading size="lg">{{ $log->job->name }}</flux:heading>
                    <flux:text class="mt-1">{{ ($log->started_at ?? $log->created_at)->translatedFormat('D j M Y, H:i') }} · {{ __($log->status) }}@if ($log->duration()) · {{ $log->duration() }}@endif</flux:text>
                </div>
                @if ($log->error)
                    <flux:callout variant="danger" icon="exclamation-triangle"><flux:callout.text class="whitespace-pre-wrap">{{ $log->error }}</flux:callout.text></flux:callout>
                @endif
                <pre class="max-h-[60vh] overflow-auto rounded-lg bg-zinc-950 p-3 font-mono text-[11px] leading-snug whitespace-pre-wrap text-zinc-100">{{ $log->output ?: __('Nothing written yet.') }}</pre>
            </div>
        @endif
    </flux:modal>
</div>
