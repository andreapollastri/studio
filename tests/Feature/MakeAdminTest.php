<?php

use App\Enums\UserRole;
use App\Models\User;

test('the make-admin command creates a verified administrator', function () {
    $this->artisan('studio:make-admin', ['--name' => 'Andrea', '--email' => 'Andrea@Example.test', '--password' => 'super-secret-1'])
        ->assertSuccessful();

    $user = User::query()->where('email', 'andrea@example.test')->firstOrFail();

    expect($user->role)->toBe(UserRole::Admin)
        ->and($user->email_verified_at)->not->toBeNull();
});

test('the make-admin command promotes an existing account', function () {
    $user = User::factory()->create(['email' => 'pm@example.test']);

    $this->artisan('studio:make-admin', ['--name' => 'x', '--email' => 'pm@example.test'])->assertSuccessful();

    expect($user->fresh()->role)->toBe(UserRole::Admin);
});
