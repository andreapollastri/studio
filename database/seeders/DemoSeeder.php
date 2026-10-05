<?php

namespace Database\Seeders;

use App\Enums\ClaudeAuthMode;
use App\Enums\ConversationStatus;
use App\Enums\PermissionStatus;
use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Enums\WorkspaceStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectMcpServer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

/**
 * A believable agency: three projects, four people, one lively conversation.
 * For local development and screenshots; never meant for a real deployment.
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * Everybody's password is "password".
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $andrea = User::query()->updateOrCreate(['email' => 'andrea@agency.test'], [
            'name' => 'Andrea Pollastri', 'password' => 'password', 'role' => UserRole::Admin, 'handle' => 'andreapollastri', 'git_login' => 'andreapollastri', 'git_token' => 'github_pat_demo_admin', 'claude_auth_mode' => ClaudeAuthMode::Subscription, 'claude_token' => 'demo-claude-token', 'email_verified_at' => now(),
        ]);
        $maria = User::query()->updateOrCreate(['email' => 'maria@agency.test'], [
            'name' => 'Maria Rossi', 'password' => 'password', 'role' => UserRole::Pm, 'handle' => 'mariarossi', 'git_login' => 'mariarossi', 'git_token' => 'github_pat_demo_maria', 'claude_auth_mode' => ClaudeAuthMode::Subscription, 'claude_token' => 'demo-claude-token', 'email_verified_at' => now(),
        ]);
        $luca = User::query()->updateOrCreate(['email' => 'luca@agency.test'], [
            'name' => 'Luca Bianchi', 'password' => 'password', 'role' => UserRole::Dev, 'handle' => 'lucabianchi', 'git_login' => 'lucabianchi', 'git_token' => 'github_pat_demo_luca', 'claude_auth_mode' => ClaudeAuthMode::ApiKey, 'claude_token' => 'sk-ant-demo', 'email_verified_at' => now(),
        ]);
        $giulia = User::query()->updateOrCreate(['email' => 'giulia@client.test'], [
            'name' => 'Giulia Conti', 'password' => 'password', 'role' => UserRole::Client, 'handle' => 'giuliaconti', 'email_verified_at' => now(),
        ]);

        $shop = Project::query()->updateOrCreate(['slug' => 'shop-acme'], [
            'name' => 'Shop Acme',
            'repo_url' => 'https://github.com/agency/shop-acme.git',
            'default_branch' => 'develop',
            'php_version' => '8.4',
            'db_engine' => 'mariadb',
            'deploy_user_id' => $andrea->id,
            'site_status' => SiteStatus::Ready,
            'larapilot_api_token' => 'demo-staging-token',
            'webhook_id' => '424242',
            'deployed_sha' => 'a1b2c3d',
            'deployed_at' => now()->subHours(2),
            'settings' => config('studio.demo.staging_url') ? ['site_url' => config('studio.demo.staging_url')] : null,
        ]);
        $portale = Project::query()->updateOrCreate(['slug' => 'supplier-portal'], [
            'name' => 'Supplier Portal',
            'repo_url' => 'https://github.com/agency/supplier-portal.git',
            'default_branch' => 'develop',
            'php_version' => '8.3',
            'db_engine' => 'pgsql',
            'deploy_user_id' => $andrea->id,
            'site_status' => SiteStatus::Ready,
            'larapilot_api_token' => 'demo-staging-token',
            'deployed_sha' => '9f8e7d6',
            'deployed_at' => now()->subDay(),
        ]);
        $loyalty = Project::query()->updateOrCreate(['slug' => 'loyalty'], [
            'name' => 'Loyalty',
            'repo_url' => 'https://github.com/agency/loyalty.git',
            'default_branch' => 'main',
            'php_version' => '8.5',
            'db_engine' => 'mariadb',
            'deploy_user_id' => $andrea->id,
        ]);

        $shop->members()->syncWithoutDetaching([$maria->id, $luca->id, $giulia->id]);
        $portale->members()->syncWithoutDetaching([$maria->id, $luca->id]);
        $loyalty->members()->syncWithoutDetaching([$luca->id]);

        $running = fn (Project $project, User $user, string $preview) => Workspace::query()->updateOrCreate(
            ['project_id' => $project->id, 'user_id' => $user->id],
            [
                'driver' => config('studio.driver', 'local'),
                'status' => WorkspaceStatus::Running,
                'app_url' => config('studio.demo.preview_url') ?: $preview,
                'bridge_url' => config('studio.local.bridge_url'),
                'bridge_token' => config('studio.local.bridge_token'),
                'callback_token' => 'demo-callback-'.$user->id,
                'callback_token_hash' => hash('sha256', 'demo-callback-'.$user->id),
                'last_seen_at' => now()->subMinutes(2),
            ],
        );

        $mariaShop = $running($shop, $maria, 'https://laravel--main--shop-acme--mariarossi.dev.agency.test');
        $running($shop, $luca, 'https://laravel--main--shop-acme--lucabianchi.dev.agency.test');
        Workspace::query()->updateOrCreate(['project_id' => $portale->id, 'user_id' => $maria->id], ['driver' => 'local', 'status' => WorkspaceStatus::Stopped]);

        // Maria's live conversation: a story written, a build about to run.
        $conversation = Conversation::query()->updateOrCreate(
            ['workspace_id' => $mariaShop->id, 'user_id' => $maria->id, 'title' => 'Date range filter on orders'],
            ['status' => ConversationStatus::WaitingPermission, 'permission_mode' => 'default', 'model' => 'claude-sonnet-5', 'agent_session_id' => 'sess-demo-1', 'last_activity_at' => now()->subMinute(),
                'last_result' => ['total_cost_usd' => 0.18, 'duration_ms' => 61200, 'num_turns' => 6]],
        );
        $conversation->messages()->delete();
        $conversation->permissionRequests()->delete();

        $say = fn (string $role, string $kind, ?string $content, array $payload = [], ?string $toolId = null) => $conversation->messages()->create([
            'role' => $role, 'kind' => $kind, 'content' => $content, 'payload' => $payload ?: null, 'tool_use_id' => $toolId,
        ]);

        $say(Message::ROLE_USER, Message::KIND_TEXT, 'The client wants to filter orders by date range in the back office. Today you can only search by order number. Can we have it by Friday?');
        $say(Message::ROLE_ASSISTANT, Message::KIND_TEXT, "Yes. I read the PRD and the backlog: there is no story for this yet, so I'm adding it as a product request and planning it.\n\n**US-014 · Date range filter on the orders list**\n\n- Two fields, *From* and *To*, on the back-office orders list, validated server-side.\n- The filter combines with the existing search by order number.\n- No migration: `created_at` is already indexed.\n\nEstimate: two tasks, half a day. Friday is realistic.");
        $say(Message::ROLE_ASSISTANT, Message::KIND_TOOL_USE, 'Bash', ['name' => 'Bash', 'input' => ['command' => 'php artisan larapilot:spec-add --title="Date range filter on the orders list" --priority=high --points=3']], 'toolu_demo_1');
        $say(Message::ROLE_TOOL, Message::KIND_TOOL_RESULT, "{\n  \"schema\": \"larapilot/v1\",\n  \"kind\": \"spec\",\n  \"data\": { \"code\": \"US-014\", \"status\": \"TODO\", \"priority\": \"high\", \"points\": 3 }\n}", ['is_error' => false], 'toolu_demo_1');
        $say(Message::ROLE_ASSISTANT, Message::KIND_TOOL_USE, 'Bash', ['name' => 'Bash', 'input' => ['command' => 'php artisan larapilot:spec-plan US-014 --file=.larapilot/tmp-US-014-plan.json']], 'toolu_demo_2');
        $say(Message::ROLE_TOOL, Message::KIND_TOOL_RESULT, '{ "data": { "code": "US-014", "status": "PLANNED", "tasks": 2 } }', ['is_error' => false], 'toolu_demo_2');
        $say(Message::ROLE_USER, Message::KIND_TEXT, 'Perfect, go ahead with the implementation.');
        $say(Message::ROLE_ASSISTANT, Message::KIND_TEXT, "Working on branch `feature/US-014-date-filter`. The first task is done: a `between()` scope on the Order model, the two fields in the form, and the test. Before rebuilding the assets for the preview I'm asking you, because `npm run build` is outside the automatic perimeter of your role.");
        $say(Message::ROLE_ASSISTANT, Message::KIND_TOOL_USE, 'Edit', ['name' => 'Edit', 'input' => ['file_path' => 'app/Models/Order.php']], 'toolu_demo_3');
        $say(Message::ROLE_TOOL, Message::KIND_TOOL_RESULT, 'The file app/Models/Order.php has been updated.', ['is_error' => false], 'toolu_demo_3');

        $conversation->permissionRequests()->create([
            'request_id' => 'req_demo_1',
            'tool_name' => 'Bash',
            'description' => 'Rebuild the front-end assets to refresh the preview',
            'input' => ['command' => 'npm run build'],
            'status' => PermissionStatus::Pending,
        ]);

        Conversation::query()->updateOrCreate(
            ['workspace_id' => $mariaShop->id, 'user_id' => $maria->id, 'title' => 'Triage of the client feedback on search'],
            ['status' => ConversationStatus::Idle, 'permission_mode' => 'default', 'model' => 'claude-sonnet-5', 'last_activity_at' => now()->subHours(3), 'created_at' => now()->subHours(4), 'updated_at' => now()->subHours(3),
                'last_result' => ['total_cost_usd' => 0.07, 'duration_ms' => 23000, 'num_turns' => 3]],
        );
        // A read-only check in Plan only mode: Claude hands two questions to subagents.
        $release = Conversation::query()->updateOrCreate(
            ['workspace_id' => $mariaShop->id, 'user_id' => $maria->id, 'title' => 'Release 1.4: what is missing'],
            ['status' => ConversationStatus::Idle, 'permission_mode' => 'plan', 'model' => 'claude-opus-5-5', 'effort' => 'high', 'last_activity_at' => now()->subDay(), 'created_at' => now()->subDays(2), 'updated_at' => now()->subDay(),
                'last_result' => ['total_cost_usd' => 0.42, 'duration_ms' => 94000, 'num_turns' => 9]],
        );
        $release->messages()->delete();
        $note = fn (string $role, string $kind, ?string $content, array $payload = [], ?string $toolId = null) => $release->messages()->create([
            'role' => $role, 'kind' => $kind, 'content' => $content, 'payload' => $payload ?: null, 'tool_use_id' => $toolId,
        ]);
        $note(Message::ROLE_USER, Message::KIND_TEXT, 'What is still missing for release 1.4? Look at the backlog and at the open branches. Change nothing.');
        $note(Message::ROLE_ASSISTANT, Message::KIND_TEXT, 'I will check the backlog and the branches in parallel, with two subagents.');
        $note(Message::ROLE_ASSISTANT, Message::KIND_TOOL_USE, 'Task', ['name' => 'Task', 'input' => ['description' => 'Backlog of release 1.4', 'subagent_type' => 'larapilot-pm']], 'toolu_rel_1');
        $note(Message::ROLE_ASSISTANT, Message::KIND_TOOL_USE, 'Bash', ['name' => 'Bash', 'input' => ['command' => 'php artisan larapilot:spec-list --release=1.4'], 'parent_tool_use_id' => 'toolu_rel_1'], 'toolu_rel_1a');
        $note(Message::ROLE_ASSISTANT, Message::KIND_TEXT, '9 stories in 1.4: 3 done, 1 in review (US-011), 2 in progress (US-014, US-015), 1 planned, 2 to do (US-016, US-017).', ['parent_tool_use_id' => 'toolu_rel_1']);
        $note(Message::ROLE_TOOL, Message::KIND_TOOL_RESULT, '9 stories; 3 done, 1 review, 2 in progress, 1 planned, 2 todo', ['is_error' => false], 'toolu_rel_1');
        $note(Message::ROLE_ASSISTANT, Message::KIND_TOOL_USE, 'Task', ['name' => 'Task', 'input' => ['description' => 'Open branches and pull requests', 'subagent_type' => 'Explore']], 'toolu_rel_2');
        $note(Message::ROLE_ASSISTANT, Message::KIND_TOOL_USE, 'Bash', ['name' => 'Bash', 'input' => ['command' => 'gh pr list --state open'], 'parent_tool_use_id' => 'toolu_rel_2'], 'toolu_rel_2a');
        $note(Message::ROLE_ASSISTANT, Message::KIND_TOOL_USE, 'Bash', ['name' => 'Bash', 'input' => ['command' => 'git log develop..feature/US-015-returns --oneline'], 'parent_tool_use_id' => 'toolu_rel_2'], 'toolu_rel_2b');
        $note(Message::ROLE_ASSISTANT, Message::KIND_TEXT, 'Two open pull requests: #48 (US-011, approved, waiting for the merge) and #51 (US-015, tests failing on the returns form).', ['parent_tool_use_id' => 'toolu_rel_2']);
        $note(Message::ROLE_TOOL, Message::KIND_TOOL_RESULT, '#48 approved; #51 failing', ['is_error' => false], 'toolu_rel_2');
        $note(Message::ROLE_ASSISTANT, Message::KIND_TEXT, "## Release 1.4 · what is missing\n\n| Story | State | What is left |\n| --- | --- | --- |\n| US-011 · Returns badge | in review | merge #48 |\n| US-014 · Date range filter | in progress | assets and review |\n| US-015 · Returns form | in progress | fix the failing tests in #51 |\n| US-016 · Returns approval | to do | not started, 3 points |\n| US-017 · Weekly sales dashboard | to do | 5 points, could move to 1.5 |\n\n**Realistic plan:** merge #48 today, finish US-014 and US-015 this week, move US-017 to 1.5. Nothing was changed: this conversation is in *Plan only*.");

        // MCP servers in the chats of Shop Acme: the project's documentation for everyone,
        // error tracking for administrators and developers.
        ProjectMcpServer::query()->updateOrCreate(['project_id' => $shop->id, 'name' => 'docs'], [
            'url' => 'https://docs.shop-acme.test/mcp',
            'headers' => null,
            'roles' => ['admin', 'pm', 'dev', 'client'],
            'enabled' => true,
            'status' => 'ok · shop-acme-docs 1.2.0',
            'checked_at' => now()->subMinutes(12),
        ]);
        ProjectMcpServer::query()->updateOrCreate(['project_id' => $shop->id, 'name' => 'errors'], [
            'url' => 'https://errors.agency.test/mcp',
            'headers' => ['Authorization' => 'Bearer demo-not-a-real-token'],
            'roles' => ['admin', 'dev'],
            'enabled' => true,
            'status' => 'ok · errors 3.4.1',
            'checked_at' => now()->subMinutes(12),
        ]);

        $this->command->info('Demo ready: andrea@agency.test (admin), maria@agency.test (PM), luca@agency.test (dev), giulia@client.test (client). Password: password');
    }
}
