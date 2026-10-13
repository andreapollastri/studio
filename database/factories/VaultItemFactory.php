<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VaultItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VaultItem>
 */
class VaultItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => 'Hosting panel '.fake()->unique()->numberBetween(1, 9999),
            'url' => 'https://panel.example.com',
            'username' => 'admin@example.com',
            'password' => 'pa55-word',
        ];
    }
}
