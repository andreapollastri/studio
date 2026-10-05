<?php

namespace Database\Factories;

use App\Enums\PermissionStatus;
use App\Models\Conversation;
use App\Models\PermissionRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PermissionRequest>
 */
class PermissionRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'request_id' => 'req_'.Str::random(12),
            'tool_name' => 'Bash',
            'description' => 'Run the test suite',
            'input' => ['command' => 'php artisan test'],
            'status' => PermissionStatus::Pending,
        ];
    }
}
