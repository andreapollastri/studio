<?php

use App\Enums\ClaudeAuthMode;
use App\Enums\WorkspaceStatus;
use App\Jobs\ProvisionWorkspace;
use App\Jobs\RefreshWorkspaces;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Server\ServerException;
use App\Server\StudioAdmin;
use App\Workspaces\NativeDriver;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'studio.driver' => 'native',
        'studio.domain' => 'dev.example.test',
        'studio.native.helper' => base_path('tests/fixtures/fake-studio-admin'),
        'studio.native.sudo' => false,
        'studio.native.bridge_port_base' => 42000,
    ]);
    // Symfony Process inherits $_ENV / $_SERVER, not putenv(): set both.
    $this->log = tempnam(sys_get_temp_dir(), 'studio-admin-');
    $_ENV['FAKE_STUDIO_ADMIN_LOG'] = $_SERVER['FAKE_STUDIO_ADMIN_LOG'] = $this->log;
});

afterEach(function () {
    unset($_ENV['FAKE_STUDIO_ADMIN_LOG'], $_SERVER['FAKE_STUDIO_ADMIN_LOG'], $_ENV['FAKE_STUDIO_ADMIN_STATUS'], $_SERVER['FAKE_STUDIO_ADMIN_STATUS']);
    @unlink($this->log);
});

function connectedUser(): User
{
    return User::factory()->create([
        'handle' => 'maria',
        'git_login' => 'MariaRossi',
        'git_token' => 'github_pat_secret',
        'claude_auth_mode' => ClaudeAuthMode::Subscription,
        'claude_token' => 'sk-ant-oat-secret',
    ]);
}

test('the helper client sends secrets on stdin and refuses odd arguments', function () {
    $admin = app(StudioAdmin::class);

    $result = $admin->run('workspace-status', ['maria', 'shop']);
    expect($result['status'])->toBe('running');

    expect(fn () => $admin->run('workspace-status', ['maria; rm -rf /', 'shop']))->toThrow(ServerException::class);
    expect(fn () => $admin->run('fail'))->toThrow(ServerException::class, 'boom');
});

test('the helper client reports the first reason the helper gives, and what stopped it unexpectedly', function () {
    $admin = app(StudioAdmin::class);

    expect(fn () => $admin->run('fail-in-subshell'))
        ->toThrow(ServerException::class, 'studio-admin fail-in-subshell failed: missing input field: repo_url');

    try {
        $admin->run('fail-unexpected');
        $this->fail('the helper failed');
    } catch (ServerException $e) {
        expect($e->getMessage())->toContain('stopped (exit 1) at: systemctl reload "php$1-fpm"')
            ->toContain('Job for php8.4-fpm.service failed.');
    }
});

test('provisioning a native workspace hands the helper the project, the ports and the credentials', function () {
    $user = connectedUser();
    $project = Project::factory()->create(['slug' => 'shop', 'php_version' => '8.4', 'db_engine' => 'pgsql']);
    $project->members()->attach($user);

    $workspace = app(WorkspaceManager::class)->forUser($project, $user);
    app(NativeDriver::class)->provision($workspace);
    $workspace->refresh();

    expect($workspace->status)->toBe(WorkspaceStatus::Running)
        ->and($workspace->driver)->toBe('native')
        ->and($workspace->app_url)->toBe('https://maria-shop.dev.example.test')
        ->and($workspace->bridge_url)->toBe('http://127.0.0.1:'.(42000 + $workspace->id))
        ->and($workspace->path)->toBe('/srv/studio/workspaces/maria/shop')
        ->and($workspace->bridge_token)->not->toBeNull()
        ->and($workspace->callback_token)->not->toBeNull();

    $line = trim(file_get_contents($this->log));
    $sent = json_decode(substr($line, strpos($line, '{')), true);

    expect($sent['repo_url'])->toBe($project->repo_url)
        ->and($sent['php_version'])->toBe('8.4')
        ->and($sent['db_engine'])->toBe('pgsql')
        ->and($sent['preview_host'])->toBe('maria-shop.dev.example.test')
        ->and($sent['reverb_port'])->toBe(20000 + $workspace->id)
        ->and($sent['bridge_token'])->toBe($workspace->bridge_token)
        ->and($sent['git_token'])->toBe('github_pat_secret')
        ->and($sent['git_username'])->toBe('x-access-token')
        ->and($sent['git_env'])->toBe(['GITHUB_TOKEN' => 'github_pat_secret', 'GH_TOKEN' => 'github_pat_secret'])
        ->and($sent['claude_env'])->toBe('CLAUDE_CODE_OAUTH_TOKEN')
        ->and($sent['claude_token'])->toBe('sk-ant-oat-secret');
});

test('without a GitHub token the workspace fails with a readable reason', function () {
    $user = User::factory()->create(['handle' => null, 'git_token' => null]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'driver' => 'native']);

    app(NativeDriver::class)->provision($workspace);

    expect($workspace->fresh()->status)->toBe(WorkspaceStatus::Failed)
        ->and($workspace->fresh()->last_error)->toContain('GitHub');
});

test('stop, start and refresh go through the helper', function () {
    $user = connectedUser();
    $project = Project::factory()->create(['slug' => 'shop']);
    $workspace = Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $user->id, 'driver' => 'native', 'path' => '/srv/studio/workspaces/maria/shop']);
    $driver = app(NativeDriver::class);

    $driver->stop($workspace);
    expect($workspace->fresh()->status)->toBe(WorkspaceStatus::Stopped);

    $driver->start($workspace);
    expect($workspace->fresh()->status)->toBe(WorkspaceStatus::Running);

    $_ENV['FAKE_STUDIO_ADMIN_STATUS'] = $_SERVER['FAKE_STUDIO_ADMIN_STATUS'] = 'stopped';
    $driver->refresh($workspace);
    expect($workspace->fresh()->status)->toBe(WorkspaceStatus::Stopped);

    $log = file_get_contents($this->log);
    $start = json_decode(substr(Str::after($log, 'workspace-start maria shop '), 0, strpos(Str::after($log, 'workspace-start maria shop '), "\n")), true);

    expect($log)->toContain('workspace-stop maria shop')
        ->toContain('workspace-start maria shop')
        ->toContain('workspace-status maria shop')
        // starting may rewrite the preview's Caddy host: the access rule and the Reverb port travel along
        ->and($start['reverb_port'])->toBe(20000 + $workspace->id);
});

test('removing the git token in Settings clears it from the workspaces: the field is sent, empty', function () {
    $user = connectedUser();
    $project = Project::factory()->create(['slug' => 'shop']);
    $workspace = Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $user->id, 'driver' => 'native', 'path' => '/srv/studio/workspaces/maria/shop']);

    $user->forceFill(['git_token' => null, 'git_login' => null])->save();
    app(NativeDriver::class)->syncCredentials($workspace->fresh());

    $line = trim(file_get_contents($this->log));
    $sent = json_decode(substr($line, strpos($line, '{')), true);

    expect($line)->toStartWith('workspace-env maria shop')
        ->and(array_key_exists('git_token', $sent))->toBeTrue()
        ->and($sent['git_token'])->toBeNull()
        ->and($sent['git_env'])->toBe([])
        ->and($sent['claude_token'])->toBe('sk-ant-oat-secret');
});

test('the periodic refresh asks the helper only for native workspaces that exist on the server', function () {
    $maria = connectedUser();
    $luca = User::factory()->create(['handle' => 'luca']);
    $project = Project::factory()->create(['slug' => 'shop']);
    Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $maria->id, 'driver' => 'native', 'path' => null]);
    Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $luca->id, 'driver' => 'native', 'path' => '/srv/studio/workspaces/luca/shop', 'callback_token' => 'tok-2', 'callback_token_hash' => hash('sha256', 'tok-2')]);

    app(RefreshWorkspaces::class)->handle(app(WorkspaceManager::class));

    expect(file_get_contents($this->log))->not->toContain('workspace-status maria shop')
        ->toContain('workspace-status luca shop');
});

test('a workspace whose job died stops waiting on "creating" and can be started again', function () {
    $maria = connectedUser();
    $luca = User::factory()->create(['handle' => 'luca']);
    $project = Project::factory()->create(['slug' => 'shop']);
    $stuck = Workspace::factory()->create(['project_id' => $project->id, 'user_id' => $maria->id, 'driver' => 'native', 'status' => WorkspaceStatus::Creating]);
    $busy = Workspace::factory()->create(['project_id' => $project->id, 'user_id' => $luca->id, 'driver' => 'native', 'status' => WorkspaceStatus::Creating]);
    Workspace::query()->whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(RefreshWorkspaces::STUCK_AFTER_MINUTES + 5)]);

    app(RefreshWorkspaces::class)->handle(app(WorkspaceManager::class));

    expect($stuck->refresh()->status)->toBe(WorkspaceStatus::Failed)
        ->and($stuck->last_error)->toContain('Press Start to try again')
        ->and($busy->refresh()->status)->toBe(WorkspaceStatus::Creating);
});

test('a creation job the worker stops marks the workspace failed', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    $workspace = Workspace::factory()->create(['project_id' => $project->id, 'user_id' => connectedUser()->id, 'driver' => 'native', 'status' => WorkspaceStatus::Creating]);

    (new ProvisionWorkspace($workspace))->failed(new RuntimeException('has timed out'));

    expect($workspace->refresh()->status)->toBe(WorkspaceStatus::Failed)
        ->and($workspace->last_error)->toContain('queue worker stopped or timed out');
});

test('the creation jobs queued by repeated visits create the workspace once', function () {
    $user = connectedUser();
    $project = Project::factory()->create(['slug' => 'shop', 'php_version' => '8.4']);
    $project->members()->attach($user);
    $workspace = app(WorkspaceManager::class)->forUser($project, $user);

    $first = new ProvisionWorkspace($workspace);
    $second = new ProvisionWorkspace(Workspace::query()->find($workspace->id));
    $first->handle(app(WorkspaceManager::class));
    $second->workspace->refresh();
    $second->handle(app(WorkspaceManager::class));

    expect(substr_count(file_get_contents($this->log), 'workspace-create maria shop'))->toBe(1)
        ->and($workspace->refresh()->status)->toBe(WorkspaceStatus::Running);
});
