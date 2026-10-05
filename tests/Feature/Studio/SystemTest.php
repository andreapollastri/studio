<?php

use App\Livewire\Admin\System;
use App\Models\Project;
use App\Models\User;
use App\Server\Updates;
use Livewire\Livewire;

beforeEach(function () {
    $this->state = tempnam(sys_get_temp_dir(), 'state');
    $this->log = tempnam(sys_get_temp_dir(), 'helper');
    $_ENV['FAKE_STUDIO_ADMIN_LOG'] = $_SERVER['FAKE_STUDIO_ADMIN_LOG'] = $this->log;

    config([
        'studio.driver' => 'native',
        'studio.native.helper' => base_path('tests/fixtures/fake-studio-admin'),
        'studio.native.sudo' => false,
        'studio.updates.state' => $this->state,
        'studio.updates.repository' => 'https://github.com/andreapollastri/studio',
        'studio.version' => '1.0.0',
    ]);
});

afterEach(function () {
    unset($_ENV['FAKE_STUDIO_ADMIN_LOG'], $_SERVER['FAKE_STUDIO_ADMIN_LOG']);
    @unlink($this->state);
    @unlink($this->log);
});

function writeState(string $path, array $state): void
{
    file_put_contents($path, json_encode($state));
}

/** @return list<string> the helper commands, with their arguments */
function helperCalls(string $log): array
{
    return array_map(fn (string $line) => trim(preg_replace('/\s*[\[{].*$/', '', $line)), file($log, FILE_IGNORE_NEW_LINES));
}

test('the system page is for administrators only', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.system'))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.system'))->assertOk()->assertSee('GitHub');
});

test('the build the updater found is offered, with the last run and its log', function () {
    writeState($this->state, [
        'channel' => 'stable', 'current' => 'v1.0.0', 'latest' => 'v1.1.0', 'target' => 'v1.1.0', 'update_available' => true,
        'checked_at' => now()->toIso8601String(), 'auto' => true,
        'status' => 'failed', 'message' => 'the migrations of v1.1.0 failed', 'finished_at' => now()->toIso8601String(),
        'log' => ['cloning v1.1.0', 'migrating', 'FAILED: the migrations of v1.1.0 failed'],
    ]);

    expect(app(Updates::class)->available())->toBe('v1.1.0');

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(System::class)
        ->assertSee('Update to v1.1.0')
        ->assertSee('Force update')
        ->assertSee('FAILED: the migrations of v1.1.0 failed')
        ->assertSee('previous release and its database are back');

    writeState($this->state, ['current' => 'v1.1.0', 'latest' => 'v1.1.0', 'target' => 'v1.1.0', 'update_available' => false]);
    expect(app(Updates::class)->available())->toBeNull();
});

test('a main build newer than the newest release says why it stays', function () {
    writeState($this->state, ['channel' => 'stable', 'current' => 'main-1a2b3c4', 'latest' => 'v1.1.0', 'target' => 'v1.1.0', 'update_available' => false, 'waiting_reason' => 'behind']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(System::class)
        ->assertSee('Studio runs main-1a2b3c4, newer than v1.1.0')
        ->assertDontSee('Update to v1.1.0');
});

test('check, update, force, the channel and the nightly switch go through the helper', function () {
    writeState($this->state, ['channel' => 'stable', 'current' => 'v1.0.0', 'target' => 'v1.1.0', 'update_available' => true, 'auto' => true]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(System::class)
        ->assertSet('automatic', true)
        ->assertSet('channel', 'stable')
        ->call('check')
        ->call('update')
        ->call('forceUpdate')
        ->set('channel', 'beta')
        ->set('automatic', false)
        ->assertSet('error', null);

    expect(helperCalls($this->log))->toBe(['update-check', 'update-start', 'update-start force', 'update-channel beta', 'update-auto off']);
});

test('the beta channel shows the newest commit on main and links to it', function () {
    writeState($this->state, ['channel' => 'beta', 'current' => 'main-1a2b3c4', 'main' => 'main-9f8e7d6', 'latest' => 'v1.1.0', 'target' => 'main-9f8e7d6', 'update_available' => true]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(System::class)
        ->assertSet('channel', 'beta')
        ->assertSee('Newest on main')
        ->assertSee('https://github.com/andreapollastri/studio/commit/9f8e7d6', false)
        ->assertSee('Beta tester mode');
});

test('a local installation explains that updates happen on the server', function () {
    config(['studio.driver' => 'local']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(System::class)
        ->assertSee('local installation')
        ->call('forceUpdate')
        ->assertSet('error', 'Updates run on the server. This Studio is a local installation.');

    expect(file_get_contents($this->log))->toBe('');
});

test('the git provider can only change while there are no projects', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(System::class)->assertSee('studio:git-provider');

    Project::factory()->create();
    Livewire::actingAs($admin)->test(System::class)->assertDontSee('studio:git-provider')->assertSee('so the provider stays');
});
