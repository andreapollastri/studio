<?php

namespace Database\Factories;

use App\Enums\ClaudeAuthMode;
use App\Models\ClaudeAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClaudeAccount>
 */
class ClaudeAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Key '.fake()->unique()->numberBetween(1, 99999),
            'kind' => ClaudeAuthMode::ApiKey,
            'token' => 'sk-ant-api03-'.fake()->regexify('[a-z0-9]{24}'),
        ];
    }

    /** A subscription signed in to Claude Code in the person's home: the first one in ~/.claude. */
    public function subscription(string $dir = ClaudeAccount::DEFAULT_DIR, bool $signedIn = true): static
    {
        return $this->state(fn () => [
            'name' => 'Subscription '.fake()->unique()->numberBetween(1, 99999),
            'kind' => ClaudeAuthMode::Subscription,
            'token' => null,
            'dir' => $dir,
            'signed_in' => $signedIn,
            'checked_at' => now(),
        ]);
    }
}
