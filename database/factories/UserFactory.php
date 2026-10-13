<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\ClaudeAccount;
use App\Models\ConnectorAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'name' => $name,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::Dev,
            'handle' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => ['email_verified_at' => null]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin]);
    }

    public function pm(): static
    {
        return $this->state(fn () => ['role' => UserRole::Pm]);
    }

    public function client(): static
    {
        return $this->state(fn () => ['role' => UserRole::Client]);
    }

    /**
     * A personal account on a git host (GitHub by default), with the identity its commits carry.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function withGit(string $token = 'ghp_test_token_1234567890', string $connector = 'github', string $host = 'github.com', array $attributes = []): static
    {
        return $this->afterCreating(function (User $user) use ($token, $connector, $host, $attributes) {
            ConnectorAccount::query()->create([
                'user_id' => $user->id,
                'connector' => $connector,
                'name' => $attributes['name'] ?? $host,
                'login' => $attributes['login'] ?? $user->handle,
                'host' => $host,
                'git_name' => $attributes['git_name'] ?? $user->name,
                'git_email' => $attributes['git_email'] ?? $user->email,
                'credentials' => ['token' => $token, ...($attributes['credentials'] ?? [])],
                'status' => 'ok',
                'checked_at' => now(),
            ]);
        });
    }

    /** A Claude account, their default: an API key, or a subscription signed in (or not) in ~/.claude. */
    public function withClaude(string $kind = 'api_key', bool $signedIn = true, string $token = 'sk-ant-api03-test-key-1234567890'): static
    {
        return $this->afterCreating(function (User $user) use ($kind, $signedIn, $token) {
            $factory = ClaudeAccount::factory()->for($user);
            $account = $kind === 'subscription'
                ? $factory->subscription(signedIn: $signedIn)->create(['name' => 'Claude subscription'])
                : $factory->create(['name' => 'API key', 'token' => $token]);

            $user->forceFill(['claude_account_id' => $account->id])->save();
        });
    }

    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
