<?php

use App\Livewire\Admin\System;
use App\Models\User;
use App\Server\DashboardVisibility;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

function hideDashboard(): DashboardVisibility
{
    $visibility = app(DashboardVisibility::class);
    $visibility->setHidden(true);

    return $visibility;
}

test('a public dashboard shows its sign-in page to anyone, as before', function () {
    $this->get('/login')->assertOk();
    $this->get('/')->assertRedirect('/login');
});

test('a hidden dashboard shows a guest the page of an unknown address, which says nothing of Studio', function () {
    hideDashboard();

    $this->get('/login')->assertNotFound()->assertSee('Nothing here yet')->assertDontSee('Open Studio');
    $this->get('/')->assertNotFound();
    $this->get('/forgot-password')->assertNotFound();
    $this->get('/door/not-the-key')->assertNotFound()->assertDontSee('Open Studio');
});

test('the door leaves a cookie that opens the sign-in page, until its key is rotated', function () {
    $visibility = hideDashboard();

    $response = $this->get('/door/'.$visibility->doorKey())->assertRedirect(route('login'));
    $cookie = $response->getCookie(DashboardVisibility::COOKIE, decrypt: false);
    expect($cookie)->not->toBeNull()->and($cookie->isSecure())->toBeTrue()->and($cookie->isHttpOnly())->toBeTrue();

    $this->withCookie(DashboardVisibility::COOKIE, $visibility->cookieValue())->get('/login')->assertOk();

    $old = $visibility->cookieValue();
    $visibility->rotateDoor();
    $this->withCookie(DashboardVisibility::COOKIE, $old)->get('/login')->assertNotFound();
});

test('a signed-in person passes and gets the door cookie, so signing out still leads to the sign-in page', function () {
    hideDashboard();
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')->assertOk()->assertCookie(DashboardVisibility::COOKIE);
});

test('a good password reset link opens a hidden dashboard, a made-up one does not', function () {
    hideDashboard();
    $user = User::factory()->create(['email' => 'reset@example.test']);
    $token = Password::broker()->createToken($user);

    $this->get('/reset-password/'.$token.'?email=reset@example.test')->assertOk()->assertCookie(DashboardVisibility::COOKIE);
    $this->get('/reset-password/made-up?email=reset@example.test')->assertNotFound();
});

test('unknown project hosts drop the link to Studio when it is hidden', function () {
    config(['studio.domain' => 'dev.example.test']);

    $this->get('/api/site-auth', ['X-Forwarded-Host' => 'nobody.dev.example.test'])->assertNotFound()->assertSee('Open Studio');

    hideDashboard();
    $this->get('/api/site-auth', ['X-Forwarded-Host' => 'nobody.dev.example.test'])->assertNotFound()->assertDontSee('Open Studio');
});

test('administrators hide the dashboard and make a new door link from the System page', function () {
    $admin = User::factory()->admin()->create();
    $visibility = app(DashboardVisibility::class);

    Livewire::actingAs($admin)->test(System::class)
        ->assertSet('visible', true)
        ->set('visible', false)
        ->assertSee($visibility->doorUrl());

    expect(app(DashboardVisibility::class)->hidden())->toBeTrue();

    $before = $visibility->doorKey();
    Livewire::actingAs($admin)->test(System::class)->call('rotateDoor');
    expect($visibility->doorKey())->not->toBe($before);
});

test('root hides it, shows it again and reads the door link from the server', function () {
    $this->artisan('studio:visibility', ['state' => 'hidden'])->expectsOutputToContain('/door/')->assertSuccessful();
    expect(app(DashboardVisibility::class)->hidden())->toBeTrue();

    $key = app(DashboardVisibility::class)->doorKey();
    $this->artisan('studio:visibility', ['--rotate' => true, '--json' => true])->assertSuccessful();
    expect(app(DashboardVisibility::class)->doorKey())->not->toBe($key);

    $this->artisan('studio:visibility', ['state' => 'public'])->expectsOutputToContain('Public')->assertSuccessful();
    expect(app(DashboardVisibility::class)->hidden())->toBeFalse();

    $this->artisan('studio:visibility', ['state' => 'invisible'])->assertFailed();
});
