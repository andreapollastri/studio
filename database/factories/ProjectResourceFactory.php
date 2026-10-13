<?php

namespace Database\Factories;

use App\Models\ConnectorAccount;
use App\Models\Project;
use App\Models\ProjectResource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectResource>
 */
class ProjectResourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'connector_account_id' => ConnectorAccount::factory(),
            'section' => 'droplets',
            'resource_id' => (string) fake()->numberBetween(1000, 9999),
            'label' => fake()->domainWord(),
            'scope' => null,
            'view_roles' => ['pm', 'dev'],
            'act_roles' => ['dev'],
            'actions' => [],
        ];
    }
}
