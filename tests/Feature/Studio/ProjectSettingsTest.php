<?php

use App\Enums\SiteStatus;
use App\Jobs\ConfigureProjectSite;
use App\Livewire\Admin\Projects;
use App\Livewire\Projects\Settings as ProjectSettings;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Server\ProjectSites;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('a project starts with the scheduler and the default queue on, Reverb off, and no address list (the login is always asked)', function () {
    $project = Project::factory()->create();
    $workspace = Workspace::factory()->create(['project_id' => $project->id]);

    expect($project->features())->toBe(['scheduler' => true, 'queues' => ['default'], 'reverb' => false, 'horizon' => false, 'pulse' => false])
        ->and($project->access()['site'])->toBe(['ips' => []])
        ->and($project->access()['previews'])->toBe(['ips' => []])
        ->and($project->access()['branches'])->toBe([])
        ->and($project->access()['public'])->toBe(['site' => false, 'previews' => false])
        ->and($project->timezone())->toBe('UTC')
        ->and($project->serviceInput())->toBe(['scheduler' => '1', 'queues' => 'default', 'reverb' => '0', 'reverb_port' => 9000 + $project->id, 'horizon' => '0', 'pulse' => '0', 'timezone' => 'UTC', 'public' => '0'])
        // every host of a project runs its own Reverb: the preview never shares the site's port
        ->and($workspace->serviceInput()['reverb_port'])->toBe(20000 + $workspace->id)
        ->and($workspace->serviceInput()['queues'])->toBe('default');
});

test('an administrator saves features and access rules, and the server is asked to apply them', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->online()->create(['slug' => 'shop']);

    Livewire::actingAs($admin)
        ->test(ProjectSettings::class, ['project' => $project])
        ->assertSet('scheduler', true)
        ->assertSet('queues', 'default')
        ->set('scheduler', false)
        ->set('queues', 'default, mail, exports')
        ->set('reverb', true)
        ->set('pulse', true)
        ->set('timezone', 'Europe/Rome')
        ->set('site_ips', "203.0.113.0/24\n198.51.100.7")
        ->call('addBranchRule')
        ->set('branch_rules.0.branch', 'feature/secret')
        ->set('branch_rules.0.ips', '10.0.0.0/8')
        ->call('saveSettings')
        ->assertHasNoErrors();

    $project->refresh();

    expect($project->features())->toBe(['scheduler' => false, 'queues' => ['default', 'mail', 'exports'], 'reverb' => true, 'horizon' => false, 'pulse' => true])
        ->and($project->access()['site'])->toBe(['ips' => ['203.0.113.0/24', '198.51.100.7']])
        ->and($project->access()['branches'])->toBe([['branch' => 'feature/secret', 'ips' => ['10.0.0.0/8']]])
        ->and($project->serviceInput()['queues'])->toBe('default,mail,exports')
        ->and($project->timezone())->toBe('Europe/Rome')
        ->and($project->serviceInput()['timezone'])->toBe('Europe/Rome');

    Queue::assertPushed(ConfigureProjectSite::class, fn (ConfigureProjectSite $job) => $job->project->is($project));
});

test('the settings open on the stored timezone, and a name outside the list is refused', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->online()->create(['settings' => ['timezone' => 'America/New_York']]);
    $workspace = Workspace::factory()->create(['project_id' => $project->id]);

    expect($workspace->serviceInput()['timezone'])->toBe('America/New_York');

    Livewire::actingAs($admin)
        ->test(ProjectSettings::class, ['project' => $project])
        ->assertSet('timezone', 'America/New_York')
        ->set('timezone', 'Mars/Olympus_Mons')
        ->call('saveSettings')
        ->assertHasErrors('timezone');

    expect($project->fresh()->timezone())->toBe('America/New_York');
    Queue::assertNothingPushed();
});

test('a timezone stored by hand that PHP does not know falls back to UTC', function () {
    expect(Project::factory()->make(['settings' => ['timezone' => 'Nowhere/Land']])->timezone())->toBe('UTC');
});

test('a bad whitelist entry or queue name is refused before anything is saved', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->online()->create();

    Livewire::actingAs($admin)
        ->test(ProjectSettings::class, ['project' => $project])
        ->set('site_ips', 'office')
        ->call('saveSettings')
        ->assertHasErrors('site_ips')
        ->set('site_ips', '')
        ->set('queues', 'Default Queue')
        ->call('saveSettings')
        ->assertHasErrors('queues');

    expect($project->fresh()->settings)->toBeNull();
    Queue::assertNothingPushed();
});

test('a developer cannot open the project settings', function () {
    $dev = User::factory()->create();
    $project = Project::factory()->create();

    Livewire::actingAs($dev)->test(Projects::class)->assertForbidden();
    Livewire::actingAs($dev)->test(ProjectSettings::class, ['project' => $project])->assertForbidden();
});

test('applying the settings runs project-configure for the site and workspace-configure for each workspace', function () {
    config([
        'studio.driver' => 'native',
        'studio.domain' => 'dev.example.test',
        'studio.native.helper' => base_path('tests/fixtures/fake-studio-admin'),
        'studio.native.sudo' => false,
    ]);
    $log = tempnam(sys_get_temp_dir(), 'studio-admin-');
    $_ENV['FAKE_STUDIO_ADMIN_LOG'] = $log;
    $_SERVER['FAKE_STUDIO_ADMIN_LOG'] = $log;

    $admin = User::factory()->admin()->withGit('github_pat_admin', attributes: ['login' => 'andrea'])->create(['handle' => 'andrea']);
    $maria = User::factory()->create(['handle' => 'maria']);
    $project = Project::factory()->create([
        'slug' => 'shop', 'php_version' => '8.4', 'site_status' => SiteStatus::Ready, 'deploy_user_id' => $admin->id,
        'settings' => ['features' => ['scheduler' => true, 'queues' => ['default', 'mail'], 'reverb' => true, 'horizon' => true], 'access' => ['previews' => ['ips' => []]]],
    ]);
    Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $maria->id, 'driver' => 'native', 'path' => '/srv/studio/workspaces/maria/shop']);

    app(ProjectSites::class)->configure($project);

    $calls = file_get_contents($log);
    unset($_ENV['FAKE_STUDIO_ADMIN_LOG'], $_SERVER['FAKE_STUDIO_ADMIN_LOG']);

    expect($calls)->toContain('project-configure shop ')
        ->toContain('"site_host":"shop.dev.example.test"')
        ->toContain('"queues":"default,mail"')
        ->toContain('"reverb":"1"')
        ->toContain('"horizon":"1"')
        ->toContain('"reverb_port":'.(9000 + $project->id))
        ->toContain('workspace-configure maria shop ')
        ->toContain('"preview_host":"maria-shop.dev.example.test"')
        ->not->toContain('site_auth')
        ->not->toContain('preview_auth');
});

test('an administrator makes the site public, then the previews too; a private site takes the previews back', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->online()->create(['slug' => 'shop']);

    $settings = Livewire::actingAs($admin)
        ->test(ProjectSettings::class, ['project' => $project, 'tab' => 'access'])
        ->assertSet('site_public', false)
        ->assertSet('previews_public', false)
        ->assertSee('Public access')
        ->set('site_public', true)
        ->set('previews_public', true)
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect($project->fresh()->access()['public'])->toBe(['site' => true, 'previews' => true]);

    $settings->set('site_public', false)
        ->assertSet('previews_public', false)
        ->set('previews_public', true)
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect($project->fresh()->access()['public'])->toBe(['site' => false, 'previews' => false]);
    Queue::assertPushed(ConfigureProjectSite::class);
});
