<?php

namespace Database\Factories;

use App\Models\SshKey;
use App\Models\User;
use App\Ssh\Keys;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SshKey>
 */
class SshKeyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'laptop '.fake()->unique()->numberBetween(1, 9999),
            ...Keys::generate('ed25519', 'studio'),
        ];
    }

    /** A key of the installation, managed in System. */
    public function system(): static
    {
        return $this->state(['user_id' => null, 'name' => 'deploy '.fake()->unique()->numberBetween(1, 9999)]);
    }
}
