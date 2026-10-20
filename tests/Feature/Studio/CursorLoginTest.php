<?php

use App\Cursor\CursorLogin;
use App\Livewire\Settings\Connections;
use App\Models\CursorAccount;
use App\Models\User;
use App\Ssh\Gateway;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'studio.driver' => 'native',
        'studio.native.helper' => base_path('tests/fixtures/fake-studio-admin'),
        'studio.native.sudo' => false,
        'app.url' => 'https://studio.example.com',
    ]);
    $this->log = tempnam(sys_get_temp_dir(), 'studio-admin-');
    $_ENV['FAKE_STUDIO_ADMIN_LOG'] = $_SERVER['FAKE_STUDIO_ADMIN_LOG'] = $this->log;
});

afterEach(function () {
    unset($_ENV['FAKE_STUDIO_ADMIN_LOG'], $_SERVER['FAKE_STUDIO_ADMIN_LOG'], $_ENV['FAKE_CURSOR_LOGGED_IN'], $_SERVER['FAKE_CURSOR_LOGGED_IN'], $_ENV['FAKE_CURSOR_EMAIL'], $_SERVER['FAKE_CURSOR_EMAIL']);
    @unlink($this->log);
});

test('"Sign in to Cursor" gives a ticket the gateway redeems for the folder of that subscription in the person\'s home, nothing else', function () {
    $user = User::factory()->create(['handle' => 'maria']);
    $first = CursorAccount::factory()->for($user)->unsigned()->create();
    $work = CursorAccount::factory()->for($user)->unsigned('.cursor-work')->create(['name' => 'Work']);

    $info = Livewire::actingAs($user)->test(Connections::class)->call('cursorLoginTicket', $work->uuid)->effects['returns'][0];

    expect($info['url'])->toBe('wss://studio.example.com/ssh/ws');

    $this->postJson(route('api.ssh.redeem'), ['ticket' => $info['ticket']], ['Authorization' => 'Bearer '.Gateway::token()])
        ->assertOk()
        ->assertExactJson(['kind' => 'cursor-login', 'user_id' => $user->id, 'handle' => 'maria', 'dir' => '.cursor-work']);

    $this->postJson(route('api.ssh.redeem'), ['ticket' => $info['ticket']], ['Authorization' => 'Bearer '.Gateway::token()])->assertNotFound();
    $client = User::factory()->client()->create(['handle' => 'carla']);
    $clientAccount = CursorAccount::factory()->for($client)->create();
    $this->postJson(route('api.ssh.redeem'), ['ticket' => Gateway::issueCursorLogin($clientAccount)], ['Authorization' => 'Bearer '.Gateway::token()])->assertNotFound();
    Livewire::actingAs(User::factory()->create())->test(Connections::class)->call('cursorLoginTicket', $first->uuid)->assertStatus(404);
    config(['studio.driver' => 'local']);
    Livewire::actingAs($user)->test(Connections::class)->call('cursorLoginTicket', $first->uuid)->assertReturned(null);
});

test('after the sign-in, Studio asks the Cursor CLI in that account\'s folder and keeps only whether it is signed in', function () {
    $user = User::factory()->create(['handle' => 'maria']);
    $work = CursorAccount::factory()->for($user)->unsigned('.cursor-work')->create(['name' => 'Work']);

    Livewire::actingAs($user)->test(Connections::class)->call('refreshCursor', $work->uuid);
    expect($work->fresh()->signed_in)->toBeFalse()->and($user->fresh()->hasCursor())->toBeFalse();

    $_ENV['FAKE_CURSOR_LOGGED_IN'] = $_SERVER['FAKE_CURSOR_LOGGED_IN'] = '1';
    $_ENV['FAKE_CURSOR_EMAIL'] = $_SERVER['FAKE_CURSOR_EMAIL'] = 'maria@cursor.test';
    Livewire::actingAs($user)->test(Connections::class)
        ->set('tab', 'cursor')
        ->call('refreshCursor', $work->uuid)
        ->assertSee('Work');

    expect($work->fresh()->signed_in)->toBeTrue()
        ->and($work->fresh()->checked_at)->not->toBeNull()
        ->and($user->fresh()->hasCursor())->toBeTrue()
        ->and(file_get_contents($this->log))->toContain('cursor-status maria .cursor-work')
        ->and(file_get_contents($this->log))->not->toContain('auth.json');
});

test('each subscription gets a folder of its own, signs out, and is removed without Studio keeping a credential', function () {
    $user = User::factory()->create(['handle' => 'maria']);
    $personal = CursorAccount::factory()->for($user)->create(['name' => 'Personal']);

    $uuid = Livewire::actingAs($user)->test(Connections::class)
        ->set('new_cursor_name', 'Work')
        ->call('addCursor')->assertHasNoErrors()->effects['returns'][0];
    $work = CursorAccount::query()->where('uuid', $uuid)->sole();

    expect($work->dir)->toStartWith('.cursor-')->not->toBe('.cursor')
        ->and($work->signed_in)->toBeFalse()
        ->and($user->cursorAccounts()->count())->toBe(2);

    Livewire::actingAs($user)->test(Connections::class)
        ->call('logoutCursor', $personal->uuid)
        ->call('removeCursor', $work->uuid);

    $log = file_get_contents($this->log);
    expect($log)->toContain('cursor-logout maria')->toContain("cursor-forget maria {$work->dir}")
        ->and($personal->fresh()->signed_in)->toBeFalse()
        ->and(CursorAccount::query()->whereKey($work->id)->exists())->toBeFalse()
        ->and(CursorAccount::query()->whereKey($personal->id)->exists())->toBeTrue();
});

test('what agent status says is signed in, and an address, and nothing else', function () {
    expect(CursorLogin::interpret('{"authenticated":true,"email":"ada@cursor.test","accessToken":"secret"}', true))
        ->toBe(['logged_in' => true, 'email' => 'ada@cursor.test'])
        ->and(CursorLogin::interpret('{"loggedIn":false,"email":"ada@cursor.test"}', true))
        ->toBe(['logged_in' => false, 'email' => null])
        ->and(CursorLogin::interpret('{"user":{"email":"ada@cursor.test"}}', true))
        ->toBe(['logged_in' => true, 'email' => 'ada@cursor.test'])
        ->and(CursorLogin::interpret('{"status":"not_authenticated"}', true))
        ->toBe(['logged_in' => false, 'email' => null])
        ->and(CursorLogin::interpret('banner', false))
        ->toBe(['logged_in' => false, 'email' => null]);
});
