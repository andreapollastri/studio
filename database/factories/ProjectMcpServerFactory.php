<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectMcpServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectMcpServer>
 */
class ProjectMcpServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'docs',
            'url' => 'https://docs.example.com/mcp',
            'headers' => ['Authorization' => 'Bearer docs-token'],
            'roles' => ProjectMcpServer::DEFAULT_ROLES,
            'enabled' => true,
        ];
    }
}
