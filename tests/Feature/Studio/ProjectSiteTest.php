<?php

use App\Enums\SiteStatus;
use App\Jobs\DeployProjectSite;
use App\Livewire\Admin\Projects;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Server\ProjectSites;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'studio.driver' => 'native',
        'studio.domain' => 'dev.example.test',
        'studio.native.helper' => base_path('tests/fixtures/fake-studio-admin'),
        'studio.native.sudo' => false,
    ]);
});

test('provisioning a project site creates it on the server and registers the push webhook', function () {
    $admin = User::factory()->admin()->create(['handle' => 'andrea', 'git_token' => 'github_pat_admin']);
    $project = Project::factory()->create(['slug' => 'shop', 'repo_url' => 'https://github.com/agency/shop.git', 'deploy_user_id' => $admin->id]);

    Http::fake([
        'https://api.github.com/user' => Http::response(['login' => 'andrea'], 200),
        'https://api.github.com/repos/agency/shop/hooks' => Http::response(['id' => 987654], 201),
    ]);

    app(ProjectSites::class)->provision($project);
    $project->refresh();

    expect($project->site_status)->toBe(SiteStatus::Ready)
        ->and($project->larapilot_api_token)->toBe('lp-token-shop')
        ->and($project->deployed_sha)->toBe('abc1234')
        ->and($project->webhook_id)->toBe('987654')
        ->and($project->webhook_secret)->not->toBeNull()
        ->and($project->siteUrl())->toBe('https://shop.dev.example.test')
        ->and($project->dashboardUrl())->toBe('https://shop.dev.example.test/larapilot');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/repos/agency/shop/hooks'
        && $request->hasHeader('Authorization', 'Bearer github_pat_admin')
        && $request['events'] === ['push']
        && str_ends_with($request['config']['url'], '/api/git/webhook/shop'));
});

test('a refused GitHub token fails the site with a message that says to paste a new one', function () {
    $admin = User::factory()->admin()->create(['handle' => 'andrea', 'git_token' => 'github_pat_dead']);
    $project = Project::factory()->create(['slug' => 'shop', 'repo_url' => 'https://github.com/agency/shop.git', 'deploy_user_id' => $admin->id]);
    Http::fake(fn (Request $request) => str_contains($request->url(), '/user')
        ? Http::response(['message' => 'Bad credentials'], 401)
        : Http::response(['id' => 1], 201));

    app(ProjectSites::class)->provision($project);

    expect($project->fresh()->site_status)->toBe(SiteStatus::Failed)
        ->and($project->fresh()->last_error)->toContain('does not recognise this token');
});

test('a project without a deploying administrator fails to provision with a readable reason', function () {
    $project = Project::factory()->create(['deploy_user_id' => null]);

    app(ProjectSites::class)->provision($project);

    expect($project->fresh()->site_status)->toBe(SiteStatus::Failed)
        ->and($project->fresh()->last_error)->toContain('GitHub token');
});

test('a signed push to the deploy branch queues a deploy, anything else is ignored or refused', function () {
    Queue::fake();
    $project = Project::factory()->create(['slug' => 'shop', 'default_branch' => 'develop', 'webhook_secret' => 'hook-secret', 'site_status' => SiteStatus::Ready]);

    $send = function (array $payload, string $event = 'push', ?string $secret = 'hook-secret') use ($project) {
        $body = json_encode($payload);
        $headers = ['X-GitHub-Event' => $event, 'Content-Type' => 'application/json'];
        if ($secret !== null) {
            $headers['X-Hub-Signature-256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', route('api.git.webhook', $project), [], [], [], $this->transformHeadersToServerVars($headers), $body);
    };

    $send(['ref' => 'refs/heads/develop'])->assertStatus(202);
    Queue::assertPushed(DeployProjectSite::class, fn (DeployProjectSite $job) => $job->project->is($project));

    $send(['ref' => 'refs/heads/feature/x'])->assertOk()->assertJson(['ignored' => true]);
    $send(['zen' => 'hi'], 'ping')->assertOk()->assertJson(['pong' => true]);
    $send(['ref' => 'refs/heads/develop'], 'push', 'wrong')->assertForbidden();
    $send(['ref' => 'refs/heads/develop'], 'push', null)->assertForbidden();

    Queue::assertPushed(DeployProjectSite::class, 1);
});

test('deploying updates the sha from the helper', function () {
    $admin = User::factory()->admin()->create(['handle' => 'andrea', 'git_token' => 'github_pat_admin']);
    $project = Project::factory()->online()->create(['deploy_user_id' => $admin->id, 'webhook_id' => '1']);

    Http::fake(['https://api.github.com/user' => Http::response(['login' => 'andrea'], 200)]);

    app(ProjectSites::class)->deploy($project);

    expect($project->fresh()->site_status)->toBe(SiteStatus::Ready)
        ->and($project->fresh()->deployed_sha)->toBe('def5678')
        ->and($project->fresh()->deployed_at)->not->toBeNull();
});

test('deploying a project whose site never got created provisions it instead of failing on an empty server', function () {
    $admin = User::factory()->admin()->create(['handle' => 'andrea', 'git_token' => 'github_pat_admin']);
    $project = Project::factory()->create(['slug' => 'shop', 'repo_url' => 'https://github.com/agency/shop.git', 'deploy_user_id' => $admin->id, 'site_status' => SiteStatus::Failed, 'last_error' => 'project-create failed: composer install failed', 'deployed_at' => null]);
    $log = tempnam(sys_get_temp_dir(), 'studio-admin-');
    $_ENV['FAKE_STUDIO_ADMIN_LOG'] = $_SERVER['FAKE_STUDIO_ADMIN_LOG'] = $log;
    Http::fake([
        'https://api.github.com/user' => Http::response(['login' => 'andrea'], 200),
        'https://api.github.com/repos/agency/shop/hooks' => Http::response(['id' => 1], 201),
    ]);

    app(ProjectSites::class)->deploy($project);
    $project->refresh();

    $calls = array_map(fn ($line) => explode(' ', $line)[0], array_filter(explode("\n", file_get_contents($log))));
    unset($_ENV['FAKE_STUDIO_ADMIN_LOG'], $_SERVER['FAKE_STUDIO_ADMIN_LOG']);
    @unlink($log);

    expect($calls)->toBe(['project-create'])
        ->and($project->site_status)->toBe(SiteStatus::Ready)
        ->and($project->last_error)->toBeNull()
        ->and($project->deployed_at)->not->toBeNull();
});

test('deleting a project takes every workspace and the site off the server, drops the webhook, and only then the row', function () {
    $admin = User::factory()->admin()->create(['handle' => 'andrea', 'git_token' => 'github_pat_admin']);
    $maria = User::factory()->create(['handle' => 'maria']);
    $project = Project::factory()->online()->create(['slug' => 'shop', 'repo_url' => 'https://github.com/agency/shop.git', 'deploy_user_id' => $admin->id, 'webhook_id' => '987654']);
    Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $maria->id, 'driver' => 'native', 'path' => '/srv/studio/workspaces/maria/shop']);
    Workspace::factory()->create(['project_id' => $project->id, 'user_id' => $admin->id, 'driver' => 'native', 'path' => null]);
    $log = tempnam(sys_get_temp_dir(), 'studio-admin-');
    $_ENV['FAKE_STUDIO_ADMIN_LOG'] = $_SERVER['FAKE_STUDIO_ADMIN_LOG'] = $log;
    Http::fake(['https://api.github.com/repos/agency/shop/hooks/987654' => Http::response('', 204)]);

    // the page marks the row and queues the job; the job (run here, the queue is sync) does the rest
    Livewire::actingAs($admin)->test(Projects::class)->call('delete', $project->id);

    $calls = array_map(fn ($line) => implode(' ', array_slice(explode(' ', $line), 0, 3)), array_filter(explode("\n", file_get_contents($log))));
    unset($_ENV['FAKE_STUDIO_ADMIN_LOG'], $_SERVER['FAKE_STUDIO_ADMIN_LOG']);
    @unlink($log);

    // the workspace that never reached the server is not asked to the helper
    expect($calls)->toBe(['workspace-delete maria shop', 'project-delete shop {}'])
        ->and(Project::query()->find($project->id))->toBeNull()
        ->and(Workspace::query()->where('project_id', $project->id)->count())->toBe(0);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === 'https://api.github.com/repos/agency/shop/hooks/987654');
});

test('deleting a person removes their workspaces from the server, with their tokens and bridges', function () {
    $maria = User::factory()->create(['handle' => 'maria']);
    $shop = Project::factory()->online()->create(['slug' => 'shop']);
    $crm = Project::factory()->online()->create(['slug' => 'crm']);
    Workspace::factory()->running()->create(['project_id' => $shop->id, 'user_id' => $maria->id, 'driver' => 'native', 'path' => '/srv/studio/workspaces/maria/shop']);
    Workspace::factory()->running()->create(['project_id' => $crm->id, 'user_id' => $maria->id, 'driver' => 'native', 'path' => '/srv/studio/workspaces/maria/crm', 'callback_token' => 'another-token', 'callback_token_hash' => hash('sha256', 'another-token')]);
    $log = tempnam(sys_get_temp_dir(), 'studio-admin-');
    $_ENV['FAKE_STUDIO_ADMIN_LOG'] = $_SERVER['FAKE_STUDIO_ADMIN_LOG'] = $log;

    $maria->delete();

    $calls = array_map(fn ($line) => implode(' ', array_slice(explode(' ', $line), 0, 3)), array_filter(explode("\n", file_get_contents($log))));
    unset($_ENV['FAKE_STUDIO_ADMIN_LOG'], $_SERVER['FAKE_STUDIO_ADMIN_LOG']);
    @unlink($log);

    sort($calls);
    expect($calls)->toBe(['workspace-delete maria crm', 'workspace-delete maria shop'])
        ->and(Workspace::query()->where('user_id', $maria->id)->count())->toBe(0);
});

test('Caddy may issue certificates only for hosts Studio knows', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    $workspace = Workspace::factory()->create(['project_id' => $project->id, 'app_url' => 'https://maria-shop.dev.example.test']);

    $this->get(route('api.tls.ask', ['domain' => 'studio.dev.example.test']))->assertOk();
    $this->get(route('api.tls.ask', ['domain' => 'shop.dev.example.test']))->assertOk();
    $this->get(route('api.tls.ask', ['domain' => 'maria-shop.dev.example.test']))->assertOk();
    $this->get(route('api.tls.ask', ['domain' => 'evil.dev.example.test']))->assertNotFound();
    $this->get(route('api.tls.ask', ['domain' => 'shop.other.test']))->assertNotFound();
    $this->get(route('api.tls.ask'))->assertNotFound();
});

test('a project marked for bootstrap gets Laravel pushed before its site is created', function () {
    $admin = User::factory()->admin()->create(['handle' => 'andrea', 'git_token' => 'github_pat_admin']);
    $project = Project::factory()->create(['slug' => 'fresh', 'repo_url' => 'https://github.com/agency/fresh.git', 'deploy_user_id' => $admin->id, 'settings' => ['bootstrap' => true]]);
    $log = tempnam(sys_get_temp_dir(), 'studio-admin-');
    $_ENV['FAKE_STUDIO_ADMIN_LOG'] = $_SERVER['FAKE_STUDIO_ADMIN_LOG'] = $log;
    Http::fake([
        'https://api.github.com/user' => Http::response(['login' => 'andrea'], 200),
        'https://api.github.com/repos/agency/fresh/hooks' => Http::response(['id' => 1], 201),
    ]);

    app(ProjectSites::class)->provision($project);
    $project->refresh();

    $calls = array_map(fn ($line) => explode(' ', $line)[0], array_filter(explode("\n", file_get_contents($log))));
    unset($_ENV['FAKE_STUDIO_ADMIN_LOG'], $_SERVER['FAKE_STUDIO_ADMIN_LOG']);
    @unlink($log);

    expect($calls)->toBe(['project-bootstrap', 'project-create'])
        ->and($project->needsBootstrap())->toBeFalse()
        ->and($project->site_status)->toBe(SiteStatus::Ready);
});
