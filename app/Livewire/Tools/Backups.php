<?php

namespace App\Livewire\Tools;

use App\Backups\Backups as Starter;
use App\Backups\Engine;
use App\Infrastructure\ConnectorException;
use App\Infrastructure\Connectors;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\BackupSchedule;
use App\Models\BackupTarget;
use App\Models\ConnectorAccount;
use App\Models\Project;
use App\Models\SshConnection;
use App\Models\SshKey;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Every backup in one place (Tools → Backups), or a project's own (its Backups tab): the jobs
 * that copy a folder over SSH or a bucket to a bucket or a folder, any direction, on as many
 * schedules as wanted, with the snapshots each keeps (so many daily, weekly, monthly); the
 * schedule of the coming week; every run with its log, to download or put back; the buckets
 * and hosts they use. For the administrators: it holds the keys of the storage.
 */
class Backups extends Component
{
    public const TABS = ['jobs', 'schedule', 'runs', 'storage'];

    public ?Project $project = null;

    #[Url]
    public string $tab = 'jobs';

    #[Url(as: 'job')]
    public ?string $runsJob = null;

    #[Url(as: 'status')]
    public string $runsStatus = '';

    // ---- the backup in the form

    public ?string $job_id = null;

    public string $job_name = '';

    /** uuid, or '' for none */
    public string $job_project = '';

    public string $job_source = '';

    public string $job_source_path = '';

    public string $job_destination = '';

    public string $job_destination_path = '';

    public string $job_mode = 'archive';

    public string $job_pre_command = '';

    public string $job_excludes = '';

    /** @var array{last: int|string, daily: int|string, weekly: int|string, monthly: int|string, yearly: int|string} */
    public array $job_retention = BackupJob::RETENTION_DEFAULTS;

    public string $job_timezone = 'UTC';

    public bool $job_enabled = true;

    /** @var list<array{uuid: string|null, frequency: string, hour: int|string, minute: int|string, weekdays: list<string>, monthday: int|string, cron: string, enabled: bool}> */
    public array $job_schedules = [];

    // ---- the storage in the form

    public ?string $target_id = null;

    public string $target_kind = 's3';

    public string $target_name = '';

    public string $target_project = '';

    public string $s3_provider = 'other';

    public string $s3_endpoint = '';

    public string $s3_region = '';

    public string $s3_bucket = '';

    public string $s3_access_key_id = '';

    public string $s3_secret_access_key = '';

    public bool $s3_path_style = true;

    /** uuid of an AWS connector account whose keys fill the form */
    public string $s3_from_account = '';

    public string $ssh_host = '';

    public int|string $ssh_port = 22;

    public string $ssh_username = '';

    /** uuid of a system key */
    public string $ssh_key = '';

    // ---- a Johnny bucket made for the backups

    public string $johnny_account = '';

    public string $johnny_bucket = '';

    // ---- a restore

    public ?string $restoreRun = null;

    public string $restore_target = '';

    public string $restore_path = '';

    // ---- a run's log

    public ?string $logRun = null;

    public function mount(?Project $project = null): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $this->project = $project?->exists ? $project : null;
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'jobs';
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'jobs';
    }

    // ------------------------------------------------------------------ reading

    /** @return Builder<BackupJob> */
    private function jobQuery(): Builder
    {
        return BackupJob::query()->when($this->project, fn (Builder $q) => $q->where('project_id', $this->project->id));
    }

    /** @return Collection<int, BackupJob> */
    #[Computed]
    public function jobs(): Collection
    {
        return $this->jobQuery()->with(['project', 'source', 'destination', 'schedules', 'latestRun'])
            ->withCount(['runs as snapshots_count' => fn ($q) => $q->where('status', 'success')->where('trigger', '!=', 'restore')->whereNull('pruned_at')->whereNotNull('path')])
            ->withSum(['runs as snapshots_bytes' => fn ($q) => $q->where('status', 'success')->where('trigger', '!=', 'restore')->whereNull('pruned_at')], 'bytes')
            ->orderBy('name')->get();
    }

    /** The storage this page may use: everything in Tools; in a project, its own and the shared ones. */
    /** @return Collection<int, BackupTarget> */
    #[Computed]
    public function targets(): Collection
    {
        return BackupTarget::query()
            ->when($this->project, fn (Builder $q) => $q->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $this->project->id)))
            ->with('project')->withCount(['sourceOf', 'destinationOf'])->orderBy('name')->get();
    }

    /** @return Collection<int, BackupRun> */
    #[Computed]
    public function runs(): Collection
    {
        return BackupRun::query()
            ->whereIn('backup_job_id', $this->jobQuery()->select('id'))
            ->when($this->runsJob, fn (Builder $q) => $q->whereHas('job', fn ($j) => $j->where('uuid', $this->runsJob)))
            ->when($this->runsStatus !== '', fn (Builder $q) => $this->runsStatus === 'kept'
                ? $q->where('status', 'success')->whereNull('pruned_at')->whereNotNull('path')->where('trigger', '!=', 'restore')
                : $q->where('status', $this->runsStatus))
            ->with(['job.destination', 'user', 'schedule', 'restoreOf'])
            ->latest('id')->limit(200)->get();
    }

    /**
     * Every schedule of the jobs on this page with its next run.
     *
     * @return \Illuminate\Support\Collection<int, array{schedule: BackupSchedule, job: BackupJob, next: Carbon|null}>
     */
    #[Computed]
    public function schedules(): \Illuminate\Support\Collection
    {
        return $this->jobs->flatMap(fn (BackupJob $job) => $job->schedules->map(fn (BackupSchedule $schedule) => [
            'schedule' => $schedule->setRelation('job', $job),
            'job' => $job,
            'next' => $job->enabled && $schedule->enabled ? $schedule->nextRunAt() : null,
        ]))->sortBy(fn (array $row) => $row['next']?->getTimestamp() ?? PHP_INT_MAX)->values();
    }

    /**
     * The coming seven days, hour by hour: how many runs start in each hour, and which.
     *
     * @return array{days: list<array{date: Carbon, hours: array<int, list<string>>}>, max: int, upcoming: list<array{at: Carbon, job: BackupJob, schedule: BackupSchedule}>}
     */
    #[Computed]
    public function week(): array
    {
        $from = now()->startOfHour();
        $until = $from->copy()->addDays(7);
        $days = [];
        $upcoming = [];

        for ($d = 0; $d < 7; $d++) {
            $days[$from->copy()->startOfDay()->addDays($d)->format('Y-m-d')] = ['date' => $from->copy()->startOfDay()->addDays($d), 'hours' => []];
        }

        foreach ($this->schedules as $row) {
            if ($row['next'] === null) {
                continue;
            }

            foreach ($row['schedule']->runsBetween($from->copy()->subSecond(), $until, 400) as $at) {
                $key = $at->format('Y-m-d');

                if (isset($days[$key])) {
                    $days[$key]['hours'][(int) $at->format('G')][] = $row['job']->name;
                }

                $upcoming[] = ['at' => $at, 'job' => $row['job'], 'schedule' => $row['schedule']];
            }
        }

        usort($upcoming, fn (array $a, array $b) => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());
        $max = 1;

        foreach ($days as $day) {
            foreach ($day['hours'] as $names) {
                $max = max($max, count($names));
            }
        }

        return ['days' => array_values($days), 'max' => $max, 'upcoming' => array_slice($upcoming, 0, 12)];
    }

    /** @return array{jobs: int, failing: int, kept: int, bytes: int, last24: int, failed24: int} */
    #[Computed]
    public function stats(): array
    {
        $runs = BackupRun::query()->whereIn('backup_job_id', $this->jobQuery()->select('id'))->where('trigger', '!=', 'restore');
        $since = now()->subDay();

        return [
            'jobs' => $this->jobs->count(),
            'failing' => $this->jobs->where('last_status', 'failed')->count(),
            'kept' => (int) $this->jobs->sum('snapshots_count'),
            'bytes' => (int) $this->jobs->sum('snapshots_bytes'),
            'last24' => (clone $runs)->where('created_at', '>=', $since)->count(),
            'failed24' => (clone $runs)->where('created_at', '>=', $since)->where('status', 'failed')->count(),
        ];
    }

    public function hasActiveRuns(): bool
    {
        return BackupRun::query()->whereIn('backup_job_id', $this->jobQuery()->select('id'))->whereIn('status', ['queued', 'running'])->exists();
    }

    // ------------------------------------------------------------------ jobs

    public function newJob(): void
    {
        $this->resetJobForm();
        $this->job_project = (string) $this->project?->uuid;
        $this->job_timezone = $this->project?->timezone() ?? (string) config('app.timezone', 'UTC');
        $ssh = $this->targets->firstWhere('kind', 'ssh');
        $s3 = $this->targets->firstWhere('kind', 's3');
        $this->job_source = (string) $ssh?->uuid;
        $this->job_destination = (string) $s3?->uuid;
        $this->job_name = $this->project ? $this->project->name.' · '.__('files') : '';
        $this->job_destination_path = $this->project ? $this->project->slug : '';
        $this->job_schedules = [$this->blankSchedule()];

        Flux::modal('backup-job')->show();
    }

    public function editJob(string $uuid): void
    {
        $job = $this->job($uuid)->load(['source', 'destination', 'schedules', 'project']);
        $this->resetJobForm();

        $this->job_id = $job->uuid;
        $this->job_name = $job->name;
        $this->job_project = (string) $job->project?->uuid;
        $this->job_source = $job->source->uuid;
        $this->job_source_path = $job->source_path;
        $this->job_destination = $job->destination->uuid;
        $this->job_destination_path = $job->destination_path;
        $this->job_mode = $job->mode;
        $this->job_pre_command = $job->preCommand();
        $this->job_excludes = implode("\n", $job->excludes());
        $this->job_retention = $job->retentionPolicy();
        $this->job_timezone = $job->timezone;
        $this->job_enabled = $job->enabled;
        $this->job_schedules = $job->schedules->sortBy('id')->map(fn (BackupSchedule $s) => [
            'uuid' => $s->uuid,
            'frequency' => $s->frequency,
            'hour' => $s->hour,
            'minute' => $s->minute,
            'weekdays' => array_map('strval', $s->days()),
            'monthday' => $s->monthday,
            'cron' => (string) $s->cron,
            'enabled' => $s->enabled,
        ])->values()->all();

        Flux::modal('backup-job')->show();
    }

    /** A copy of a backup, to change what differs: another folder, another bucket. */
    public function duplicateJob(string $uuid): void
    {
        $this->editJob($uuid);
        $this->job_id = null;
        $this->job_name .= ' '.__('(copy)');
        $this->job_schedules = array_map(fn (array $s) => ['uuid' => null] + $s, $this->job_schedules);
    }

    private function resetJobForm(): void
    {
        $this->reset(['job_id', 'job_name', 'job_project', 'job_source', 'job_source_path', 'job_destination', 'job_destination_path', 'job_mode', 'job_pre_command', 'job_excludes', 'job_retention', 'job_enabled', 'job_schedules']);
        $this->resetValidation();
    }

    /** @return array{uuid: null, frequency: string, hour: int, minute: int, weekdays: list<string>, monthday: int, cron: string, enabled: bool} */
    private function blankSchedule(): array
    {
        return ['uuid' => null, 'frequency' => 'daily', 'hour' => 3, 'minute' => 0, 'weekdays' => ['1'], 'monthday' => 1, 'cron' => '', 'enabled' => true];
    }

    public function addSchedule(): void
    {
        $this->job_schedules[] = $this->blankSchedule();
    }

    public function removeSchedule(int $index): void
    {
        unset($this->job_schedules[$index]);
        $this->job_schedules = array_values($this->job_schedules);
    }

    /** A ready-made plan: hourly, daily at night, weekly on Sunday, monthly on the 1st. */
    public function preset(string $preset): void
    {
        $this->job_schedules = match ($preset) {
            'nightly' => [['frequency' => 'daily', 'hour' => 3, 'minute' => 0] + $this->blankSchedule()],
            'twice' => [['frequency' => 'daily', 'hour' => 3, 'minute' => 0] + $this->blankSchedule(), ['frequency' => 'daily', 'hour' => 13, 'minute' => 0] + $this->blankSchedule()],
            'hourly' => [['frequency' => 'hourly', 'minute' => 5] + $this->blankSchedule()],
            'gfs' => [
                ['frequency' => 'daily', 'hour' => 2, 'minute' => 30] + $this->blankSchedule(),
                ['frequency' => 'weekly', 'hour' => 4, 'minute' => 0, 'weekdays' => ['0']] + $this->blankSchedule(),
                ['frequency' => 'monthly', 'hour' => 5, 'minute' => 0, 'monthday' => 1] + $this->blankSchedule(),
            ],
            default => $this->job_schedules,
        };

        if ($preset === 'gfs') {
            $this->job_retention = ['last' => 3, 'daily' => 7, 'weekly' => 5, 'monthly' => 12, 'yearly' => 2];
        }
    }

    public function saveJob(): void
    {
        $targets = $this->targets->pluck('uuid')->all();

        $this->validate([
            'job_name' => ['required', 'string', 'max:120'],
            'job_project' => ['nullable', Rule::exists('projects', 'uuid')],
            'job_source' => ['required', Rule::in($targets)],
            'job_source_path' => ['nullable', 'string', 'max:1024', 'not_regex:/[\x00\r\n]/'],
            'job_destination' => ['required', Rule::in($targets)],
            'job_destination_path' => ['nullable', 'string', 'max:1024', 'not_regex:/[\x00\r\n]/'],
            'job_mode' => ['required', Rule::in(BackupJob::MODES)],
            'job_pre_command' => ['nullable', 'string', 'max:2000'],
            'job_excludes' => ['nullable', 'string', 'max:4000'],
            'job_retention.*' => ['required', 'integer', 'min:0', 'max:1000'],
            'job_timezone' => ['required', Rule::in(timezone_identifiers_list())],
            'job_schedules' => ['array', 'max:50'],
            'job_schedules.*.frequency' => ['required', Rule::in(BackupSchedule::FREQUENCIES)],
            'job_schedules.*.hour' => ['required', 'integer', 'min:0', 'max:23'],
            'job_schedules.*.minute' => ['required', 'integer', 'min:0', 'max:59'],
            'job_schedules.*.monthday' => ['required', 'integer', 'min:1', 'max:28'],
            'job_schedules.*.weekdays' => ['array'],
            'job_schedules.*.weekdays.*' => [Rule::in(['0', '1', '2', '3', '4', '5', '6'])],
        ], [], [
            'job_source' => __('source'),
            'job_destination' => __('destination'),
            'job_schedules.*.monthday' => __('day of the month'),
        ]);

        $source = $this->targets->firstWhere('uuid', $this->job_source);
        $destination = $this->targets->firstWhere('uuid', $this->job_destination);
        $errors = [];

        if ($this->job_mode === 'archive' && ! $source->isSsh()) {
            $errors['job_mode'] = __('An archive is made on the source host: pick an SSH source, or a snapshot for a bucket.');
        }

        if (trim($this->job_pre_command) !== '' && ! $source->isSsh()) {
            $errors['job_pre_command'] = __('A command runs on an SSH source only.');
        }

        if ($source->id === $destination->id && trim($this->job_source_path, '/') === trim($this->job_destination_path, '/')) {
            $errors['job_destination_path'] = __('The destination cannot be the source itself.');
        }

        foreach ($this->job_schedules as $i => $schedule) {
            if ($schedule['frequency'] === 'cron' && ! BackupSchedule::validExpression((string) $schedule['cron'])) {
                $errors["job_schedules.{$i}.cron"] = __('Not a cron expression: five fields, like 30 2 * * 1-5.');
            }

            if ($schedule['frequency'] === 'weekly' && ($schedule['weekdays'] ?? []) === []) {
                $errors["job_schedules.{$i}.weekdays"] = __('Pick at least one day.');
            }
        }

        if ($errors !== []) {
            foreach ($errors as $key => $message) {
                $this->addError($key, $message);
            }

            return;
        }

        $project = $this->project ?? ($this->job_project !== '' ? Project::query()->where('uuid', $this->job_project)->first() : null);
        $job = $this->job_id ? $this->job($this->job_id) : new BackupJob(['user_id' => auth()->id()]);

        $job->fill([
            'project_id' => $project?->id,
            'name' => trim($this->job_name),
            'source_id' => $source->id,
            'source_path' => trim($this->job_source_path),
            'destination_id' => $destination->id,
            'destination_path' => trim($this->job_destination_path, " \t/"),
            'mode' => $this->job_mode,
            'options' => array_filter([
                'pre_command' => trim($this->job_pre_command),
                'excludes' => array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $this->job_excludes) ?: []))),
            ]),
            'retention' => array_map('intval', $this->job_retention),
            'timezone' => $this->job_timezone,
            'enabled' => $this->job_enabled,
        ])->save();

        $kept = [];

        foreach ($this->job_schedules as $row) {
            $schedule = filled($row['uuid'] ?? null) ? $job->schedules()->where('uuid', $row['uuid'])->first() : null;
            $schedule ??= new BackupSchedule(['backup_job_id' => $job->id]);
            $schedule->fill([
                'frequency' => $row['frequency'],
                'hour' => (int) $row['hour'],
                'minute' => (int) $row['minute'],
                'weekdays' => array_map('intval', (array) ($row['weekdays'] ?? [])),
                'monthday' => (int) $row['monthday'],
                'cron' => $row['frequency'] === 'cron' ? trim((string) $row['cron']) : null,
                'enabled' => (bool) ($row['enabled'] ?? true),
            ])->save();
            $kept[] = $schedule->id;
        }

        $job->schedules()->whereNotIn('id', $kept)->delete();
        Log::info('backups', ['user' => auth()->id(), 'action' => $this->job_id ? 'update' : 'create', 'job' => $job->uuid]);

        Flux::modal('backup-job')->close();
        Flux::toast(variant: 'success', text: $job->schedules()->exists()
            ? __(':name saved. Next run :when.', ['name' => $job->name, 'when' => $job->load('schedules')->nextRunAt()?->diffForHumans() ?? __('never: it is off')])
            : __(':name saved. It runs when you start it: add a schedule to run it on its own.', ['name' => $job->name]));
        $this->resetJobForm();
        unset($this->jobs, $this->schedules, $this->week);
    }

    public function runNow(string $uuid, Starter $backups): void
    {
        $job = $this->job($uuid);

        if ($job->isRunning()) {
            Flux::toast(variant: 'warning', text: __(':name is running already.', ['name' => $job->name]));

            return;
        }

        $backups->start($job, 'manual', auth()->user());
        Log::info('backups', ['user' => auth()->id(), 'action' => 'run', 'job' => $job->uuid]);
        Flux::toast(variant: 'success', text: __(':name started: follow it in Runs.', ['name' => $job->name]));
        unset($this->jobs, $this->runs);
    }

    public function toggleJob(string $uuid): void
    {
        $job = $this->job($uuid);
        $job->update(['enabled' => ! $job->enabled]);
        unset($this->jobs, $this->schedules, $this->week);
    }

    public function toggleSchedule(string $uuid): void
    {
        $schedule = BackupSchedule::query()->where('uuid', $uuid)->whereIn('backup_job_id', $this->jobQuery()->select('id'))->firstOrFail();
        $schedule->update(['enabled' => ! $schedule->enabled]);
        unset($this->jobs, $this->schedules, $this->week);
    }

    public function deleteJob(string $uuid): void
    {
        $job = $this->job($uuid);
        $job->delete();
        Log::info('backups', ['user' => auth()->id(), 'action' => 'delete', 'job' => $job->uuid]);
        Flux::toast(text: __(':name deleted. Its snapshots stay at the destination.', ['name' => $job->name]));
        unset($this->jobs, $this->schedules, $this->week);
    }

    public function showRuns(string $uuid): void
    {
        $this->runsJob = $this->job($uuid)->uuid;
        $this->runsStatus = '';
        $this->tab = 'runs';
    }

    private function job(string $uuid): BackupJob
    {
        return $this->jobQuery()->where('uuid', $uuid)->firstOrFail();
    }

    // ------------------------------------------------------------------ runs

    private function run(string $uuid): BackupRun
    {
        return BackupRun::query()->where('uuid', $uuid)->whereIn('backup_job_id', $this->jobQuery()->select('id'))->with(['job', 'destination'])->firstOrFail();
    }

    public function showLog(string $uuid): void
    {
        $this->logRun = $this->run($uuid)->uuid;
        Flux::modal('backup-log')->show();
    }

    public function download(string $uuid, Engine $engine): void
    {
        try {
            $url = $engine->link($this->run($uuid));
        } catch (Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Log::info('backups', ['user' => auth()->id(), 'action' => 'download', 'run' => $uuid]);
        $this->dispatch('backup-download', url: $url);
    }

    public function deleteSnapshot(string $uuid, Engine $engine): void
    {
        $run = $this->run($uuid);

        try {
            $engine->delete($run);
        } catch (Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Log::info('backups', ['user' => auth()->id(), 'action' => 'delete-snapshot', 'run' => $run->uuid]);
        Flux::toast(text: __('Snapshot deleted at the destination.'));
        unset($this->runs, $this->jobs);
    }

    public function askRestore(string $uuid): void
    {
        $run = $this->run($uuid);
        $this->restoreRun = $run->uuid;
        $this->restore_target = $run->job->source->uuid ?? '';
        $this->restore_path = $run->job->source_path !== '' ? rtrim($run->job->source_path, '/').'-restored-'.($run->started_at ?? $run->created_at)->format('Ymd-His') : 'restored';
        $this->resetValidation();
        Flux::modal('backup-restore')->show();
    }

    public function restore(Starter $backups): void
    {
        $run = $this->run((string) $this->restoreRun);

        $this->validate([
            'restore_target' => ['required', Rule::in($this->targets->pluck('uuid')->all())],
            'restore_path' => ['nullable', 'string', 'max:1024', 'not_regex:/[\x00\r\n]/'],
        ]);

        try {
            $backups->restore($run, $this->targets->firstWhere('uuid', $this->restore_target), $this->restore_path, auth()->user());
        } catch (Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Log::info('backups', ['user' => auth()->id(), 'action' => 'restore', 'run' => $run->uuid, 'to' => $this->restore_target.':'.$this->restore_path]);
        Flux::modal('backup-restore')->close();
        Flux::toast(variant: 'success', text: __('Restore started: follow it in Runs.'));
        $this->restoreRun = null;
        unset($this->runs);
    }

    // ------------------------------------------------------------------ storage

    public function newTarget(string $kind = 's3'): void
    {
        $this->resetTargetForm();
        $this->target_kind = in_array($kind, BackupTarget::KINDS, true) ? $kind : 's3';
        $this->target_project = (string) $this->project?->uuid;
        Flux::modal('backup-target')->show();
    }

    public function editTarget(string $uuid): void
    {
        $target = $this->target($uuid);
        $this->resetTargetForm();

        $this->target_id = $target->uuid;
        $this->target_kind = $target->kind;
        $this->target_name = $target->name;
        $this->target_project = (string) $target->project?->uuid;

        if ($target->isS3()) {
            $this->s3_provider = (string) $target->setting('provider', 'other');
            $this->s3_endpoint = (string) $target->setting('endpoint', '');
            $this->s3_region = (string) $target->setting('region', '');
            $this->s3_bucket = (string) $target->setting('bucket', '');
            $this->s3_access_key_id = (string) $target->setting('access_key_id', '');
            $this->s3_path_style = (bool) $target->setting('path_style', true);
        } else {
            $this->ssh_host = (string) $target->setting('host', '');
            $this->ssh_port = (int) $target->setting('port', 22);
            $this->ssh_username = (string) $target->setting('username', '');
            $this->ssh_key = (string) $target->sshKey()?->uuid;
        }

        Flux::modal('backup-target')->show();
    }

    private function resetTargetForm(): void
    {
        $this->reset(['target_id', 'target_kind', 'target_name', 'target_project', 's3_provider', 's3_endpoint', 's3_region', 's3_bucket', 's3_access_key_id', 's3_secret_access_key', 's3_path_style', 's3_from_account', 'ssh_host', 'ssh_port', 'ssh_username', 'ssh_key']);
        $this->resetValidation();
    }

    /** The keys of an AWS account of Administration → Connectors fill the bucket's keys. */
    public function updatedS3FromAccount(string $uuid): void
    {
        $account = ConnectorAccount::query()->whereNull('user_id')->where('connector', 'aws')->where('uuid', $uuid)->first();

        if ($account !== null) {
            $this->s3_provider = 'aws';
            $this->s3_endpoint = '';
            $this->s3_path_style = false;
            $this->s3_region = (string) $account->credential('region', 'eu-central-1');
            $this->s3_access_key_id = (string) $account->credential('access_key_id', '');
            // the secret stays on the server: saveTarget reads it from the account
            $this->s3_secret_access_key = '';
        }
    }

    public function updatedS3Provider(string $provider): void
    {
        $this->s3_path_style = $provider !== 'aws';
    }

    /** A saved SSH connection of a project fills host, port and user: its secret stays the owner's. */
    public function fillFromConnection(string $uuid): void
    {
        $connection = SshConnection::query()->visibleTo(auth()->user())->where('uuid', $uuid)->first();

        if ($connection !== null) {
            $this->ssh_host = $connection->host;
            $this->ssh_port = $connection->port;
            $this->ssh_username = $connection->username;
            $this->target_name = $this->target_name ?: $connection->name;
        }
    }

    public function saveTarget(Engine $engine): void
    {
        $editing = $this->target_id ? $this->target($this->target_id) : null;

        $this->validate([
            'target_name' => ['required', 'string', 'max:120'],
            'target_kind' => ['required', Rule::in(BackupTarget::KINDS)],
            'target_project' => ['nullable', Rule::exists('projects', 'uuid')],
            ...($this->target_kind === 's3' ? [
                's3_provider' => ['required', Rule::in(array_keys(BackupTarget::PROVIDERS))],
                's3_endpoint' => [$this->s3_provider === 'aws' ? 'nullable' : 'required', 'nullable', 'url:https,http', 'max:255'],
                's3_region' => ['nullable', 'string', 'max:64'],
                's3_bucket' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'],
                's3_access_key_id' => ['required', 'string', 'max:255'],
                's3_secret_access_key' => [$editing || $this->s3_from_account !== '' ? 'nullable' : 'required', 'nullable', 'string', 'max:255'],
            ] : [
                'ssh_host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.:\-\[\]]+$/'],
                'ssh_port' => ['required', 'integer', 'min:1', 'max:65535'],
                'ssh_username' => ['required', 'string', 'max:64', 'regex:/^[a-z_][a-z0-9_.-]*\$?$/i'],
                'ssh_key' => ['required', Rule::in($this->systemKeys->pluck('uuid')->all())],
            ]),
        ], [], ['ssh_key' => __('key')]);

        $old = $editing?->config ?? [];
        $fromAccount = $this->s3_from_account !== '' ? ConnectorAccount::query()->whereNull('user_id')->where('connector', 'aws')->where('uuid', $this->s3_from_account)->first() : null;

        if ($fromAccount !== null && $this->s3_secret_access_key === '') {
            $old['secret_access_key'] = (string) $fromAccount->credential('secret_access_key', '');
        }

        if ($this->target_kind === 's3') {
            $config = [
                'provider' => $this->s3_provider,
                'endpoint' => rtrim(trim($this->s3_endpoint), '/'),
                'region' => trim($this->s3_region) ?: ($this->s3_provider === 'aws' ? 'us-east-1' : ''),
                'bucket' => trim($this->s3_bucket),
                'access_key_id' => trim($this->s3_access_key_id),
                'secret_access_key' => $this->s3_secret_access_key !== '' ? $this->s3_secret_access_key : (string) ($old['secret_access_key'] ?? ''),
                'path_style' => $this->s3_path_style,
            ];
        } else {
            $key = SshKey::query()->system()->where('uuid', $this->ssh_key)->firstOrFail();
            $host = trim($this->ssh_host, ' []');
            $samePlace = ($old['host'] ?? null) === $host && (int) ($old['port'] ?? 0) === (int) $this->ssh_port;
            $config = [
                'host' => $host,
                'port' => (int) $this->ssh_port,
                'username' => trim($this->ssh_username),
                'ssh_key_id' => $key->id,
                'known_hosts' => $samePlace ? ($old['known_hosts'] ?? []) : [],
                'fingerprint' => $samePlace ? ($old['fingerprint'] ?? null) : null,
            ];

            // the host key, accepted now and checked on every connection after
            if ($config['known_hosts'] === []) {
                try {
                    $config['known_hosts'] = $engine->hostKeys($host, (int) $this->ssh_port);
                    $preferred = collect($config['known_hosts'])->first(fn ($k) => str_starts_with($k, 'ssh-ed25519')) ?? $config['known_hosts'][0];
                    $config['fingerprint'] = Engine::fingerprint($preferred);
                } catch (Throwable $e) {
                    $this->addError('ssh_host', $e->getMessage());

                    return;
                }
            }
        }

        $project = $this->project ?? ($this->target_project !== '' ? Project::query()->where('uuid', $this->target_project)->first() : null);
        $target = $editing ?? new BackupTarget(['kind' => $this->target_kind, 'user_id' => auth()->id()]);
        $target->fill(['name' => trim($this->target_name), 'project_id' => $project?->id, 'config' => $config])->save();

        $status = $engine->test($target);
        Log::info('backups', ['user' => auth()->id(), 'action' => $editing ? 'update-target' : 'create-target', 'target' => $target->uuid]);

        Flux::modal('backup-target')->close();
        Flux::toast(variant: $target->isHealthy() ? 'success' : 'warning', text: $target->isHealthy()
            ? __(':name saved and reachable.', ['name' => $target->name]).($target->isSsh() ? ' '.__('Host key :fingerprint accepted.', ['fingerprint' => $target->setting('fingerprint')]) : '')
            : __(':name saved, but: :status', ['name' => $target->name, 'status' => $status]));
        $this->resetTargetForm();
        unset($this->targets);
    }

    public function testTarget(string $uuid, Engine $engine): void
    {
        $target = $this->target($uuid);
        $status = $engine->test($target);

        Flux::toast(variant: $target->isHealthy() ? 'success' : 'danger', text: $target->name.': '.$status);
        unset($this->targets);
    }

    /** The host shows another key (it was reinstalled): accept the new one, on purpose. */
    public function rescanHostKey(string $uuid, Engine $engine): void
    {
        $target = $this->target($uuid);

        try {
            $keys = $engine->hostKeys((string) $target->setting('host'), (int) $target->setting('port', 22));
        } catch (Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $preferred = collect($keys)->first(fn ($k) => str_starts_with($k, 'ssh-ed25519')) ?? $keys[0];
        $target->update(['config' => ['known_hosts' => $keys, 'fingerprint' => Engine::fingerprint($preferred)] + $target->config]);
        Log::notice('backups', ['user' => auth()->id(), 'action' => 'accept-host-key', 'target' => $target->uuid, 'fingerprint' => Engine::fingerprint($preferred)]);
        $engine->test($target);
        Flux::toast(variant: 'success', text: __('Host key :fingerprint accepted for :name.', ['fingerprint' => Engine::fingerprint($preferred), 'name' => $target->name]));
        unset($this->targets);
    }

    public function deleteTarget(string $uuid): void
    {
        $target = $this->target($uuid);

        if ($target->inUse()) {
            Flux::toast(variant: 'danger', text: __('Backups use :name: change or delete them first.', ['name' => $target->name]));

            return;
        }

        $target->delete();
        Flux::toast(text: __(':name removed. Nothing changed on the storage itself.', ['name' => $target->name]));
        unset($this->targets);
    }

    private function target(string $uuid): BackupTarget
    {
        return $this->targets->firstWhere('uuid', $uuid) ?? abort(404);
    }

    /** @return Collection<int, SshKey> the installation's keys, shared with the project first */
    #[Computed]
    public function systemKeys(): Collection
    {
        $keys = SshKey::query()->system()->with('projects:id')->orderBy('name')->get();

        return $this->project ? $keys->sortByDesc(fn (SshKey $k) => $k->projects->contains('id', $this->project->id))->values() : $keys;
    }

    /** @return Collection<int, SshConnection> */
    #[Computed]
    public function sshConnections(): Collection
    {
        return SshConnection::query()->visibleTo(auth()->user())
            ->when($this->project, fn (Builder $q) => $q->where('project_id', $this->project->id))
            ->orderBy('name')->get();
    }

    /** @return Collection<int, ConnectorAccount> */
    #[Computed]
    public function connectorAccounts(): Collection
    {
        return ConnectorAccount::query()->whereNull('user_id')->whereIn('connector', ['aws', 'johnny'])->orderBy('name')->get();
    }

    // ------------------------------------------------------------------ Johnny

    public function newJohnnyBucket(): void
    {
        $this->johnny_account = (string) $this->connectorAccounts->firstWhere('connector', 'johnny')?->uuid;
        $this->johnny_bucket = $this->project ? $this->project->slug.'-backups' : '';
        $this->resetValidation();
        Flux::modal('backup-johnny')->show();
    }

    /** A new bucket on a Johnny panel with a key of its own, saved as storage for the backups. */
    public function provisionJohnny(Engine $engine): void
    {
        $this->validate([
            'johnny_account' => ['required', Rule::in($this->connectorAccounts->where('connector', 'johnny')->pluck('uuid')->all())],
            'johnny_bucket' => ['nullable', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{1,254}$/'],
        ], [], ['johnny_account' => __('Johnny panel')]);

        $account = $this->connectorAccounts->firstWhere('uuid', $this->johnny_account);

        try {
            $made = $account->client()->post('/buckets/provision', array_filter(['bucket' => $this->johnny_bucket ?: null]));
        } catch (ConnectorException $e) {
            $this->addError('johnny_bucket', $e->getMessage());

            return;
        }

        $bucket = (string) ($made['bucket'] ?? '');
        $target = BackupTarget::query()->create([
            'name' => 'Johnny · '.$bucket,
            'kind' => 's3',
            'project_id' => $this->project?->id,
            'user_id' => auth()->id(),
            'config' => [
                'provider' => 'johnny',
                'endpoint' => rtrim((string) ($made['endpoint'] ?? ''), '/'),
                'region' => (string) ($made['region'] ?? 'garage'),
                'bucket' => $bucket,
                'access_key_id' => (string) data_get($made, 'credentials.access_key_id'),
                'secret_access_key' => (string) data_get($made, 'credentials.secret_access_key'),
                'path_style' => (bool) ($made['path_style'] ?? true),
                'connector_account' => $account->uuid,
            ],
        ]);
        $engine->test($target);
        Log::info('backups', ['user' => auth()->id(), 'action' => 'johnny-provision', 'target' => $target->uuid, 'account' => $account->uuid]);

        Flux::modal('backup-johnny')->close();
        Flux::toast(variant: 'success', text: __('Bucket :bucket created on :panel with a key of its own, ready for the backups.', ['bucket' => $bucket, 'panel' => $account->name]));
        unset($this->targets);
    }

    public function render(): View
    {
        $log = $this->logRun ? BackupRun::query()->where('uuid', $this->logRun)->whereIn('backup_job_id', $this->jobQuery()->select('id'))->with('job')->first() : null;
        $restoring = $this->restoreRun ? BackupRun::query()->where('uuid', $this->restoreRun)->with('job')->first() : null;

        return view($this->project ? 'livewire.projects.backups' : 'livewire.tools.backups', [
            'title' => $this->project ? __(':project · Backups', ['project' => $this->project->name]) : __('Backups'),
            'log' => $log,
            'restoring' => $restoring,
            'projects' => $this->project ? collect() : Project::query()->orderBy('name')->get(['id', 'uuid', 'name']),
            'johnnyReady' => Connectors::find('johnny') !== null && $this->connectorAccounts->contains('connector', 'johnny'),
            'active' => $this->hasActiveRuns(),
            'timezones' => timezone_identifiers_list(),
        ])->layout($this->project ? 'layouts::studio' : 'layouts::app', ['title' => $this->project ? __(':project · Backups', ['project' => $this->project->name]) : __('Backups')]);
    }
}
