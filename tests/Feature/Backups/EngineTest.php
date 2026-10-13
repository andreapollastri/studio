<?php

use App\Backups\Backups;
use App\Backups\Engine;
use App\Backups\Retention;
use App\Jobs\RunBackup;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\BackupSchedule;
use App\Models\BackupTarget;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

function sshTarget(array $config = []): BackupTarget
{
    return BackupTarget::query()->create([
        'name' => 'web',
        'kind' => 'ssh',
        'config' => ['host' => 'web.example.com', 'port' => 22, 'username' => 'shop', 'ssh_key_id' => SshKey::factory()->system()->create()->id, 'known_hosts' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl'], ...$config],
    ]);
}

function s3Target(array $config = []): BackupTarget
{
    return BackupTarget::query()->create([
        'name' => 'vault',
        'kind' => 's3',
        'config' => ['provider' => 'johnny', 'endpoint' => 'https://s3.johnny.test', 'region' => 'garage', 'bucket' => 'backups', 'access_key_id' => 'GK1', 'secret_access_key' => 'shh', 'path_style' => true, ...$config],
    ]);
}

function backupJob(array $attributes = []): BackupJob
{
    return BackupJob::query()->create([
        'name' => 'Shop files',
        'source_id' => ($attributes['source_id'] ?? null) ?: sshTarget()->id,
        'source_path' => 'current',
        'destination_id' => ($attributes['destination_id'] ?? null) ?: s3Target()->id,
        'destination_path' => 'shop',
        'mode' => 'archive',
        'timezone' => 'Europe/Rome',
        ...$attributes,
    ]);
}

test('the retention keeps the newest, and the newest of each day, week and month that has one', function () {
    $snapshots = [];
    $start = Carbon::parse('2026-10-31 02:00', 'UTC');

    // two a day for 90 days
    for ($i = 0; $i < 180; $i++) {
        $snapshots[$i] = $start->copy()->subHours(12 * $i);
    }

    $keep = Retention::keep($snapshots, ['last' => 2, 'daily' => 7, 'weekly' => 4, 'monthly' => 3, 'yearly' => 0]);

    // the two newest, the newest of the 7 last days (the newest is one of them), weeks and months
    expect($keep[0])->toBe(['last', 'daily', 'weekly', 'monthly'])
        ->and($keep[1])->toBe(['last', 'daily'])
        ->and(collect($keep)->filter(fn ($rules) => in_array('daily', $rules))->count())->toBe(7)
        ->and(collect($keep)->filter(fn ($rules) => in_array('weekly', $rules))->count())->toBe(4)
        ->and(collect($keep)->filter(fn ($rules) => in_array('monthly', $rules))->count())->toBe(3)
        ->and(count($keep))->toBeLessThan(16);

    // a missing day does not use up a slot: 3 daily of snapshots a week apart are 3 weeks
    $weekly = [Carbon::parse('2026-10-01'), Carbon::parse('2026-10-08'), Carbon::parse('2026-10-15'), Carbon::parse('2026-10-22')];
    expect(array_keys(Retention::keep($weekly, ['last' => 1, 'daily' => 3, 'weekly' => 0, 'monthly' => 0, 'yearly' => 0])))->toEqualCanonicalizing([3, 2, 1]);
});

test('schedules say when they run in plain words and in cron, in the job\'s timezone', function () {
    $job = backupJob();
    $daily = $job->schedules()->create(['frequency' => 'daily', 'hour' => 3, 'minute' => 30]);
    $weekly = $job->schedules()->create(['frequency' => 'weekly', 'hour' => 22, 'minute' => 0, 'weekdays' => [4, 1]]);
    $hourly = $job->schedules()->create(['frequency' => 'hourly', 'minute' => 15]);
    $monthly = $job->schedules()->create(['frequency' => 'monthly', 'hour' => 1, 'minute' => 5, 'monthday' => 31]);
    $cron = $job->schedules()->create(['frequency' => 'cron', 'cron' => '*/10 9-18 * * 1-5']);

    expect($daily->expression())->toBe('30 3 * * *')->and($daily->label())->toBe('Every day at 03:30')
        ->and($weekly->expression())->toBe('0 22 * * 1,4')->and($weekly->label())->toBe('Mon, Thu at 22:00')
        ->and($hourly->label())->toBe('Every hour at :15')
        ->and($monthly->expression())->toBe('5 1 28 * *')
        ->and($cron->label())->toBe('Cron */10 9-18 * * 1-5');

    // 03:30 in Rome is 02:30 UTC in winter
    expect($daily->isDue(Carbon::parse('2026-12-01 02:30', 'UTC')))->toBeTrue()
        ->and($daily->isDue(Carbon::parse('2026-12-01 03:30', 'UTC')))->toBeFalse()
        ->and($daily->nextRunAt(Carbon::parse('2026-12-01 00:00', 'UTC'))->utc()->format('H:i'))->toBe('02:30')
        ->and(count($hourly->runsBetween(Carbon::parse('2026-12-01 00:00', 'UTC'), Carbon::parse('2026-12-01 23:59', 'UTC'))))->toBe(24);
});

test('the schedules due start one run each, once a minute, only for jobs that are on', function () {
    Queue::fake();
    $job = backupJob();
    $job->schedules()->create(['frequency' => 'daily', 'hour' => 3, 'minute' => 0]);
    $job->schedules()->create(['frequency' => 'hourly', 'minute' => 0]);
    $job->schedules()->create(['frequency' => 'hourly', 'minute' => 0, 'enabled' => false]);
    $off = backupJob(['name' => 'Off', 'enabled' => false]);
    $off->schedules()->create(['frequency' => 'hourly', 'minute' => 0]);

    $at = Carbon::parse('2026-12-01 02:00:20', 'UTC'); // 03:00 in Rome
    $started = app(Backups::class)->dispatchDue($at);

    expect($started)->toHaveCount(2)
        ->and(BackupRun::query()->where('trigger', 'schedule')->where('status', 'queued')->count())->toBe(2);
    Queue::assertPushed(RunBackup::class, 2);

    // the same minute again: nothing more
    expect(app(Backups::class)->dispatchDue($at->copy()->addSeconds(30)))->toBe([]);
});

test('an archive is made with tar on the source host and streamed into the bucket, then measured', function () {
    Process::fake([
        '*rclone*size*' => Process::result('{"count":1,"bytes":52428800}'),
        '*' => Process::result('ok'),
    ]);

    $job = backupJob(['options' => ['pre_command' => 'mysqldump shop > ~/db.sql', 'excludes' => ['storage/logs']]]);
    $run = app(Backups::class)->start($job);

    expect($run->fresh()->status)->toBe('success')
        ->and($run->fresh()->path)->toStartWith('shop/shop-files-')->toEndWith('.tar.gz')
        ->and($run->fresh()->bytes)->toBe(52428800)
        ->and($run->fresh()->kept_by)->toContain('last')
        ->and($job->fresh()->last_status)->toBe('success');

    Process::assertRan(fn (PendingProcess $p) => is_array($p->command) && in_array('mysqldump shop > ~/db.sql', $p->command, true) && in_array('StrictHostKeyChecking=yes', $p->command, true));
    Process::assertRan(function (PendingProcess $p) {
        $line = is_array($p->command) ? implode(' ', $p->command) : $p->command;

        return str_contains($line, 'set -o pipefail')
            && str_contains($line, "--exclude='\\''storage/logs'\\''")
            && str_contains($line, 'rcat')
            && ($p->environment['RCLONE_CONFIG_DST_TYPE'] ?? null) === 's3'
            && ($p->environment['RCLONE_CONFIG_DST_SECRET_ACCESS_KEY'] ?? null) === 'shh'
            && ! str_contains($line, 'shh');
    });
});

test('a snapshot copies the files into a folder of its own; the retention deletes the old ones', function () {
    Process::fake(['*' => Process::result('{"count":10,"bytes":1000}')]);

    $job = backupJob(['mode' => 'snapshot', 'source_id' => s3Target(['bucket' => 'media'])->id, 'source_path' => 'uploads', 'retention' => ['last' => 1, 'daily' => 0, 'weekly' => 0, 'monthly' => 0, 'yearly' => 0]]);
    $old = $job->runs()->create(['trigger' => 'schedule', 'status' => 'success', 'path' => 'shop/2026-01-01_030000', 'destination_id' => $job->destination_id, 'started_at' => now()->subDays(30)]);

    $run = app(Backups::class)->start($job);

    expect($run->fresh()->status)->toBe('success')
        ->and($old->fresh()->pruned_at)->not->toBeNull()
        ->and($run->fresh()->pruned_at)->toBeNull();

    Process::assertRan(fn (PendingProcess $p) => $p->command[1] === 'copy' && $p->command[2] === 'src:media/uploads' && str_starts_with($p->command[3], 'dst:backups/shop/'));
    Process::assertRan(fn (PendingProcess $p) => $p->command[1] === 'purge' && $p->command[2] === 'dst:backups/shop/2026-01-01_030000');
});

test('a failed copy says why, and a second run of the same job waits its turn', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: "2026/10/13 ERROR : Failed to copy: AccessDenied\n", exitCode: 1)]);

    $job = backupJob(['mode' => 'mirror']);
    $run = app(Backups::class)->start($job);

    expect($run->fresh()->status)->toBe('failed')
        ->and($run->fresh()->error)->toContain('AccessDenied')
        ->and($job->fresh()->last_status)->toBe('failed');

    $job->runs()->create(['trigger' => 'manual', 'status' => 'running', 'started_at' => now()]);
    $second = app(Backups::class)->start($job);
    expect($second->fresh()->status)->toBe('skipped');
});

test('restoring an archive unpacks it on an SSH host', function () {
    Process::fake(['*' => Process::result('ok')]);

    $job = backupJob();
    $snapshot = $job->runs()->create(['trigger' => 'manual', 'status' => 'success', 'path' => 'shop/shop-files-2026.tar.gz', 'destination_id' => $job->destination_id, 'started_at' => now()]);
    $restore = app(Backups::class)->restore($snapshot, $job->source, 'restored', $job->source->project?->deployUser ?? User::factory()->create());

    expect($restore->fresh()->status)->toBe('success');
    Process::assertRan(function (PendingProcess $p) {
        $line = implode(' ', (array) $p->command);

        return str_contains($line, 'cat') && str_contains($line, 'tar -xzf - -C');
    });
});

test('host keys come from ssh-keyscan and show as ssh fingerprints', function () {
    Process::fake(['*ssh-keyscan*' => Process::result("# web.example.com:22 SSH-2.0-OpenSSH_9.6\nweb.example.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl\n")]);

    $keys = app(Engine::class)->hostKeys('web.example.com');

    expect($keys)->toBe(['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl'])
        ->and(Engine::fingerprint($keys[0]))->toStartWith('SHA256:');
});

test('abandoned runs are marked failed', function () {
    $job = backupJob();
    $silent = $job->runs()->create(['trigger' => 'manual', 'status' => 'running', 'started_at' => now()->subHour()]);
    BackupRun::query()->whereKey($silent->id)->update(['updated_at' => now()->subMinutes(30)]);
    $busy = $job->runs()->create(['trigger' => 'manual', 'status' => 'running', 'started_at' => now()->subHour()]);

    expect(app(Backups::class)->failStale())->toBe(1)
        ->and($silent->fresh()->status)->toBe('failed')
        ->and($busy->fresh()->status)->toBe('running');
});

test('a schedule with a bad cron never fires', function () {
    $schedule = backupJob()->schedules()->create(['frequency' => 'cron', 'cron' => 'nope']);

    expect(BackupSchedule::validExpression('nope'))->toBeFalse()
        ->and($schedule->isDue(now()))->toBeFalse()
        ->and($schedule->nextRunAt())->toBeNull();
});
