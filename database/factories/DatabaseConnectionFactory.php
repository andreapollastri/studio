<?php

namespace Database\Factories;

use App\Databases\Driver;
use App\Databases\Environment;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseConnection>
 */
class DatabaseConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'database '.fake()->unique()->numberBetween(1, 99999),
            'driver' => Driver::Mysql,
            'environment' => Environment::Development,
            'host' => 'db.example.com',
            'port' => 3306,
            'username' => 'app',
            'password' => 'secret',
            'database' => 'app',
            'tunnel_as' => 'owner',
        ];
    }

    /** A SQLite file on Studio's disk, as an administrator adds one. */
    public function sqlite(string $path): static
    {
        return $this->state(fn () => ['driver' => Driver::Sqlite, 'host' => null, 'port' => null, 'username' => null, 'password' => null, 'database' => $path]);
    }

    public function production(): static
    {
        return $this->state(fn () => ['environment' => Environment::Production]);
    }
}
