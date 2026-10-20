<?php

namespace Database\Factories;

use App\Models\CursorAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CursorAccount>
 */
class CursorAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Cursor '.fake()->unique()->numberBetween(1, 99999),
            'dir' => CursorAccount::DEFAULT_DIR,
            'signed_in' => true,
            'checked_at' => now(),
        ];
    }

    /** A subscription that still has to sign in, in the given folder. */
    public function unsigned(string $dir = CursorAccount::DEFAULT_DIR): static
    {
        return $this->state(fn () => [
            'dir' => $dir,
            'signed_in' => false,
            'checked_at' => null,
        ]);
    }
}
