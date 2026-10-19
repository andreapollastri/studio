<?php

use App\Livewire\Admin\System;
use App\Models\User;
use App\Server\SecurityPolicy;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

function securityLogInto(string $path): void
{
    config(['logging.channels.security.path' => $path]);
}

test('every page answers with the security headers, and HSTS over https only', function () {
    $response = $this->get('/login')->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('Strict-Transport-Security');

    expect((string) $response->headers->get('Content-Security-Policy'))
        ->toContain("frame-ancestors 'self'")
        ->toContain("object-src 'none'")
        ->toContain("form-action 'self'")
        ->toContain('frame-src https: http:');

    $secure = $this->get('https://studio.localhost/login')->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

    // over https the previews, images and sockets come from https hosts only
    expect((string) $secure->headers->get('Content-Security-Policy'))
        ->toContain('frame-src https:;')
        ->not->toContain('http:');
});

test('the endpoints for Caddy, the bridges and the SSH gateway answer the loopback only', function () {
    config(['studio.domain' => 'agency.test']);
    $this->get('/api/tls/ask?domain=studio.agency.test')->assertOk();

    $elsewhere = fn () => $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
    $elsewhere()->get('/api/tls/ask?domain=studio.agency.test')->assertNotFound();
    $elsewhere()->get('/api/site-auth')->assertNotFound();
    $elsewhere()->postJson('/api/ssh/redeem', ['ticket' => str_repeat('a', 48)])->assertNotFound();
    $elsewhere()->postJson('/api/bridge/events', ['conversation' => 1, 'events' => []])->assertNotFound();

    config(['studio.security.internal_loopback_only' => false]);
    $elsewhere()->get('/api/tls/ask?domain=studio.agency.test')->assertOk();
});

test('one address gets so many sign-in attempts a minute, whatever the accounts it tries', function () {
    config(['studio.security.login_per_ip_per_minute' => 3]);

    foreach (range(1, 3) as $i) {
        $this->post('/login', ['email' => "nobody{$i}@agency.test", 'password' => 'wrong-password'])->assertSessionHasErrors('email');
    }

    $this->post('/login', ['email' => 'nobody4@agency.test', 'password' => 'wrong-password'])->assertStatus(429);
});

test('sign-ins, refusals and the door go to the security log with the address, and System shows the latest', function () {
    securityLogInto($log = tempnam(sys_get_temp_dir(), 'studio-security'));
    $user = User::factory()->admin()->create();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
    $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    $this->get('/door/not-the-key');
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();

    $lines = file($log, FILE_IGNORE_NEW_LINES);
    expect($lines[0])->toMatch('/\.WARNING: login\.failed email='.preg_quote($user->email, '/').' known=yes ip=203\.0\.113\.9 agent=/')
        ->and($lines[1])->toContain('.WARNING: door.refused ip=203.0.113.9')
        ->and($lines[2])->toContain('.INFO: login.ok user='.$user->email.' remember=no ip=203.0.113.9');

    $recent = SecurityLog::recent(2);
    expect($recent)->toHaveCount(2)
        ->and($recent[0]['level'])->toBe('INFO')
        ->and($recent[0]['message'])->toStartWith('login.ok')
        ->and($recent[1]['message'])->toStartWith('door.refused');

    Livewire::actingAs($user)->test(System::class)->assertSee('login.ok user='.$user->email)->assertSee('door.refused');
    unlink($log);
});

test('whoever must set two-factor up is sent to Settings → Security and nowhere else', function () {
    securityLogInto(tempnam(sys_get_temp_dir(), 'studio-security'));
    $admin = User::factory()->admin()->create();
    $dev = User::factory()->create();
    $ready = User::factory()->admin()->withTwoFactor()->create();

    Livewire::actingAs($admin)->test(System::class)->set('twoFactor', 'admins');
    expect(app(SecurityPolicy::class)->twoFactor())->toBe(SecurityPolicy::ADMINS);

    $this->actingAs($admin)->get('/projects')->assertRedirect(route('security.edit'));
    $this->actingAs($admin)->get(route('admin.users'))->assertRedirect(route('security.edit'));
    $this->actingAs($admin)->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))
        ->assertOk()->assertSee('Required for your account');
    $this->actingAs($admin)->post('/logout')->assertRedirect('/');

    $this->actingAs($dev)->get('/projects')->assertOk();
    $this->actingAs($ready)->get('/projects')->assertOk();
    $this->actingAs($ready)->get(route('admin.users'))->assertOk()->assertSee('no 2FA');

    Livewire::actingAs($ready)->test(System::class)->set('twoFactor', 'all');
    $this->actingAs($dev)->get('/projects')->assertRedirect(route('security.edit'));

    Livewire::actingAs($ready)->test(System::class)->set('twoFactor', 'nonsense');
    expect(app(SecurityPolicy::class)->twoFactor())->toBe(SecurityPolicy::OFF);
    $this->actingAs($admin)->get('/projects')->assertOk();
});

test('a Livewire call while two-factor is missing runs the Security page only', function () {
    securityLogInto(tempnam(sys_get_temp_dir(), 'studio-security'));
    app(SecurityPolicy::class)->setTwoFactor(SecurityPolicy::ALL);
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.security')->assertOk()->assertSet('twoFactorRequired', true)
        ->call('disable')->assertSet('twoFactorEnabled', false);

    $snapshot = fn (string $name) => ['components' => [['snapshot' => json_encode(['memo' => ['name' => $name]]), 'calls' => []]]];
    $headers = ['X-Livewire' => '1'];
    $update = app(HandleRequests::class)->getUpdateUri();
    // the Security page's components reach Livewire (which then refuses a forged snapshot on its own); any other does not
    expect($this->actingAs($user)->post($update, $snapshot('pages::settings.security'), $headers)->status())->not->toBe(403);
    $this->actingAs($user)->post($update, $snapshot('tools.vault'), $headers)->assertForbidden();
    $this->actingAs($user)->post($update, ['components' => []], $headers)->assertForbidden();
});

test('a session ends when the password changed from another one, and changing it closes the others', function () {
    securityLogInto($log = tempnam(sys_get_temp_dir(), 'studio-security'));
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['password_hash_web' => 'the hash of the old password'])->get('/projects')->assertRedirect('/login');

    $hash = $user->password;
    Livewire::actingAs($user)->test('pages::settings.security')
        ->set('current_password', 'password')->set('password', 'a-longer-new-password')->set('password_confirmation', 'a-longer-new-password')
        ->call('updatePassword')->assertHasNoErrors();

    // a new hash: every remember-me cookie made with the old one is refused (AuthenticateSession), the others' sessions end
    expect($user->refresh()->password)->not->toBe($hash);
    $lines = implode("\n", file($log, FILE_IGNORE_NEW_LINES));
    expect($lines)->toContain('sessions.others-closed user='.$user->email)->toContain('password.changed user='.$user->email);
    unlink($log);
});

test('a password has twelve characters at least', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.security')
        ->set('current_password', 'password')->set('password', 'short-one')->set('password_confirmation', 'short-one')
        ->call('updatePassword')->assertHasErrors(['password']);
});

test('"remember me" lasts thirty days, and booting does not need the encryption key', function () {
    $user = User::factory()->create();
    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => 'on']);

    $remember = collect($response->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
    expect($remember)->not->toBeNull()
        ->and($remember->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->getTimestamp())
        ->toBeLessThan(now()->addDays(31)->getTimestamp());

    // the installer runs composer (package:discover) before key:generate: nothing at boot may
    // build the encrypted session store
    $boot = Process::path(base_path())->env(['APP_KEY' => '', 'SESSION_ENCRYPT' => 'true'])
        ->run([PHP_BINARY, 'artisan', '--version']);

    expect($boot->successful())->toBeTrue($boot->errorOutput().$boot->output());
});

test('forgot password answers the same for an unknown address', function () {
    Notification::fake();
    $user = User::factory()->create();

    $known = $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();
    $unknown = $this->post('/forgot-password', ['email' => 'nobody@agency.test'])->assertSessionHasNoErrors();

    expect($known->getSession()->get('status'))->toBe($unknown->getSession()->get('status'))->toContain('If that address belongs to an account');
});
