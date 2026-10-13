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
