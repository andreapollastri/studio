<?php

use App\Bridge\BridgeClient;
use App\Livewire\Admin\Projects;
use App\Livewire\Studio\Chat;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\ProjectMcpServer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('an administrator adds an MCP server to a project, with its header stored encrypted', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();

    Livewire::actingAs($admin)
        ->test(Projects::class)
        ->call('settings', $project->id)
        ->set('settingsTab', 'mcp')
        ->assertSee('MCP servers in the chat')
        ->set('mcp_name', 'Docs Site')
        ->set('mcp_url', 'not a url')
        ->call('saveMcp')
        ->assertHasErrors(['mcp_name', 'mcp_url'])
        ->set('mcp_name', 'docs')
        ->set('mcp_url', 'https://docs.example.com/mcp')
        ->set('mcp_header_value', 'Bearer secret-docs-token')
        ->set('mcp_roles', ['admin', 'dev', 'client'])
        ->call('saveMcp')
        ->assertHasNoErrors()
        ->assertSee('https://docs.example.com/mcp')
        ->assertSee('header Authorization')
        ->assertDontSee('secret-docs-token');

    $server = $project->mcpServers()->sole();
    expect($server->headers)->toBe(['Authorization' => 'Bearer secret-docs-token'])
        ->and($server->roles)->toBe(['admin', 'dev', 'client'])
        ->and(DB::table('project_mcp_servers')->value('headers'))->not->toContain('secret-docs-token');
});

test('editing keeps the stored secret unless a new value is typed; names are unique per project', function () {
    $admin = User::factory()->admin()->create();
    $server = ProjectMcpServer::factory()->create(['headers' => ['Authorization' => 'Bearer keep-me']]);
    ProjectMcpServer::factory()->create(['project_id' => $server->project_id, 'name' => 'errors']);

    $component = Livewire::actingAs($admin)
        ->test(Projects::class)
        ->call('settings', $server->project_id)
        ->call('editMcp', $server->id)
        ->assertSet('mcp_header_value', '')
        ->assertSet('mcp_has_secret', true)
        ->set('mcp_url', 'https://docs.example.com/v2/mcp')
        ->call('saveMcp')
        ->assertHasNoErrors();

    expect($server->fresh()->headers)->toBe(['Authorization' => 'Bearer keep-me'])
        ->and($server->fresh()->url)->toBe('https://docs.example.com/v2/mcp');

    $component->call('editMcp', $server->id)->set('mcp_header_value', 'Bearer new-one')->call('saveMcp');
    expect($server->fresh()->headers)->toBe(['Authorization' => 'Bearer new-one']);

    $component->call('editMcp', $server->id)->set('mcp_name', 'errors')->call('saveMcp')->assertHasErrors('mcp_name');

    $component->call('toggleMcp', $server->id);
    expect($server->fresh()->enabled)->toBeFalse();

    $component->call('deleteMcp', $server->id);
    expect(ProjectMcpServer::query()->whereKey($server->id)->exists())->toBeFalse();
});

test('testing a server makes the initialize call with its header and keeps the answer', function () {
    $admin = User::factory()->admin()->create();
    $json = ProjectMcpServer::factory()->create(['name' => 'docs', 'url' => 'https://docs.example.com/mcp']);
    $stream = ProjectMcpServer::factory()->create(['project_id' => $json->project_id, 'name' => 'errors', 'url' => 'https://errors.example.com/mcp']);
    $refused = ProjectMcpServer::factory()->create(['project_id' => $json->project_id, 'name' => 'crm', 'url' => 'https://crm.example.com/mcp']);

    Http::fake([
        'https://docs.example.com/mcp' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18', 'serverInfo' => ['name' => 'docs-mcp', 'version' => '1.2.0']]]),
        'https://errors.example.com/mcp' => Http::response("event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"serverInfo\":{\"name\":\"errors\"}}}\n\n", 200, ['Content-Type' => 'text/event-stream']),
        'https://crm.example.com/mcp' => Http::response(['error' => 'invalid token'], 401),
    ]);

    Livewire::actingAs($admin)
        ->test(Projects::class)
        ->call('settings', $json->project_id)
        ->call('testMcp', $json->id)
        ->call('testMcp', $stream->id)
        ->call('testMcp', $refused->id);

    expect($json->fresh()->status)->toBe('ok · docs-mcp 1.2.0')
        ->and($stream->fresh()->status)->toBe('ok · errors')
        ->and($refused->fresh()->status)->toContain('refused (401)')
        ->and($json->fresh()->checked_at)->not->toBeNull();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://docs.example.com/mcp' && $r['method'] === 'initialize'
        && $r->hasHeader('Authorization', 'Bearer docs-token') && str_contains($r->header('Accept')[0], 'text/event-stream'));
});

test('each chat gets the enabled servers of its project that its owner\'s role may use, and sends them with the turn', function () {
    $dev = User::factory()->create();
    $pm = User::factory()->create(['role' => 'pm']);
    $project = Project::factory()->create();
    $project->members()->attach([$dev->id, $pm->id]);
    ProjectMcpServer::factory()->create(['project_id' => $project->id, 'name' => 'docs', 'roles' => ['admin', 'pm', 'dev']]);
    ProjectMcpServer::factory()->create(['project_id' => $project->id, 'name' => 'errors', 'url' => 'https://errors.example.com/mcp', 'roles' => ['admin', 'dev']]);
    ProjectMcpServer::factory()->create(['project_id' => $project->id, 'name' => 'old', 'enabled' => false]);

    $devWorkspace = Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $dev->id]);
    $pmWorkspace = Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $pm->id, 'callback_token_hash' => hash('sha256', 'pm-token')]);
    $devChat = Conversation::factory()->forWorkspace($devWorkspace)->create();
    $pmChat = Conversation::factory()->forWorkspace($pmWorkspace)->create();

    expect(array_keys((new BridgeClient($devWorkspace))->mcpServers($devChat)))->toBe(['docs', 'errors'])
        ->and(array_keys((new BridgeClient($pmWorkspace))->mcpServers($pmChat)))->toBe(['docs']);

    Http::fake(['http://bridge.test:4455/*' => Http::response(['accepted' => true], 202)]);
    (new BridgeClient($devWorkspace))->sendTurn($devChat, 'What do the docs say about refunds?');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), "/conversations/{$devChat->id}/turns")
        && $r['mcp_servers']['errors'] === ['type' => 'http', 'url' => 'https://errors.example.com/mcp', 'headers' => ['Authorization' => 'Bearer docs-token']]
        && ! isset($r['mcp_servers']['old']));

    Livewire::actingAs($pm)->test(Chat::class, ['conversation' => $pmChat])->assertSee('MCP servers')->assertSee('docs')->assertDontSee('errors');
});

test('a project without servers sends none', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);
    $chat = Conversation::factory()->forWorkspace($workspace)->create();

    expect((new BridgeClient($workspace))->mcpServers($chat))->toBeNull();
});
