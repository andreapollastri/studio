<?php

use App\Livewire\Admin\System;
use App\Models\User;
use App\Server\PhpVersions;
use App\Server\Updates;
use Illuminate\Support\Facades\Cache;
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

test('the last server update shows what changed and whether a reboot is needed', function () {
    writeState($this->state, [
        'current' => 'v1.0.0', 'status' => 'ok',
        'system' => [
            'auto' => true, 'status' => 'ok', 'message' => 'Updated 2 packages.', 'finished_at' => now()->toIso8601String(),
            'changes' => ['mariadb-server 1:10.11.13 → 1:10.11.14', 'php8.4-fpm 8.4.12-1 → 8.4.13-1'],
            'reboot_required' => true, 'reboot_packages' => ['linux-image-6.8.0-90'],
            'log' => ['server update: every package and tool to its newest version'],
        ],
    ]);

    expect(app(Updates::class)->systemRunning())->toBeFalse();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(System::class)
        ->assertSet('serverAutomatic', true)
        ->assertSee('Updated 2 packages.')
        ->assertSee('php8.4-fpm 8.4.12-1 → 8.4.13-1')
        ->assertSee('reboot required')
        ->assertSee('Update the server now');
});

test('certificates Caddy could not renew are shown with what to check', function () {
    writeState($this->state, ['current' => 'v1.0.0', 'system' => ['status' => 'ok', 'certificates' => [
        'checked_at' => now()->toIso8601String(), 'total' => 3, 'renewed' => ['shop.example.test'],
        'problems' => [['host' => 'old.example.test', 'days' => -2, 'state' => 'expired'], ['host' => 'stuck.example.test', 'days' => 5, 'state' => 'late']],
        'next' => ['host' => 'old.example.test', 'days' => -2, 'state' => 'expired'],
    ]]]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(System::class)
        ->assertSee('Caddy could not renew these certificates')
        ->assertSee('old.example.test · expired')
        ->assertSee('stuck.example.test · 5 days left')
        ->assertSee('journalctl -u caddy');
});

test('valid certificates show how many, the next to expire and what the last check renewed', function () {
    writeState($this->state, ['current' => 'v1.0.0', 'system' => ['status' => 'ok', 'certificates' => [
        'checked_at' => now()->toIso8601String(), 'total' => 4, 'renewed' => ['shop.example.test'], 'problems' => [],
        'next' => ['host' => 'acme.example.test', 'days' => 41, 'state' => 'ok'],
    ]]]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(System::class)
        ->assertSee('4 TLS certificates, all valid')
        ->assertSee('the next to expire: acme.example.test in 41 days')
        ->assertSee('renewed by the last check: shop.example.test')
        ->assertDontSee('Caddy could not renew');
});

test('the server update and its nightly switch go through the helper', function () {
    writeState($this->state, ['current' => 'v1.0.0', 'system' => ['auto' => true]]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(System::class)
        ->call('updateServer')
        ->set('serverAutomatic', false)
        ->assertSet('error', null);

    expect(helperCalls($this->log))->toBe(['system-update-start', 'system-update-auto off']);
});

test('a server update in progress keeps the page following it', function () {
    writeState($this->state, ['current' => 'v1.0.0', 'system' => ['status' => 'running', 'auto' => false]]);

    expect(app(Updates::class)->systemRunning())->toBeTrue();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(System::class)
        ->assertSet('serverAutomatic', false)
        ->assertSee('running: true', false)
        ->assertSee('updating…');
});

test('PHP 8.5 is the default and the optional 8.3 and 8.4 install from the page, one at a time', function () {
    config(['studio.php_versions' => ['8.5']]);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(System::class)
        ->assertSeeInOrder(['PHP 8.5', 'default', 'installed', 'PHP 8.4', 'Install', 'PHP 8.3', 'Install'])
        ->call('installPhp', '8.4')
        ->assertSet('error', null);

    expect(helperCalls($this->log))->toBe(['php-install 8.4'])
        ->and(app(PhpVersions::class)->state())->toBeNull();

    // a version the release does not offer, or one already there, never reaches the helper
    Livewire::actingAs($admin)->test(System::class)
        ->call('installPhp', '7.4')
        ->assertSet('error', 'PHP 7.4 is not one this server can add.')
        ->call('installPhp', '8.5')
        ->assertSet('error', 'PHP 8.5 is not one this server can add.');

    expect(helperCalls($this->log))->toBe(['php-install 8.4']);
});

test('an install under way blocks a second one, and a failed one says why', function () {
    config(['studio.php_versions' => ['8.5']]);
    $admin = User::factory()->admin()->create();
    Cache::put('studio:php-install', ['version' => '8.4', 'status' => 'running', 'error' => null, 'at' => now()->toIso8601String()]);

    Livewire::actingAs($admin)->test(System::class)
        ->assertSee('installing…')
        ->assertSeeHtml('wire:poll.5s')
        ->call('installPhp', '8.3')
        ->assertSet('error', 'PHP 8.4 is being installed: one at a time.');

    Cache::forget('studio:php-install');
    $_ENV['FAKE_PHP_INSTALL_FAIL'] = $_SERVER['FAKE_PHP_INSTALL_FAIL'] = '1';

    try {
        Livewire::actingAs($admin)->test(System::class)
            ->call('installPhp', '8.3')
            ->assertSee('PHP 8.3 could not be installed')
            ->assertSee('/var/log/studio-install.log');
    } finally {
        unset($_ENV['FAKE_PHP_INSTALL_FAIL'], $_SERVER['FAKE_PHP_INSTALL_FAIL']);
    }

    expect(app(PhpVersions::class)->state())->toMatchArray(['version' => '8.3', 'status' => 'failed']);
});

test('a local installation explains that updates happen on the server', function () {
    config(['studio.driver' => 'local']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(System::class)
        ->assertSee('local installation')
        ->call('forceUpdate')
        ->assertSet('error', 'Updates run on the server. This Studio is a local installation.')
        ->call('updateServer')
        ->assertSet('error', 'Updates run on the server. This Studio is a local installation.');

    expect(file_get_contents($this->log))->toBe('');
});

test('the system page lists every git host: none is the installation\'s own', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(System::class)
        ->assertSee(['GitHub', 'GitLab', 'Bitbucket', 'Azure DevOps'])
        ->assertDontSee('studio:git-provider');
});

test('the page follows an update through a JSON status that answers even during the maintenance of the switch', function () {
    $admin = User::factory()->admin()->create();
    writeState($this->state, [
        'status' => 'running', 'message' => 'Updating v1.0.0 → v1.1.0', 'started_at' => '2026-10-17T10:00:00Z', 'finished_at' => null,
        'log' => ['==> building v1.1.0', '==> migrating'],
        'system' => ['status' => 'ok', 'finished_at' => '2026-10-17T03:00:00Z', 'log' => []],
    ]);

    $this->actingAs(User::factory()->create())->getJson(route('admin.system.status'))->assertForbidden();

    $this->actingAs($admin)->get(route('admin.system'))->assertOk()->assertSee('systemWatch', false)->assertDontSee('wire:poll', false);

    $this->artisan('down');
    try {
        $this->actingAs($admin)->getJson(route('admin.system.status'))
            ->assertOk()
            ->assertJson(['status' => 'running', 'message' => 'Updating v1.0.0 → v1.1.0', 'log' => ['==> building v1.1.0', '==> migrating'], 'server' => ['status' => 'ok']]);
        $this->actingAs($admin)->get(route('admin.system'))->assertStatus(503);
    } finally {
        $this->artisan('up');
    }
});
