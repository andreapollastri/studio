<?php

namespace Database\Seeders;

use App\Backups\Retention;
use App\Databases\Driver;
use App\Databases\Environment;
use App\Enums\ClaudeAuthMode;
use App\Enums\ConversationStatus;
use App\Enums\DomainStatus;
use App\Enums\PermissionStatus;
use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Enums\WorkspaceStatus;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\BackupTarget;
use App\Models\ClaudeAccount;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSetting;
use App\Models\Conversation;
use App\Models\DatabaseConnection;
use App\Models\DatabaseGroup;
use App\Models\DatabasePreference;
use App\Models\DatabaseQuery;
use App\Models\DatabaseTransfer;
use App\Models\Domain;
use App\Models\Message;
use App\Models\PermissionRule;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\ProjectMcpServer;
use App\Models\ProjectPreference;
use App\Models\ProjectResource;
use App\Models\SshConnection;
use App\Models\SshKey;
use App\Models\User;
use App\Models\VaultFolder;
use App\Models\VaultItem;
use App\Models\Workspace;
use App\Ssh\Keys;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A believable agency: five projects (two of them called "API", for two
 * clients), four people with a role on each project, a service account that
 * deploys the sites, a Droplet attached to a project, one lively conversation.
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
            'name' => 'Andrea Pollastri', 'password' => 'password', 'role' => UserRole::Admin, 'handle' => 'andreapollastri', 'email_verified_at' => now(),
        ]);
        $maria = User::query()->updateOrCreate(['email' => 'maria@agency.test'], [
            'name' => 'Maria Rossi', 'password' => 'password', 'role' => UserRole::Pm, 'handle' => 'mariarossi', 'email_verified_at' => now(),
        ]);
        $luca = User::query()->updateOrCreate(['email' => 'luca@agency.test'], [
            'name' => 'Luca Bianchi', 'password' => 'password', 'role' => UserRole::Dev, 'handle' => 'lucabianchi', 'email_verified_at' => now(),
        ]);
        $giulia = User::query()->updateOrCreate(['email' => 'giulia@client.test'], [
            'name' => 'Giulia Conti', 'password' => 'password', 'role' => UserRole::Client, 'handle' => 'giuliaconti', 'email_verified_at' => now(),
        ]);

        // their accounts: a GitHub account each, Andrea also the agency's Azure DevOps; Claude subscriptions and a key
        $github = $this->gitAccount($andrea, 'github', 'andreapollastri', 'github.com');
        $this->gitAccount($andrea, 'azure', 'andrea@agency.test', 'dev.azure.com/agency', 'Agency DevOps', 'https://dev.azure.com/agency');
        $this->gitAccount($maria, 'github', 'mariarossi', 'github.com');
        $this->gitAccount($luca, 'github', 'lucabianchi', 'github.com');

        $personal = $this->claudeAccount($andrea, 'Personal Max', ClaudeAuthMode::Subscription, dir: '.claude');
        $team = $this->claudeAccount($andrea, 'Agency Team', ClaudeAuthMode::Subscription, dir: '.claude-agency');
        $this->claudeAccount($maria, 'Claude subscription', ClaudeAuthMode::Subscription, dir: '.claude');
        $this->claudeAccount($luca, 'API key', ClaudeAuthMode::ApiKey, token: 'sk-ant-demo');

        $work = ProjectGroup::query()->updateOrCreate(['user_id' => $andrea->id, 'name' => 'Clients'], ['color' => 'sky', 'position' => 1, 'claude_account_id' => $team->id]);
        ProjectGroup::query()->updateOrCreate(['user_id' => $andrea->id, 'name' => 'Personal'], ['color' => 'emerald', 'position' => 2, 'claude_account_id' => $personal->id, 'git_account_id' => $github->id]);

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

        // two clients, two projects named "API": each has a key of its own
        $api = Project::query()->firstOrCreate(['name' => 'API', 'repo_url' => 'https://github.com/agency/acme-api.git'], [
            'slug' => Project::newKey('API'), 'default_branch' => 'main', 'php_version' => '8.4', 'db_engine' => 'pgsql', 'deploy_user_id' => $andrea->id,
        ]);
        $otherApi = Project::query()->firstOrCreate(['name' => 'API', 'repo_url' => 'https://dev.azure.com/agency/Globex/_git/api'], [
            'slug' => Project::newKey('API'), 'default_branch' => 'develop', 'php_version' => '8.4', 'db_engine' => 'mariadb', 'deploy_user_id' => $andrea->id,
        ]);

        // Acme's own domain, online: the shop's site at staging.acme.test and its previews under it, open to the client; Globex's is on its way
        $ip = ['expected' => ['203.0.113.10']];
        $acme = Domain::query()->updateOrCreate(['name' => 'acme.test'], [
            'wildcard' => true, 'status' => DomainStatus::Verified, 'records' => [...$ip, 'name' => ['203.0.113.10'], 'wildcard' => ['203.0.113.10']],
            'checked_at' => now()->subHours(3), 'verified_at' => now()->subDays(12), 'user_id' => $andrea->id, 'online' => true,
        ]);
        Domain::query()->updateOrCreate(['name' => 'globex.test'], [
            'wildcard' => true, 'status' => DomainStatus::Pending, 'records' => [...$ip, 'name' => ['198.51.100.7'], 'wildcard' => []],
            'last_error' => 'globex.test points at 198.51.100.7, not at this server (203.0.113.10).', 'checked_at' => now()->subMinutes(20), 'user_id' => $andrea->id,
        ]);
        $shop->update(['site_host' => 'staging.acme.test', 'site_domain_id' => $acme->id, 'preview_domain_id' => $acme->id]);

        // the role is each project's: Luca develops the shop and is the PM of the loyalty app; Giulia is the client of the shop
        $shop->syncMembers([$maria->id => 'pm', $luca->id => 'dev', $giulia->id => 'client']);
        $portale->syncMembers([$maria->id => 'pm', $luca->id => 'dev']);
        $loyalty->syncMembers([$luca->id => 'pm']);
        $api->syncMembers([$luca->id => 'dev', $maria->id => 'dev']);
        $otherApi->syncMembers([$luca->id => 'dev']);

        // the team's own token deploys the sites, whoever the administrator is
        $bot = ConnectorAccount::query()->updateOrCreate(['user_id' => null, 'connector' => 'github', 'name' => 'Agency deploy bot'], [
            'login' => 'agency-deploy', 'host' => 'github.com', 'credentials' => ['token' => 'demo_token_bot'], 'status' => 'ok · agency-deploy', 'checked_at' => now(),
        ]);
        Project::query()->whereKey([$shop->id, $portale->id, $loyalty->id, $api->id])->update(['git_account_id' => $bot->id]);

        // the shop's production Droplet, in its Infrastructure tab: developers reboot it, the PM and the client look
        ConnectorSetting::query()->updateOrCreate(['connector' => 'digitalocean'], ['enabled' => true]);
        $cloud = ConnectorAccount::query()->updateOrCreate(['user_id' => null, 'connector' => 'digitalocean', 'name' => 'Agency'], [
            'credentials' => ['token' => 'dop_v1_demo'], 'status' => 'ok · agency@agency.test', 'checked_at' => now(),
        ]);
        ProjectResource::query()->updateOrCreate(['project_id' => $shop->id, 'connector_account_id' => $cloud->id, 'section' => 'droplets', 'resource_id' => '424242'], [
            'label' => 'Shop Acme · production', 'view_roles' => ['pm', 'dev', 'client'], 'act_roles' => ['dev'], 'actions' => ['reboot', 'snapshot'], 'added_by' => $andrea->id,
        ]);
        $this->operations($shop, $andrea);
        $this->databases($shop, $andrea, $luca);

        // Andrea's list: the clients' projects in their group, on the agency's Team plan
        foreach ([$shop, $portale] as $project) {
            ProjectPreference::query()->updateOrCreate(['user_id' => $andrea->id, 'project_id' => $project->id], ['project_group_id' => $work->id]);
        }

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
        $say(Message::ROLE_ASSISTANT, Message::KIND_TEXT, "Working on branch `feature/US-014-date-filter`. The first task is done: a `between()` scope on the Order model, the two fields in the form, and the test. Before resetting the workspace database with the demo orders I'm asking you, because `php artisan migrate:fresh --seed` wipes what is in it.");
        $say(Message::ROLE_ASSISTANT, Message::KIND_TOOL_USE, 'Edit', ['name' => 'Edit', 'input' => ['file_path' => 'app/Models/Order.php']], 'toolu_demo_3');
        $say(Message::ROLE_TOOL, Message::KIND_TOOL_RESULT, 'The file app/Models/Order.php has been updated.', ['is_error' => false], 'toolu_demo_3');

        $conversation->permissionRequests()->create([
            'request_id' => 'req_demo_1',
            'tool_name' => 'Bash',
            'description' => 'Reset the workspace database with the demo orders',
            'input' => ['command' => 'php artisan migrate:fresh --seed'],
            'suggestions' => ['Bash(php artisan migrate:fresh:*)'],
            'status' => PermissionStatus::Pending,
        ]);
        // one "Always allow" Maria gave on this project earlier
        PermissionRule::query()->firstOrCreate(['user_id' => $maria->id, 'project_id' => $mariaShop->project_id, 'rule' => 'Bash(composer require:*)']);

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

        // Andrea's password manager: the shop's folder opens to its PMs and developers, the agency's
        // tools are shared with Maria to edit; what is inside goes with each folder.
        $shopFolder = VaultFolder::query()->updateOrCreate(['user_id' => $andrea->id, 'name' => 'Shop Acme', 'parent_id' => null], ['project_id' => $shop->id, 'project_roles' => ['pm', 'dev']]);
        $production = VaultFolder::query()->updateOrCreate(['user_id' => $andrea->id, 'name' => 'Production', 'parent_id' => $shopFolder->id], []);
        $tools = VaultFolder::query()->updateOrCreate(['user_id' => $andrea->id, 'name' => 'Agency tools', 'parent_id' => null], []);
        $tools->sharedWith()->syncWithoutDetaching([$maria->id => ['can_edit' => true]]);
        foreach ([
            [$shopFolder, 'Back office admin', 'https://shop-acme.dev.agency.test/admin', 'admin@shop-acme.test'],
            [$shopFolder, 'Stripe dashboard', 'https://dashboard.stripe.com', 'billing@shop-acme.test'],
            [$production, 'Production database', 'mysql://10.0.0.12', 'shop_acme'],
            [$production, 'Production server SSH', 'ssh://203.0.113.42', 'deploy'],
            [$tools, 'Figma', 'https://figma.com', 'design@agency.test'],
            [$tools, 'Mailgun', 'https://app.mailgun.com', 'ops@agency.test'],
        ] as [$in, $title, $url, $username]) {
            VaultItem::query()->updateOrCreate(['user_id' => $andrea->id, 'title' => $title], [
                'vault_folder_id' => $in->id, 'url' => $url, 'username' => $username, 'password' => 'demo-'.substr(md5($title), 0, 12), 'updated_by' => $andrea->id,
            ]);
        }

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

        $this->command->info('Demo ready: andrea@agency.test (admin), maria@agency.test (PM of the shop), luca@agency.test (developer here, PM there), giulia@client.test (client of the shop). Password: password');
    }

    /**
     * Tools → Databases: the shop's database as a real SQLite file next to the demo's own (so the
     * client shows rows), the agency's other databases in Andrea's coloured groups, a query
     * history, a saved query and a finished export.
     */
    private function databases(Project $shop, User $andrea, User $luca): void
    {
        // a short path, as a server's would be: /tmp/studio-demo/shop-acme.sqlite
        $dir = is_writable('/tmp') ? '/tmp/studio-demo' : sys_get_temp_dir().'/studio-demo';
        File::ensureDirectoryExists($dir);
        $file = $dir.'/shop-acme.sqlite';
        @unlink($file);

        $pdo = new \PDO('sqlite:'.$file);
        $pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, country CHAR(2), vip BOOLEAN NOT NULL DEFAULT 0, notes TEXT, created_at DATETIME)');
        $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, sku VARCHAR(32) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL, price DECIMAL(10,2) NOT NULL, stock INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE, status VARCHAR(20) NOT NULL DEFAULT \'pending\', total DECIMAL(10,2) NOT NULL, placed_at DATETIME)');
        $pdo->exec('CREATE INDEX orders_status_index ON orders (status)');
        $pdo->exec('CREATE INDEX orders_customer_id_index ON orders (customer_id)');
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(255) NOT NULL, batch INTEGER NOT NULL)');
        $pdo->exec("CREATE VIEW paid_orders AS SELECT o.id, c.name, o.total, o.placed_at FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.status = 'paid'");

        $people = ['Giulia Conti', 'Marco Ferri', 'Sofia Greco', 'Luca Marino', 'Elena Bruno', 'Paolo Gallo', 'Chiara Costa', 'Davide Fontana', 'Sara Moretti', 'Matteo Rizzo', 'Anna Lombardi', 'Simone Barbieri', 'Laura Romano', 'Andrea Colombo', 'Francesca Ricci', 'Giorgio Esposito', 'Valentina De Luca', 'Federico Mancini', 'Martina Villa', 'Alessandro Serra'];
        $insert = $pdo->prepare('INSERT INTO customers (name, email, country, vip, notes, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($people as $i => $name) {
            $insert->execute([$name, Str::slug($name, '.').'@example.com', ['IT', 'IT', 'DE', 'FR', 'IT', 'ES'][$i % 6], (int) ($i % 5 === 0), $i % 4 === 0 ? 'Prefers deliveries after 18:00' : null, now()->subDays(120 - $i * 5)->format('Y-m-d H:i:s')]);
        }
        $insert = $pdo->prepare('INSERT INTO products (sku, name, price, stock) VALUES (?, ?, ?, ?)');
        foreach ([['CH-OAK', 'Oak chair', 149.00, 32], ['TB-WAL', 'Walnut table', 890.00, 6], ['LM-BRS', 'Brass lamp', 79.90, 54], ['SH-PIN', 'Pine shelf', 129.00, 18], ['RG-WOL', 'Wool rug', 340.00, 9], ['CS-LIN', 'Linen cushion', 39.00, 120]] as $product) {
            $insert->execute($product);
        }
        $insert = $pdo->prepare('INSERT INTO orders (customer_id, status, total, placed_at) VALUES (?, ?, ?, ?)');
        for ($i = 0; $i < 64; $i++) {
            $insert->execute([($i * 7) % 20 + 1, ['paid', 'paid', 'shipped', 'pending', 'paid', 'refunded'][$i % 6], round(39 + ($i * 37.3) % 900, 2), now()->subHours(6 + $i * 9)->format('Y-m-d H:i:s')]);
        }
        foreach (['0001_01_01_000000_create_users_table', '2026_09_01_000001_create_customers_table', '2026_09_01_000002_create_products_table', '2026_09_02_000001_create_orders_table'] as $i => $migration) {
            $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)')->execute([$migration, $i < 1 ? 1 : 2]);
        }
        unset($pdo);

        $groups = collect([['Clients', 'violet'], ['Production', 'red'], ['Agency', 'sky']])->mapWithKeys(fn (array $g, int $i) => [$g[0] => DatabaseGroup::query()->updateOrCreate(['user_id' => $andrea->id, 'name' => $g[0]], ['color' => $g[1], 'position' => $i + 1])]);
        $ssh = SshConnection::query()->where('user_id', $andrea->id)->where('name', 'Shop Acme · Cipi')->first();

        $connections = [
            ['Shop Acme · local copy', Driver::Sqlite, Environment::Development, ['database' => $file], 'Clients', true, ['dev' => 'write', 'pm' => 'read']],
            ['Shop Acme · production', Driver::Mariadb, Environment::Production, ['host' => '127.0.0.1', 'port' => 3306, 'username' => 'shopacme', 'password' => 'demo', 'database' => 'shopacme', 'ssh_connection_id' => $ssh?->id], 'Production', true, ['dev' => 'read']],
            ['Shop Acme · cache', Driver::Redis, Environment::Production, ['host' => '127.0.0.1', 'port' => 6379, 'database' => '0', 'ssh_connection_id' => $ssh?->id], 'Production', false, ['dev' => 'read']],
            ['Shop Acme · staging', Driver::Mysql, Environment::Staging, ['host' => 'db.staging.agency.test', 'port' => 3306, 'username' => 'shop', 'password' => 'demo', 'database' => 'shop_staging'], 'Clients', false, ['dev' => 'write', 'pm' => 'read']],
            ['Analytics warehouse', Driver::Pgsql, Environment::Production, ['host' => 'warehouse.agency.test', 'port' => 5432, 'username' => 'analyst', 'password' => 'demo', 'database' => 'analytics', 'tls' => true], 'Agency', false, null],
            ['Sessions', Driver::Valkey, Environment::Testing, ['host' => '10.0.0.21', 'port' => 6379, 'password' => 'demo', 'database' => '1'], 'Agency', false, null],
        ];

        foreach ($connections as [$name, $driver, $environment, $attributes, $group, $favorite, $access]) {
            $connection = DatabaseConnection::query()->updateOrCreate(['user_id' => $andrea->id, 'name' => $name], [
                'driver' => $driver, 'environment' => $environment, 'host' => null, 'port' => null, 'username' => null, 'password' => null, 'database' => null, 'tls' => false, 'ssh_connection_id' => null,
                ...$attributes,
                'project_id' => $access !== null ? $shop->id : null,
                'project_access' => $access,
                'last_used_at' => now()->subMinutes(30),
            ]);
            DatabasePreference::query()->updateOrCreate(['user_id' => $andrea->id, 'database_connection_id' => $connection->id], ['database_group_id' => $groups[$group]->id, 'favorite' => $favorite]);
        }

        $local = DatabaseConnection::query()->where('user_id', $andrea->id)->where('name', 'Shop Acme · local copy')->firstOrFail();
        $local->queries()->delete();
        foreach ([
            ["SELECT status, COUNT(*) AS orders, SUM(total) AS revenue\nFROM orders\nGROUP BY status\nORDER BY revenue DESC;", 'ok', 4, null, null],
            ['UPDATE customers SET vip = 1 WHERE id IN (3, 8);', 'ok', 6, null, null],
            ['SELECT * FROM order_items;', 'error', 1, 'no such table: order_items', null],
            ["SELECT c.name, COUNT(o.id) AS orders, SUM(o.total) AS spent\nFROM customers c JOIN orders o ON o.customer_id = c.id\nWHERE o.status = 'paid'\nGROUP BY c.id ORDER BY spent DESC LIMIT 10;", 'ok', 3, null, 'Best customers'],
        ] as $i => [$sql, $status, $ms, $error, $title]) {
            DatabaseQuery::query()->create(['database_connection_id' => $local->id, 'user_id' => $andrea->id, 'database' => 'main', 'sql' => $sql, 'status' => $status, 'duration_ms' => $ms, 'error' => $error, 'title' => $title, 'saved' => $title !== null, 'created_at' => now()->subMinutes(40 - $i * 9)]);
        }

        $local->transfers()->delete();
        $transfer = DatabaseTransfer::query()->create([
            'database_connection_id' => $local->id, 'user_id' => $andrea->id, 'kind' => 'export', 'format' => 'sql', 'scope' => ['database' => 'main', 'tables' => [], 'structure' => true, 'data' => true, 'drop' => true, 'gzip' => true],
            'status' => 'success', 'started_at' => now()->subMinutes(18), 'finished_at' => now()->subMinutes(18), 'expires_at' => now()->addDays(7), 'rows' => 94, 'bytes' => 6214,
            'output' => "[10:42:01] Dumping with sqlite3.\n[10:42:01] Ready: shop-acme-2026-10-14_104201.sql.gz, 6.1 KB.\n",
        ]);
        $relative = DatabaseTransfer::DIR.'/'.$transfer->uuid.'/shop-acme-'.now()->format('Y-m-d_His').'.sql.gz';
        Storage::disk('local')->put($relative, (string) gzencode("-- demo dump\n"));
        $transfer->forceFill(['file_path' => $relative, 'file_name' => basename($relative)])->save();
        DatabaseTransfer::query()->create([
            'database_connection_id' => $local->id, 'user_id' => $luca->id, 'kind' => 'import', 'format' => 'csv', 'scope' => ['database' => 'main', 'table' => 'products', 'header' => true],
            'status' => 'success', 'started_at' => now()->subHours(3), 'finished_at' => now()->subHours(3), 'rows' => 6, 'file_name' => 'products.csv', 'bytes' => 412,
            'output' => "[07:58:12] Running the rows into products.\n[07:58:12] 6 rows imported into products\n",
        ]);
    }

    private function gitAccount(User $user, string $connector, string $login, string $host, ?string $name = null, ?string $url = null): ConnectorAccount
    {
        return ConnectorAccount::query()->updateOrCreate(['user_id' => $user->id, 'connector' => $connector, 'name' => $name ?? $login], [
            'login' => $login,
            'host' => $host,
            'git_name' => $user->name,
            'git_email' => $user->email,
            'credentials' => array_filter(['token' => 'demo_token_'.$login, 'url' => $url]),
            'status' => 'ok · '.$login,
            'checked_at' => now(),
        ]);
    }

    private function claudeAccount(User $user, string $name, ClaudeAuthMode $kind, ?string $dir = null, ?string $token = null): ClaudeAccount
    {
        $account = ClaudeAccount::query()->updateOrCreate(['user_id' => $user->id, 'name' => $name], [
            'kind' => $kind,
            'dir' => $dir,
            'token' => $token,
            'signed_in' => $kind === ClaudeAuthMode::Subscription,
            'checked_at' => now(),
        ]);

        if ($user->claude_account_id === null) {
            $user->forceFill(['claude_account_id' => $account->id])->save();
        }

        return $account;
    }

    /**
     * The rest of the shop's infrastructure (its Cipi app with the agent, its zone, its exception
     * tracker, its bucket) and its backups, with a few weeks of runs.
     */
    private function operations(Project $shop, User $andrea): void
    {
        $account = function (string $connector, string $name, array $credentials, string $status) {
            ConnectorSetting::query()->updateOrCreate(['connector' => $connector], ['enabled' => true]);

            return ConnectorAccount::query()->updateOrCreate(['user_id' => null, 'connector' => $connector, 'name' => $name], [
                'credentials' => $credentials, 'status' => $status, 'checked_at' => now(),
            ]);
        };
        $attach = fn (ConnectorAccount $account, string $section, string $id, string $label, array $actions) => ProjectResource::query()->updateOrCreate(
            ['project_id' => $shop->id, 'connector_account_id' => $account->id, 'section' => $section, 'resource_id' => $id],
            ['label' => $label, 'view_roles' => ['pm', 'dev'], 'act_roles' => ['dev'], 'actions' => $actions, 'added_by' => $andrea->id],
        );

        $cipi = $account('cipi', 'Web 1', ['url' => 'https://api.web1.agency.test', 'token' => 'demo-cipi-token'], 'ok · web1 · Cipi 5.6');
        $attach($cipi, 'apps', 'shopacme', 'Shop Acme · Cipi', ['deploy', 'rollback', 'releases', 'audit', 'logs', 'env', 'env-set', 'disk', 'anonymize', 'anonymizer-audit']);
        $attach($account('cloudflare', 'Agency', ['token' => 'demo-cf-token'], 'ok · Agency'), 'zones', 'zone-acme', 'acme.test', ['purge']);
        $attach($account('boogle', 'Boogle', ['url' => 'https://boogle.agency.test', 'token' => 'demo-boogle'], 'ok · 6 projects'), 'projects', '12', 'Shop Acme · errors', []);
        $johnny = $account('johnny', 'Johnny EU', ['url' => 'https://panel.johnny.agency.test', 'token' => 'demo-johnny'], 'ok · 4 buckets');
        $attach($johnny, 'buckets', 'shop-acme-media', 'Shop Acme · media', ['details']);

        // the app's user on its server, for the remote terminal; a system key for the backups, shared with the shop
        SshConnection::query()->updateOrCreate(['user_id' => $andrea->id, 'project_id' => $shop->id, 'name' => 'Shop Acme · Cipi'], [
            'host' => '203.0.113.9', 'port' => 22, 'username' => 'shopacme', 'auth' => 'password', 'password' => 'demo', 'host_key' => 'SHA256:'.str_repeat('q', 43),
        ]);
        $key = SshKey::query()->updateOrCreate(['user_id' => null, 'name' => 'backups'], Keys::generate('ed25519', 'studio@dev.agency.test'));
        $shop->sshKeys()->syncWithoutDetaching([$key->id => ['developers' => false]]);

        $server = BackupTarget::query()->updateOrCreate(['name' => 'web1 · shopacme'], [
            'kind' => 'ssh', 'project_id' => $shop->id, 'user_id' => $andrea->id, 'status' => 'ok · Linux 6.8.0-45-generic · 61G free', 'checked_at' => now()->subHours(2),
            'config' => ['host' => '203.0.113.9', 'port' => 22, 'username' => 'shopacme', 'ssh_key_id' => $key->id, 'known_hosts' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl'], 'fingerprint' => 'SHA256:2tDz9hY7m0d4c3l8s5Qk1nVb6wXr0pF3eGa9uJ7iK2o'],
        ]);
        $bucket = BackupTarget::query()->updateOrCreate(['name' => 'Johnny · agency-backups'], [
            'kind' => 's3', 'user_id' => $andrea->id, 'status' => 'ok · 14 items at the top', 'checked_at' => now()->subHours(2),
            'config' => ['provider' => 'johnny', 'endpoint' => 'https://s3.johnny.agency.test', 'region' => 'garage', 'bucket' => 'agency-backups', 'access_key_id' => 'GKdemo', 'secret_access_key' => 'demo', 'path_style' => true],
        ]);
        $media = BackupTarget::query()->updateOrCreate(['name' => 'Johnny · shop-acme-media'], [
            'kind' => 's3', 'project_id' => $shop->id, 'user_id' => $andrea->id, 'status' => 'ok · 3 items at the top', 'checked_at' => now()->subHours(2),
            'config' => ['provider' => 'johnny', 'endpoint' => 'https://s3.johnny.agency.test', 'region' => 'garage', 'bucket' => 'shop-acme-media', 'access_key_id' => 'GKmedia', 'secret_access_key' => 'demo', 'path_style' => true],
        ]);
        $offsite = BackupTarget::query()->updateOrCreate(['name' => 'AWS · agency-offsite'], [
            'kind' => 's3', 'user_id' => $andrea->id, 'status' => 'ok · 2 items at the top', 'checked_at' => now()->subHours(5),
            'config' => ['provider' => 'aws', 'endpoint' => '', 'region' => 'eu-central-1', 'bucket' => 'agency-offsite', 'access_key_id' => 'AKIADEMO', 'secret_access_key' => 'demo', 'path_style' => false],
        ]);

        $job = function (array $attributes, array $schedules, int $days, int $bytes, ?string $failed = null) use ($andrea) {
            $job = BackupJob::query()->updateOrCreate(['name' => $attributes['name']], [...$attributes, 'timezone' => 'Europe/Rome', 'user_id' => $andrea->id]);
            $job->schedules()->delete();

            foreach ($schedules as $schedule) {
                $job->schedules()->create($schedule + ['last_dispatched_at' => now()->subHours(8)]);
            }

            $job->runs()->delete();

            for ($day = $days; $day >= 0; $day--) {
                $at = now()->subDays($day)->setTime(3, 0);
                $status = $day === 0 && $failed ? 'failed' : 'success';
                $job->runs()->create([
                    'trigger' => 'schedule', 'status' => $status, 'started_at' => $at, 'finished_at' => $at->copy()->addSeconds(70 + $day * 3),
                    'destination_id' => $job->destination_id,
                    'path' => match ($job->mode) {
                        'archive' => $job->destination_path.'/'.Str::slug($job->name).'-'.$at->format('Y-m-d_His').'.tar.gz',
                        'snapshot' => $job->destination_path.'/'.$at->format('Y-m-d_His'),
                        default => $job->destination_path,
                    },
                    'bytes' => $status === 'success' ? $bytes + $day * 1_048_576 : null,
                    'files' => $status === 'success' ? ($job->mode === 'archive' ? 1 : 1240 + $day) : null,
                    'error' => $status === 'failed' ? $failed : null,
                    'output' => $status === 'failed' ? $failed : "Archiving shopacme:current\nTransferred: 412.5 MiB / 412.5 MiB, 100%, 38 MiB/s\n",
                ]);
            }

            // what the retention keeps and what it pruned, as it would have after each run
            if ($job->keepsVersions()) {
                $runs = $job->runs()->where('status', 'success')->get()->keyBy('id');
                $keep = Retention::keep($runs->map(fn (BackupRun $run) => $run->started_at)->all(), $job->retentionPolicy(), $job->timezone);

                foreach ($runs as $id => $run) {
                    $run->update(isset($keep[$id]) ? ['kept_by' => $keep[$id]] : ['pruned_at' => $run->started_at->copy()->addDays(8)]);
                }
            }

            $job->update(['last_run_at' => now()->setTime(3, 0), 'last_status' => $failed ? 'failed' : 'success']);
        };

        $job(['name' => 'Shop Acme · site and database', 'project_id' => $shop->id, 'source_id' => $server->id, 'source_path' => 'current', 'destination_id' => $bucket->id, 'destination_path' => 'shop-acme', 'mode' => 'archive',
            'options' => ['pre_command' => 'mysqldump --single-transaction shopacme > ~/current/storage/app/db.sql', 'excludes' => ['storage/logs', 'node_modules']],
            'retention' => ['last' => 3, 'daily' => 7, 'weekly' => 5, 'monthly' => 12, 'yearly' => 2]],
            [['frequency' => 'daily', 'hour' => 2, 'minute' => 30], ['frequency' => 'weekly', 'hour' => 4, 'minute' => 0, 'weekdays' => [0]], ['frequency' => 'monthly', 'hour' => 5, 'minute' => 0, 'monthday' => 1]],
            30, 432_013_312);
        $job(['name' => 'Shop Acme · media to AWS', 'project_id' => $shop->id, 'source_id' => $media->id, 'source_path' => '', 'destination_id' => $offsite->id, 'destination_path' => 'shop-acme-media', 'mode' => 'mirror'],
            [['frequency' => 'hourly', 'minute' => 15]], 3, 2_147_483_648, 'ERROR : uploads/2026/10/banner.jpg: Failed to copy: AccessDenied: Access Denied');
        $job(['name' => 'Agency backups · off-site copy', 'source_id' => $bucket->id, 'source_path' => '', 'destination_id' => $offsite->id, 'destination_path' => 'johnny', 'mode' => 'snapshot',
            'retention' => ['last' => 2, 'daily' => 0, 'weekly' => 4, 'monthly' => 6, 'yearly' => 0]],
            [['frequency' => 'weekly', 'hour' => 23, 'minute' => 0, 'weekdays' => [6]], ['frequency' => 'cron', 'cron' => '0 12 1 * *']], 14, 9_663_676_416);
    }
}
