<?php

namespace Database\Factories;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'title' => null,
            'status' => ConversationStatus::Idle,
            'permission_mode' => 'default',
        ];
    }

    /** A conversation that belongs to the owner of its workspace. */
    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(fn () => [
            'workspace_id' => $workspace->id,
            'user_id' => $workspace->user_id,
        ]);
    }
}
