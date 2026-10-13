<?php

namespace Database\Factories;

use App\Models\SshConnection;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SshConnection>
 */
class SshConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'server '.fake()->unique()->numberBetween(1, 9999),
            'host' => 'web1.example.com',
            'port' => 22,
            'username' => 'deploy',
            'auth' => 'password',
            'password' => 'secret',
        ];
    }

    public function withKey(?SshKey $key = null): static
    {
        return $this->state(fn (array $attributes) => [
            'auth' => 'key',
            'password' => null,
            'ssh_key_id' => $key->id ?? SshKey::factory()->state(['user_id' => $attributes['user_id']]),
        ]);
    }
}
