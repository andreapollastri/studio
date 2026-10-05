<?php

namespace Database\Factories;

use App\Enums\WorkspaceStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'driver' => 'local',
            'status' => WorkspaceStatus::New,
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => WorkspaceStatus::Running,
            'bridge_url' => 'http://bridge.test:4455',
            'bridge_token' => 'bridge-secret',
            'app_url' => 'https://preview.example.test',
            'path' => null,
            'callback_token' => 'callback-plain-token',
            'callback_token_hash' => hash('sha256', 'callback-plain-token'),
        ]);
    }
}
