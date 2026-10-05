<?php

namespace Database\Factories;

use App\Enums\SiteStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->domainWord());

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'repo_url' => 'https://github.com/acme/'.Str::slug($name).'.git',
            'default_branch' => 'develop',
            'php_version' => '8.4',
            'db_engine' => 'mariadb',
            'site_status' => SiteStatus::New,
            'larapilot_api_token' => null,
        ];
    }

    /** A project whose site is online and whose Larapilot API can be read. */
    public function online(): static
    {
        return $this->state(fn () => [
            'site_status' => SiteStatus::Ready,
            'larapilot_api_token' => 'site-token-'.Str::random(8),
            'deployed_sha' => Str::random(7),
            'deployed_at' => now(),
        ]);
    }
}
