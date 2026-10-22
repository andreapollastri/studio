<?php

use App\Bridge\BridgeClient;
use App\Infrastructure\Cipi\Anonymization;
use App\Livewire\Projects\CipiAppHub;
use App\Livewire\Projects\Infrastructure as ProjectInfrastructure;
use App\Livewire\Projects\ResourceCard;
use App\Models\ConnectorAccount;
use App\Models\ConnectorSetting;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\ProjectResource;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** A Cipi server with the app "shop", the Cipi agent in it with its MCP server and anonymizer on. */
function cipiServer(array $env = []): ConnectorAccount
{
    ConnectorSetting::query()->updateOrCreate(['connector' => 'cipi'], ['enabled' => true]);

    $app = ['app' => 'shop', 'domain' => 'shop.example.com', 'type' => 'laravel', 'php' => '8.4', 'branch' => 'main', 'suspended' => false, 'force_https' => true];
    $env = [
        'APP_URL' => 'https://shop.example.com',
        'CIPI_APP_USER' => 'shop',
        'CIPI_MCP' => 'true',
        'CIPI_MCP_TOKEN' => 'mcp-secret',
        'CIPI_ANONYMIZER' => 'true',
        'CIPI_ANONYMIZER_TOKEN' => 'anon-secret',
        'DB_PASSWORD' => 'db-secret',
        ...$env,
    ];

    Http::fake([
        'api.cipi.test/api/apps/shop/env' => Http::response(['data' => ['app' => 'shop', 'vars' => $env]]),
        'api.cipi.test/api/apps/shop/deploy/audit*' => Http::response(['data' => ['app' => 'shop', 'records' => [
            ['ts' => '2026-10-10T10:00:00Z', 'event' => 'published', 'release' => 11, 'commit' => 'aaaaaaa1111', 'trigger' => 'webhook'],
            ['ts' => '2026-10-12T10:00:00Z', 'event' => 'published', 'release' => 12, 'commit' => 'bbbbbbb2222', 'trigger' => 'api'],
        ]]]),
        'api.cipi.test/api/apps/shop/deploy/rollback' => Http::response(['job_id' => 'job-rb', 'status' => 'pending'], 202),
        'api.cipi.test/api/apps/shop/deploy' => Http::response(['job_id' => 'job-1', 'status' => 'pending'], 202),
        'api.cipi.test/api/apps/shop/logs*' => Http::response(['data' => ['app' => 'shop', 'type' => 'laravel', 'available_types' => ['laravel'], 'files' => [
            ['path' => '/home/shop/shared/storage/logs/laravel.log', 'total_lines' => 2, 'page' => 1, 'total_pages' => 1, 'lines' => ['[2026-10-12] production.ERROR: Boom', '[2026-10-12] production.INFO: fine']],
        ]]]),
        'api.cipi.test/api/apps/shop' => Http::response(['data' => $app]),
        'api.cipi.test/api/apps' => Http::response(['data' => [$app, ['app' => 'crm', 'domain' => 'crm.example.com']]]),
        'api.cipi.test/api/status' => Http::response(['data' => ['system' => ['ip' => '203.0.113.9', 'hostname' => 'web1', 'cipi' => '5.6'], 'resources' => ['cpu' => ['usage_percent' => 12], 'memory' => ['usage_percent' => 64], 'disk' => ['usage_percent' => 93]], 'services' => ['nginx' => 'running', 'redis' => 'stopped']]]),
        'api.cipi.test/api/disk' => Http::response(['data' => ['disk' => ['used_percent' => 93], 'apps' => [['app' => 'shop', 'files_gb' => 1.2, 'database_gb' => 0.3, 'limit_gb' => 10, 'limit_percent' => 15, 'over_limit' => false]]]]),
        'api.cipi.test/api/jobs/job-1' => Http::response(['data' => ['id' => 'job-1', 'type' => 'app-deploy', 'status' => 'completed', 'output' => 'Deployed release 13', 'result' => ['deployed' => true]]]),
        'shop.example.com/cipi/mcp' => function (Request $request) {
            $request = json_decode($request->body(), true);

            return match ($request['method']) {
                'initialize' => Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => ['serverInfo' => ['name' => 'cipi-agent', 'version' => '2.0.0']]]),
                'tools/list' => Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => ['tools' => [['name' => 'health'], ['name' => 'deploy_status'], ['name' => 'anonymizer']]]]),
                'tools/call' => Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => ['content' => [['type' => 'text', 'text' => json_encode(match ($request['params']['name']) {
                    'deploy_status' => ['release' => '12', 'commit' => 'bbbbbbb2222', 'deploy_branch' => 'main', 'pending' => false, 'in_progress' => false, 'releases' => [
                        ['name' => '12', 'commit' => 'bbbbbbb2222', 'current' => true, 'created_at' => '2026-10-12T10:00:00Z'],
                        ['name' => '11', 'commit' => 'aaaaaaa1111', 'current' => false, 'created_at' => '2026-10-10T10:00:00Z'],
                    ]],
                    'anonymizer' => $request['params']['arguments']['action'] === 'scan'
                        ? ['transformations' => ['customers' => ['email' => ['type' => 'fakeEmail', 'unique' => true], 'phone' => 'mask:3,2']], 'truncate' => ['sessions']]
                        : ['ok' => true, 'uncovered' => ['customers.notes']],
                    default => [],
                })]]]]),
                default => Http::response('', 202),
            };
        },
        'shop.example.com/cipi/db' => Http::response(['status' => 'queued', 'email' => 'ada@example.com', 'link_valid_minutes' => 15]),
    ]);

    return ConnectorAccount::factory()->of('cipi', ['url' => 'https://api.cipi.test', 'token' => 'cipi-api-token'])->create(['name' => 'Web 1']);
}

function attachShop(Project $project, ConnectorAccount $account, array $attributes = []): ProjectResource
{
    return ProjectResource::query()->create([
        'project_id' => $project->id,
        'connector_account_id' => $account->id,
        'section' => 'apps',
        'resource_id' => 'shop',
        'label' => 'Shop production',
        'view_roles' => ['pm', 'dev'],
        'act_roles' => ['dev'],
        'actions' => ['deploy', 'rollback', 'logs', 'releases', 'anonymize'],
        ...$attributes,
    ]);
}

test('a Cipi app in a project gives its agent\'s MCP server to those who act on it, and the server\'s when turned on', function () {
    $account = cipiServer();
    $project = Project::factory()->create();
    [$dev, $pm] = [User::factory()->create(), User::factory()->create()];
    $project->syncMembers([$dev->id => 'dev', $pm->id => 'pm']);
    $resource = attachShop($project, $account);

    $devChat = Conversation::factory()->forWorkspace(Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $dev->id]))->create();
    $pmChat = Conversation::factory()->forWorkspace(Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $pm->id, 'callback_token_hash' => hash('sha256', 'pm-token')]))->create();

    expect((new BridgeClient($devChat->workspace))->mcpServers($devChat))->toBe([
        'cipi-shop' => ['type' => 'http', 'url' => 'https://shop.example.com/cipi/mcp', 'headers' => ['Authorization' => 'Bearer mcp-secret']],
    ])->and((new BridgeClient($pmChat->workspace))->mcpServers($pmChat))->toBeNull();

    $resource->update(['settings' => ['mcp_server' => true]]);

    expect((new BridgeClient($devChat->workspace))->mcpServers($devChat)['cipi-server-web-1'])
        ->toBe(['type' => 'http', 'url' => 'https://api.cipi.test/mcp', 'headers' => ['Authorization' => 'Bearer cipi-api-token']]);
});

test('the agent\'s MCP server stays out while it is off in the app', function () {
    $account = cipiServer(['CIPI_MCP' => 'false']);
    $project = Project::factory()->create();
    $dev = User::factory()->create();
    $project->syncMembers([$dev->id => 'dev']);
    attachShop($project, $account);
    $chat = Conversation::factory()->forWorkspace(Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $dev->id]))->create();

    expect((new BridgeClient($chat->workspace))->mcpServers($chat))->toBeNull();
});

test('the hub shows the releases from the agent and deploys and rolls back for the roles allowed', function () {
    $account = cipiServer();
    $project = Project::factory()->create();
    [$dev, $client] = [User::factory()->create(), User::factory()->create()];
    $project->syncMembers([$dev->id => 'dev', $client->id => 'client']);
    $resource = attachShop($project, $account, ['view_roles' => ['dev', 'client']]);

    Livewire::actingAs($dev)->test(CipiAppHub::class, ['resource' => $resource->uuid])
        ->assertSee('Releases on the server')
        ->assertSee('#12')->assertSee('live')
        ->assertSee('Roll back here')
        ->assertSee('Server memory')
        ->call('deploy')
        ->assertSet('job.id', 'job-1')
        ->call('pollJob')
        ->assertSet('job.status', 'completed')
        ->assertSee('Deployed release 13')
        ->call('setTab', 'logs')
        ->assertSee('production.ERROR: Boom')
        ->set('logSearch', 'info')
        ->assertDontSee('production.ERROR: Boom')
        ->call('setTab', 'env')
        ->assertSet('tab', 'deploys');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.cipi.test/api/apps/shop/deploy' && $r->method() === 'POST');

    // a client sees the app, not its releases, and runs nothing
    Livewire::actingAs($client)->test(CipiAppHub::class, ['resource' => $resource->uuid])
        ->assertSee('Your role does not see the releases')
        ->assertDontSee('Roll back here')
        ->call('deploy')
        ->assertForbidden();
});

test('a developer asks for an anonymized copy of the database, mailed by the app', function () {
    $account = cipiServer();
    $project = Project::factory()->create();
    $dev = User::factory()->create(['email' => 'ada@example.com']);
    $project->syncMembers([$dev->id => 'dev']);
    $resource = attachShop($project, $account);

    Livewire::actingAs($dev)->test(CipiAppHub::class, ['resource' => $resource->uuid])
        ->call('setTab', 'database')
        ->assertSee('Anonymized copy of the database')
        ->assertDontSee('The anonymization file')
        ->call('requestAnonymizedDump')
        ->assertHasNoErrors();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://shop.example.com/cipi/db'
        && $r->hasHeader('Authorization', 'Bearer anon-secret')
        && $r['email'] === 'ada@example.com' && $r['gzip'] === true);
});

test('the anonymization file is proposed from the schema, edited and written back as JSON', function () {
    $account = cipiServer();
    $project = Project::factory()->create();
    $admin = User::factory()->admin()->create();
    $resource = attachShop($project, $account);

    $page = Livewire::actingAs($admin)->test(CipiAppHub::class, ['resource' => $resource->uuid])
        ->call('setTab', 'database')
        ->call('loadAnonymizer', 'scan')
        ->assertSet('anonRules.0.table', 'customers')
        ->assertSet('anonRules.1.rule', 'mask:3,2')
        ->call('addAnonRule')
        ->set('anonRules.2.column', 'notes')
        ->set('anonRules.2.rule', 'fakeParagraph')
        ->call('auditAnonymizer')
        ->assertSee('customers.notes');

    $json = json_decode($page->instance()->anonymizationJson(), true);

    expect($json['transformations']['customers'])->toBe([
        'email' => ['type' => 'fakeEmail', 'unique' => true],
        'phone' => 'mask:3,2',
        'notes' => 'fakeParagraph',
    ])->and($json['truncate'])->toBe(['sessions'])
        ->and($json['$schema'])->toBe(Anonymization::SCHEMA);

    $page->call('downloadAnonymizer')->assertFileDownloaded('anonymization.json');

    // writing it needs a connection of the project as the app's user
    $page->call('saveAnonymizer')->assertHasErrors('anonConnection');
});

test('the overview shows each attached resource at a glance, the hub opens for a Cipi app', function () {
    $account = cipiServer();
    $project = Project::factory()->create();
    $admin = User::factory()->admin()->create();
    $resource = attachShop($project, $account);

    Livewire::actingAs($admin)->test(ProjectInfrastructure::class, ['project' => $project])
        ->assertSet('resource', 'overview')
        ->assertSee('Overview')
        ->assertSee('cipi-shop')
        ->call('open', $resource->uuid)
        ->assertSeeLivewire(CipiAppHub::class);

    Livewire::withoutLazyLoading()->actingAs($admin)->test(ResourceCard::class, ['resource' => $resource->uuid])
        ->assertSee('shop.example.com')
        ->assertSee('Stopped on the server: redis')
        ->assertSee('MCP on');
});

test('an administrator attaches a Cipi app from the project itself', function () {
    $account = cipiServer();
    $project = Project::factory()->create();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(ProjectInfrastructure::class, ['project' => $project])
        ->call('startAttach')
        ->set('attachAccount', $account->uuid)
        ->assertSet('attachSection', 'apps')
        ->assertSee('crm.example.com')
        ->call('pickAttachRow', 'shop')
        ->assertSet('attach_label', 'shop')
        ->call('safeActions')
        ->call('saveAttach')
        ->assertHasNoErrors();

    $resource = ProjectResource::query()->sole();
    expect($resource->resource_id)->toBe('shop')
        ->and($resource->actions)->toContain('deploy')->toContain('anonymize')->not->toContain('delete');
});

test('administrators turn the agent\'s servers on and off and type tokens the API hides', function () {
    $account = cipiServer(['CIPI_MCP_TOKEN' => '********']);
    $project = Project::factory()->create();
    $admin = User::factory()->admin()->create();
    $resource = attachShop($project, $account);

    Livewire::actingAs($admin)->test(CipiAppHub::class, ['resource' => $resource->uuid])
        ->call('setTab', 'agent')
        ->assertSee('No MCP token')
        ->set('mcpToken', 'typed-token')
        ->set('mcpServer', true)
        ->call('saveAgent')
        ->assertHasNoErrors()
        ->call('testAgent');

    $resource->refresh();
    expect($resource->setting('mcp_token'))->toBe('typed-token')
        ->and($resource->setting('mcp_server'))->toBeTrue();

    $dev = User::factory()->create();
    $project->syncMembers([$dev->id => 'dev']);
    $chat = Conversation::factory()->forWorkspace(Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $dev->id]))->create();
    expect((new BridgeClient($chat->workspace))->mcpServers($chat)['cipi-shop']['headers'])->toBe(['Authorization' => 'Bearer typed-token']);
});

/** The Apply cipi.yml button, disabled (an attribute, not the disabled: classes). */
const APPLY_DISABLED = '/<button(?=[^>]*wire:click="applyYml")(?=[^>]*\\sdisabled[\\s=>])[^>]*>/';

/** The plan GET /api/apps/shop/yml answers (cipi/api 1.35), with what the test changes in it. */
function ymlPlan(array $data = []): array
{
    return ['data' => [
        'app' => 'shop',
        'file' => '/home/shop/current/cipi.yml',
        'auto' => false,
        'valid' => true,
        'in_sync' => false,
        'errors' => [],
        'warnings' => ['healthcheck path /up answers 404 today'],
        'changes' => [['kind' => 'php', 'summary' => 'PHP 8.3 → 8.4'], ['kind' => 'alias-add', 'summary' => 'add alias www.shop.test']],
        'blockers' => [],
        'notes' => ['node settings change on the next deploy, before the build:'],
        ...$data,
    ]];
}

test('the cipi.yml tab shows the plan, applies it as a job with its result, and turns auto-apply on and off', function () {
    Http::fake([
        'api.cipi.test/api/apps/shop/yml/apply' => Http::response(['job_id' => 'job-yml', 'status' => 'pending'], 202),
        'api.cipi.test/api/apps/shop/yml/auto/enable' => Http::response(['data' => ['app' => 'shop', 'auto' => true, 'sudoers' => true, 'file' => '/home/shop/current/cipi.yml']]),
        'api.cipi.test/api/apps/shop/yml/auto/disable' => Http::response(['data' => ['app' => 'shop', 'auto' => false, 'sudoers' => false, 'file' => '/home/shop/current/cipi.yml']]),
        'api.cipi.test/api/apps/shop/yml' => Http::response(ymlPlan()),
        // a partial apply fails the job and still says what it did
        'api.cipi.test/api/jobs/job-yml' => Http::response(['data' => ['id' => 'job-yml', 'type' => 'app-yml-apply', 'status' => 'failed', 'output' => 'cipi.yml: 1 of 2 changes applied', 'result' => [
            'app' => 'shop', 'outcome' => 'partial', 'applied' => 1, 'failed' => 1, 'failures' => ['add alias www.shop.test'], 'blockers' => [], 'errors' => [],
        ]]]),
    ]);
    $account = cipiServer();
    $project = Project::factory()->create();
    $dev = User::factory()->create();
    $project->syncMembers([$dev->id => 'dev']);
    $resource = attachShop($project, $account, ['actions' => ['deploy', 'yml', 'yml-apply', 'yml-auto']]);

    $page = Livewire::actingAs($dev)->test(CipiAppHub::class, ['resource' => $resource->uuid])
        ->call('setTab', 'yml')
        ->assertSet('tab', 'yml')
        ->assertSee('/home/shop/current/cipi.yml')
        ->assertSee('2 changes')
        ->assertSee('PHP 8.3 → 8.4')
        ->assertSee('add alias www.shop.test')
        ->assertSee('healthcheck path /up answers 404 today')
        ->assertSee('node settings change on the next deploy')
        ->assertSee('auto-apply off')
        ->assertDontSee('Turn auto-apply off')
        ->assertSee('Anyone who can commit to the repository can then change the app', false);

    expect($page->html())->not->toMatch(APPLY_DISABLED);

    $page->call('applyYml')
        ->assertSet('job.id', 'job-yml')
        ->call('pollJob')
        ->assertSet('job.status', 'failed')
        ->assertSet('job.type', 'app-yml-apply')
        ->assertSee('partly applied')
        ->assertSee('1 change applied')
        ->assertSee('1 change failed')
        ->call('setYmlAuto', true)
        ->call('setYmlAuto', false);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.cipi.test/api/apps/shop/yml/apply' && $r->method() === 'POST');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.cipi.test/api/apps/shop/yml/auto/enable' && $r->method() === 'POST');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.cipi.test/api/apps/shop/yml/auto/disable' && $r->method() === 'POST');
});

test('the cipi.yml tab says when there is no file, when it is invalid and when apply is blocked', function (array $plan, array $see) {
    Http::fake(['api.cipi.test/api/apps/shop/yml' => Http::response(ymlPlan($plan))]);
    $account = cipiServer();
    $resource = attachShop(Project::factory()->create(), $account);

    $page = Livewire::actingAs(User::factory()->admin()->create())->test(CipiAppHub::class, ['resource' => $resource->uuid])
        ->call('setTab', 'yml');

    foreach ($see as $text) {
        $page->assertSee($text);
    }

    // apply refuses a file it cannot apply: the button waits
    expect($page->html())->toMatch(APPLY_DISABLED);
})->with([
    'no file' => [['file' => null, 'valid' => null, 'in_sync' => null, 'changes' => [], 'warnings' => [], 'notes' => []], ['no cipi.yml', 'The live release has no cipi.yml']],
    'invalid' => [['valid' => false, 'in_sync' => null, 'changes' => [], 'errors' => ['line 4: unknown key "phpp"']], ['not valid', 'line 4: unknown key', 'The file is not valid']],
    'blocked' => [['blockers' => ['PHP 8.5 is not installed — run: cipi php install 8.5']], ['blocked', 'PHP 8.5 is not installed']],
]);

test('a token without yml-view or a Cipi older than 5.6.4 leaves a note on the cipi.yml tab, not an error', function (int $status, array $body, string $note, string $refusal) {
    Http::fake([
        'api.cipi.test/api/apps/shop/yml/auto/enable' => Http::response($body, $status),
        'api.cipi.test/api/apps/shop/yml/apply' => Http::response($body, $status),
        'api.cipi.test/api/apps/shop/yml' => Http::response($body, $status),
    ]);
    $account = cipiServer();
    $resource = attachShop(Project::factory()->create(), $account);

    Livewire::actingAs(User::factory()->admin()->create())->test(CipiAppHub::class, ['resource' => $resource->uuid])
        ->call('setTab', 'yml')
        ->assertSee($note)
        ->assertSee('Apply cipi.yml')
        ->assertSee('Turn auto-apply on')
        ->assertSee('Turn auto-apply off')
        ->call('applyYml')
        ->assertSet('job', null)
        ->assertDispatched('toast-show', fn (string $name, array $params) => ($params['dataset']['variant'] ?? null) === 'danger' && str_contains($params['slots']['text'], $refusal))
        ->call('setYmlAuto', true)
        ->assertOk()
        ->assertDispatched('toast-show', fn (string $name, array $params) => ($params['dataset']['variant'] ?? null) === 'danger' && str_contains($params['slots']['text'], $refusal));
})->with([
    'token without the ability' => [403, ['message' => 'Invalid ability provided.'], 'lacks the yml-view ability', 'lacks the yml-manage ability'],
    'cipi/api before 1.35' => [404, ['message' => 'Not Found'], 'it needs Cipi 5.6.4 and cipi/api 1.35 or later', 'it needs Cipi 5.6.4 and cipi/api 1.35 or later'],
    'Cipi before 5.6.4' => [503, ['error' => 'cipi yml plan --json: unknown option'], 'unknown option', 'unknown option'],
]);

test('only the roles handed the cipi.yml actions see the tab and apply it', function () {
    $account = cipiServer();
    $project = Project::factory()->create();
    $dev = User::factory()->create();
    $project->syncMembers([$dev->id => 'dev']);
    $resource = attachShop($project, $account);

    Livewire::actingAs($dev)->test(CipiAppHub::class, ['resource' => $resource->uuid])
        ->assertDontSee('cipi.yml')
        ->call('setTab', 'yml')
        ->assertSet('tab', 'deploys')
        ->call('applyYml')
        ->assertForbidden();

    Livewire::actingAs($dev)->test(CipiAppHub::class, ['resource' => $resource->uuid])
        ->call('setYmlAuto', true)
        ->assertForbidden();

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/yml'));
});
