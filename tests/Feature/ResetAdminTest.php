<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

test('reset-admin takes the oldest administrator: new password, 2FA off, sessions closed, still an admin', function () {
    $first = User::factory()->admin()->create([
        'email' => 'first@example.test',
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['a', 'b'])),
        'two_factor_confirmed_at' => now(),
        'remember_token' => 'old-remember-me',
    ]);
    $second = User::factory()->admin()->create(['email' => 'second@example.test']);
    DB::table('sessions')->insert([
        ['id' => 'sess-first', 'user_id' => $first->id, 'payload' => '', 'last_activity' => time()],
        ['id' => 'sess-second', 'user_id' => $second->id, 'payload' => '', 'last_activity' => time()],
    ]);

    $this->artisan('studio:reset-admin', ['--password' => 'brand-new-password-1'])
        ->expectsOutputToContain('first@example.test')
        ->assertSuccessful();

    $first->refresh();
    expect(Hash::check('brand-new-password-1', $first->password))->toBeTrue()
        ->and($first->role)->toBe(UserRole::Admin)
        ->and($first->two_factor_secret)->toBeNull()
        ->and($first->two_factor_recovery_codes)->toBeNull()
        ->and($first->two_factor_confirmed_at)->toBeNull()
        ->and($first->remember_token)->not->toBe('old-remember-me')
        ->and(DB::table('sessions')->pluck('id')->all())->toBe(['sess-second'])
        ->and(Hash::check('brand-new-password-1', $second->fresh()->password))->toBeFalse();
});

test('reset-admin with an email resets that account and gives it back the admin role', function () {
    User::factory()->admin()->create(['email' => 'boss@example.test']);
    $demoted = User::factory()->create(['email' => 'Me@Example.test', 'role' => UserRole::Dev, 'email_verified_at' => null]);

    expect(Artisan::call('studio:reset-admin', ['--email' => 'me@example.test', '--json' => true]))->toBe(0);
    $out = json_decode(Artisan::output(), true);

    expect($out['email'])->toBe('Me@Example.test')
        ->and($out['created'])->toBeFalse()
        ->and(Hash::check($out['password'], $demoted->fresh()->password))->toBeTrue()
        ->and($demoted->fresh()->role)->toBe(UserRole::Admin)
        ->and($demoted->fresh()->email_verified_at)->not->toBeNull();
});

test('reset-admin creates the administrator when the email is unknown, and needs one when nobody is an admin', function () {
    User::factory()->create(['role' => UserRole::Dev]);

    $this->artisan('studio:reset-admin')->assertFailed();
    $this->artisan('studio:reset-admin', ['--email' => 'not-an-email'])->assertFailed();

    $this->artisan('studio:reset-admin', ['--email' => 'Root@Example.test', '--password' => 'fresh-start-password'])
        ->expectsOutputToContain('created')
        ->assertSuccessful();

    $user = User::query()->where('email', 'root@example.test')->firstOrFail();
    expect($user->role)->toBe(UserRole::Admin)
        ->and($user->name)->toBe('Root')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('fresh-start-password', $user->password))->toBeTrue();
});
