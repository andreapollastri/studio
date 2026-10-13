<?php

namespace Database\Factories;

use App\Models\ConnectorAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectorAccount>
 */
class ConnectorAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connector' => 'digitalocean',
            'name' => 'Production '.fake()->unique()->numberBetween(1, 99999),
            'credentials' => ['token' => 'dop_v1_test'],
            'status' => 'ok',
            'checked_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function of(string $connector, array $credentials): static
    {
        return $this->state(fn () => ['connector' => $connector, 'credentials' => $credentials]);
    }
}
