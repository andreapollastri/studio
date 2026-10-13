<?php

use App\Jobs\RunBackup;
use App\Livewire\Tools\Backups;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\BackupTarget;
use App\Models\ConnectorAccount;
use App\Models\Project;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';

beforeEach(function () {
    Process::fake([
        '*ssh-keyscan*' => Process::result('web.example.com '.HOST_KEY."\n"),
        '*lsf*' => Process::result("a/\nb/\n"),
        '*' => Process::result("studio-ok\nLinux 6.8\n/dev/sda1 100G 40G 60G 40% /\n"),
    ]);
});

test('only administrators open the backups, in Tools and in a project', function () {
    $project = Project::factory()->create();
    $dev = User::factory()->create();
    $project->syncMembers([$dev->id => 'dev']);

    $this->actingAs($dev)->get(route('backups'))->assertForbidden();
    $this->actingAs($dev)->get(route('projects.backups', $project))->assertForbidden();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get(route('backups'))->assertOk()->assertSee('Two steps to the first backup');
    $this->actingAs($admin)->get(route('projects.backups', $project))->assertOk()->assertSee($project->name);
});

test('an administrator adds an SSH host and a bucket, a backup with its schedules, and runs it', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create(['name' => 'Shop']);
    $key = SshKey::factory()->system()->create(['name' => 'backups']);
    $project->sshKeys()->attach($key, ['developers' => false]);

    $page = Livewire::actingAs($admin)->test(Backups::class, ['project' => $project])
        ->call('newTarget', 'ssh')
        ->set('target_name', 'Shop server')
        ->set('ssh_host', 'web.example.com')
        ->set('ssh_username', 'shop')
        ->set('ssh_key', $key->uuid)
        ->call('saveTarget')
        ->assertHasNoErrors()
        ->call('newTarget', 's3')
        ->set('target_name', 'Vault')
        ->set('s3_provider', 'other')
        ->set('s3_endpoint', 'https://s3.example.com')
        ->set('s3_bucket', 'backups')
        ->set('s3_access_key_id', 'GK1')
        ->set('s3_secret_access_key', 'Sup3rS3cretValue')
        ->call('saveTarget')
        ->assertHasNoErrors();

    $ssh = BackupTarget::query()->where('kind', 'ssh')->sole();
    expect($ssh->project_id)->toBe($project->id)
        ->and($ssh->setting('known_hosts'))->toBe([HOST_KEY])
        ->and($ssh->setting('fingerprint'))->toStartWith('SHA256:')
        ->and($ssh->status)->toStartWith('ok · Linux 6.8')
        ->and(BackupTarget::query()->where('kind', 's3')->sole()->status)->toBe('ok · 2 items at the top');

    $page->call('newJob')
        ->assertSet('job_source', $ssh->uuid)
        ->set('job_source_path', 'current')
        ->call('preset', 'gfs')
        ->call('addSchedule')
        ->set('job_schedules.3.frequency', 'cron')
        ->set('job_schedules.3.cron', 'nope')
        ->call('saveJob')
        ->assertHasErrors('job_schedules.3.cron')
        ->set('job_schedules.3.cron', '*/30 9-18 * * 1-5')
        ->call('saveJob')
        ->assertHasNoErrors();

    $job = BackupJob::query()->sole();
    expect($job->project_id)->toBe($project->id)
        ->and($job->name)->toBe('Shop · files')
        ->and($job->destination_path)->toBe($project->slug)
        ->and($job->schedules()->count())->toBe(4)
        ->and($job->retentionPolicy())->toBe(['last' => 3, 'daily' => 7, 'weekly' => 5, 'monthly' => 12, 'yearly' => 2]);

    $page->call('runNow', $job->uuid);
    Queue::assertPushed(RunBackup::class);
    expect(BackupRun::query()->sole()->trigger)->toBe('manual');

    $page->set('tab', 'schedule')->assertSee('*/30 9-18 * * 1-5')->assertSee('The coming seven days')
        ->set('tab', 'runs')->assertSee('queued')
        ->set('tab', 'storage')->assertSee('Shop server')->assertDontSee('Sup3rS3cretValue');
});

test('an archive needs an SSH source, and a bucket can be the source of a snapshot', function () {
    $admin = User::factory()->admin()->create();
    $a = BackupTarget::query()->create(['name' => 'A', 'kind' => 's3', 'config' => ['bucket' => 'a', 'access_key_id' => 'x', 'secret_access_key' => 'y']]);
    $b = BackupTarget::query()->create(['name' => 'B', 'kind' => 's3', 'config' => ['bucket' => 'b', 'access_key_id' => 'x', 'secret_access_key' => 'y']]);

    Livewire::actingAs($admin)->test(Backups::class)
        ->call('newJob')
        ->set('job_name', 'Bucket copy')
        ->set('job_source', $a->uuid)
        ->set('job_destination', $b->uuid)
        ->set('job_mode', 'archive')
        ->call('saveJob')
        ->assertHasErrors('job_mode')
        ->set('job_mode', 'snapshot')
        ->call('saveJob')
        ->assertHasNoErrors();

    expect(BackupJob::query()->sole()->mode)->toBe('snapshot');
});

test('a Johnny panel makes a bucket with its own key for the backups', function () {
    $admin = User::factory()->admin()->create();
    ConnectorAccount::factory()->create(['connector' => 'johnny', 'name' => 'Johnny EU', 'credentials' => ['url' => 'https://panel.johnny.test', 'token' => 't']]);
    Http::fake(['panel.johnny.test/api/buckets/provision' => Http::response([
        'bucket' => 'shop-backups', 'endpoint' => 'https://s3.johnny.test', 'region' => 'garage', 'path_style' => true,
        'credentials' => ['access_key_id' => 'GKabc', 'secret_access_key' => 'sss'],
    ])]);

    Livewire::actingAs($admin)->test(Backups::class)
        ->call('newJohnnyBucket')
        ->set('johnny_bucket', 'shop-backups')
        ->call('provisionJohnny')
        ->assertHasNoErrors();

    $target = BackupTarget::query()->sole();
    expect($target->name)->toBe('Johnny · shop-backups')
        ->and($target->setting('endpoint'))->toBe('https://s3.johnny.test')
        ->and($target->setting('secret_access_key'))->toBe('sss');
});

test('storage in use cannot be removed', function () {
    $admin = User::factory()->admin()->create();
    $job = BackupJob::query()->create([
        'name' => 'x',
        'source_id' => BackupTarget::query()->create(['name' => 'S', 'kind' => 's3', 'config' => ['bucket' => 's']])->id,
        'destination_id' => BackupTarget::query()->create(['name' => 'D', 'kind' => 's3', 'config' => ['bucket' => 'd']])->id,
        'mode' => 'mirror',
    ]);

    Livewire::actingAs($admin)->test(Backups::class)->call('deleteTarget', $job->source->uuid);
    expect(BackupTarget::query()->count())->toBe(2);
});
