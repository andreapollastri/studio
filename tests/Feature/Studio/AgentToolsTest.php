<?php

use App\Bridge\BridgeClient;
use App\Enums\ClaudeAuthMode;
use App\Enums\ConversationStatus;
use App\Livewire\Admin\Projects;
use App\Livewire\Studio\Chat;
use App\Livewire\Studio\ConversationList;
use App\Livewire\Studio\Shell;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Server\SiteAccess;
use App\Server\SitePass;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// a 1×1 PNG, as the agent's browser hands back a screenshot
const SCREENSHOT = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

beforeEach(function () {
    config(['studio.domain' => 'dev.example.test']);
    Storage::fake('local');
});

function agentMember(array $workspace = []): array
{
    $user = User::factory()->pm()->create(['handle' => 'maria']);
    $project = Project::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
    $project->members()->attach($user);
    $workspace = Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $user->id, ...$workspace]);

    return [$user, $project, $workspace];
}

/** What the bridge answers when it creates a sandbox's worktree. */
function sandboxCreated(string $name = 'c1', string $branch = 'sandbox/maria-1'): array
{
    return ['name' => $name, 'path' => "/srv/studio/workspaces/maria/.sandboxes/shop/{$name}", 'branch' => $branch, 'workspace_branch' => 'main', 'ahead' => 0, 'behind' => 0, 'files' => 0, 'status' => [], 'stat' => '', 'upstream' => null];
}

function postEvents(Workspace $workspace, Conversation $conversation, array $events)
{
    return test()->withToken((string) $workspace->callback_token)->postJson(route('api.bridge.events'), ['conversation' => $conversation->id, 'events' => $events]);
}

// ------------------------------------------------------------------ browser --

test('a turn hands the bridge the preview and a pass for that host alone, as the person', function () {
    [$user, , $workspace] = agentMember(['app_url' => 'https://maria-shop.dev.example.test']);
    $conversation = Conversation::factory()->forWorkspace($workspace)->create();
    Http::fake(['http://bridge.test:4455/*' => Http::response(['accepted' => true], 202)]);

    Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation])->set('draft', 'Controlla la home')->call('send');

    Http::assertSent(function (Request $request) use ($user) {
        $browser = $request['browser'];
        $passes = app(SitePass::class);

        return $browser['url'] === 'https://maria-shop.dev.example.test'
            && $browser['cookies'][0]['name'] === SitePass::COOKIE
            && $passes->userFromPass($browser['cookies'][0]['value'], 'maria-shop.dev.example.test')?->is($user)
            // the same pass opens no other host
            && $passes->userFromPass($browser['cookies'][0]['value'], 'shop.dev.example.test') === null
            && $browser['cookies'][0]['expires'] > now()->getTimestamp()
            && $request['sandbox'] === null;
    });
});

test('no browser when the project keeps it off; a local preview opens without a pass', function () {
    [, $project, $workspace] = agentMember(['app_url' => 'http://127.0.0.1:8000']);
    $conversation = Conversation::factory()->forWorkspace($workspace)->create();
    $bridge = new BridgeClient($workspace);

    expect($bridge->browser($conversation))->toBe(['url' => 'http://127.0.0.1:8000', 'cookies' => []]);

    $project->update(['settings' => ['agent' => ['browser' => false]]]);
    expect($bridge->browser($conversation->fresh()))->toBeNull();
});

test('screenshots in tool results are kept on Studio\'s disk and served only to who may read the conversation', function () {
    [$user, $project, $workspace] = agentMember();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create(['status' => ConversationStatus::Running]);

    postEvents($workspace, $conversation, [
        ['type' => 'tool_use', 'id' => 'toolu_shot', 'name' => 'mcp__browser__browser_take_screenshot', 'input' => []],
        ['type' => 'tool_result', 'tool_use_id' => 'toolu_shot', 'content' => "Took the screenshot\n[image]", 'images' => [
            ['media_type' => 'image/png', 'data' => SCREENSHOT],
            // not a PNG at all: a page passed off as one is dropped
            ['media_type' => 'image/png', 'data' => base64_encode('<script>alert(1)</script>')],
            // too big for the bridge to send: noted, not shown
            ['media_type' => 'image/png', 'data' => null, 'omitted_bytes' => 9_000_000],
        ]],
    ])->assertOk();

    $result = $conversation->messages()->where('kind', Message::KIND_TOOL_RESULT)->firstOrFail();
    $images = $result->images($conversation);

    expect($images)->toHaveCount(1)
        ->and($result->payload['images'])->toHaveCount(2)
        ->and($result->payload['images'][1]['omitted'])->toBeTrue();

    $this->actingAs($user)->get($images[0]['url'])
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->actingAs(User::factory()->pm()->create())->get($images[0]['url'])->assertNotFound();
    $this->actingAs($user)->get(route('studio.conversation.image', [$project, $conversation, '../../.env']))->assertNotFound();

    // the chat shows the thumbnail with the browser's tool named plainly
    Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation])
        ->assertSee('browser · take screenshot')
        ->assertSee($images[0]['url'], false);
});

// ---------------------------------------------------------------- subagents --

test('subagents are drawn as cards with their steps and screenshots; those started together side by side', function () {
    [$user, , $workspace] = agentMember();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create(['status' => ConversationStatus::Running]);

    postEvents($workspace, $conversation, [
        ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'Task', 'message_id' => 'msg_1', 'input' => ['description' => 'Check the checkout', 'subagent_type' => 'browser-qa', 'prompt' => 'Open /checkout']],
        ['type' => 'tool_use', 'id' => 'toolu_b', 'name' => 'Task', 'message_id' => 'msg_1', 'input' => ['description' => 'Review the diff', 'subagent_type' => 'code-reviewer', 'prompt' => 'Review']],
        ['type' => 'task', 'phase' => 'started', 'task_id' => 'k1', 'tool_use_id' => 'toolu_a', 'status' => 'running'],
        ['type' => 'tool_use', 'id' => 'toolu_nav', 'name' => 'mcp__browser__browser_navigate', 'input' => ['url' => 'https://maria-shop.dev.example.test/checkout'], 'parent_tool_use_id' => 'toolu_a'],
        ['type' => 'tool_use', 'id' => 'toolu_shot', 'name' => 'mcp__browser__browser_take_screenshot', 'input' => [], 'parent_tool_use_id' => 'toolu_a'],
        ['type' => 'tool_result', 'tool_use_id' => 'toolu_shot', 'content' => '[image]', 'parent_tool_use_id' => 'toolu_a', 'images' => [['media_type' => 'image/png', 'data' => SCREENSHOT]]],
        ['type' => 'text', 'text' => 'The total shows NaN.', 'parent_tool_use_id' => 'toolu_a'],
        ['type' => 'tool_result', 'tool_use_id' => 'toolu_a', 'content' => "The checkout total shows **NaN** when the cart is empty.\nagentId: a1b2"],
        ['type' => 'task', 'phase' => 'finished', 'task_id' => 'k1', 'tool_use_id' => 'toolu_a', 'status' => 'completed', 'summary' => 'Checkout checked'],
    ])->assertOk();

    $call = $conversation->messages()->where('tool_use_id', 'toolu_a')->where('kind', Message::KIND_TOOL_USE)->firstOrFail();
    expect($call->payload['task']['status'])->toBe('completed')
        ->and($call->payload['task'])->toHaveKeys(['started_at', 'finished_at'])
        ->and($call->payload['message_id'])->toBe('msg_1');

    $chat = Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation]);
    $rows = $chat->instance()->rows();

    // the two calls are one row; the subagent's own steps are not rows of the chat
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['agents'])->toBeTrue()
        ->and($rows[0]['messages'])->toHaveCount(2)
        ->and($chat->instance()->children()->get('toolu_a'))->toHaveCount(3);

    $chat->assertSee('2 subagents in parallel')
        ->assertSee('Check the checkout')
        ->assertSee('browser-qa')
        ->assertSee('2 steps')
        ->assertSee('NaN')
        ->assertDontSee('agentId: a1b2')
        ->assertSee('/images/', false);
});

// ---------------------------------------------------------------- sandboxes --

test('a conversation in a sandbox: the bridge makes the worktree, the turn runs in it, the composer waits for it', function () {
    [$user, $project, $workspace] = agentMember();
    Http::fake([
        'http://bridge.test:4455/sandboxes' => Http::response(sandboxCreated('c1'), 201),
        'http://bridge.test:4455/sandboxes/*' => Http::response([...sandboxCreated('c1'), 'files' => 2, 'ahead' => 1]),
        'http://bridge.test:4455/conversations/*' => Http::response(['accepted' => true], 202),
        'http://bridge.test:4455/git' => Http::response(['branch' => 'sandbox/maria-1', 'status' => [], 'files' => []]),
    ]);

    Livewire::actingAs($user)->test(Shell::class, ['project' => $project])->call('newSandbox')->assertRedirect();

    $conversation = Conversation::query()->sole();
    expect($conversation->sandboxName())->toBe('c'.$conversation->id)
        ->and($conversation->sandboxReady())->toBeTrue()
        ->and($conversation->sandbox['branch'])->toBe('sandbox/maria-'.$conversation->id)
        ->and($conversation->sandbox['base'])->toBe('main')
        // local driver: a worktree, no host of its own
        ->and($conversation->sandbox_host)->toBeNull()
        ->and($conversation->previewUrl())->toBeNull();

    Http::assertSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/sandboxes' && $request['name'] === $conversation->sandboxName() && $request['branch'] === $conversation->sandbox['branch']);

    Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation])
        ->assertSee('Sandbox')
        ->assertSee('2 files changed')
        ->assertSee('1 commit ahead of main')
        ->set('draft', 'Rifai il checkout')
        ->call('send')
        ->assertHasNoErrors();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/turns') && $request['sandbox'] === $conversation->sandboxName() && $request['browser'] === null);
    // the Changes and Files tabs of this conversation work in the sandbox
    expect(BridgeClient::for($conversation)->git())->toBeArray();
    Http::assertSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/git' && $request->hasHeader('X-Studio-Sandbox', $conversation->sandboxName()));
});

test('while the sandbox is being prepared nobody can write in it', function () {
    [$user, , $workspace] = agentMember();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create(['sandbox' => ['status' => Conversation::SANDBOX_CREATING, 'name' => 'c1', 'branch' => 'sandbox/maria-1']]);

    Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation])
        ->assertSee('Preparing')
        ->set('draft', 'Ciao')
        ->call('send')
        ->assertHasErrors('draft');

    expect($conversation->messages()->count())->toBe(0);
});

test('on a server the sandbox gets its own host, which the gatekeeper and the certificate check know', function () {
    config(['studio.driver' => 'native', 'studio.native.helper' => base_path('tests/fixtures/fake-studio-admin'), 'studio.native.sudo' => false]);
    $log = tempnam(sys_get_temp_dir(), 'studio-admin-');
    $_ENV['FAKE_STUDIO_ADMIN_LOG'] = $_SERVER['FAKE_STUDIO_ADMIN_LOG'] = $log;

    [$user, $project, $workspace] = agentMember(['driver' => 'native', 'app_url' => 'https://maria-shop.dev.example.test', 'path' => '/srv/studio/workspaces/maria/shop']);
    $user->forceFill(['git_token' => 'github_pat_secret', 'claude_auth_mode' => ClaudeAuthMode::Subscription])->save();
    Http::fake(['http://bridge.test:4455/sandboxes' => Http::response(sandboxCreated('c1'), 201)]);

    Livewire::actingAs($user)->test(Shell::class, ['project' => $project])->call('newSandbox');
    $conversation = Conversation::query()->sole();
    $host = 'maria-shop--c'.$conversation->id.'.dev.example.test';

    expect($conversation->sandbox_host)->toBe($host)
        ->and($conversation->sandbox['url'])->toBe('https://'.$host)
        ->and($conversation->previewUrl())->toBe('https://'.$host)
        ->and(file_get_contents($log))->toContain('sandbox-create maria shop '.$conversation->id)->toContain('"preview_host":"'.$host.'"');

    // the gatekeeper: a preview of the project, on the sandbox's branch
    $target = app(SiteAccess::class)->resolveHost($host);
    expect($target['kind'])->toBe('preview')
        ->and($target['project']->is($project))->toBeTrue()
        ->and($target['branch'])->toBe('sandbox/maria-1');

    $this->get('/api/tls/ask?domain='.$host)->assertOk();

    // the agent's browser opens the sandbox's preview with a pass for that host
    $browser = (new BridgeClient($workspace))->browser($conversation);
    expect($browser['url'])->toBe('https://'.$host)
        ->and(app(SitePass::class)->userFromPass($browser['cookies'][0]['value'], $host)?->is($user))->toBeTrue();

    unset($_ENV['FAKE_STUDIO_ADMIN_LOG'], $_SERVER['FAKE_STUDIO_ADMIN_LOG']);
    @unlink($log);
});

test('a sandbox that could not be prepared says why and can be tried again', function () {
    config(['studio.driver' => 'native', 'studio.native.helper' => base_path('tests/fixtures/fake-studio-admin'), 'studio.native.sudo' => false]);
    $_ENV['FAKE_SANDBOX_FAIL'] = $_SERVER['FAKE_SANDBOX_FAIL'] = '1';

    [$user, $project, $workspace] = agentMember(['driver' => 'native', 'path' => '/srv/studio/workspaces/maria/shop']);
    Http::fake(['http://bridge.test:4455/sandboxes' => Http::response(sandboxCreated('c1'), 201)]);

    Livewire::actingAs($user)->test(Shell::class, ['project' => $project])->call('newSandbox');
    $conversation = Conversation::query()->sole();

    expect($conversation->sandboxStatus())->toBe(Conversation::SANDBOX_FAILED)
        ->and($conversation->sandbox['error'])->toContain('composer install failed');

    unset($_ENV['FAKE_SANDBOX_FAIL'], $_SERVER['FAKE_SANDBOX_FAIL']);

    Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation])
        ->assertSee('composer install failed')
        ->call('retrySandbox');

    expect($conversation->fresh()->sandboxReady())->toBeTrue();
});

test('sandboxes stop at the limit per person, and are not offered when the project keeps them off', function () {
    [$user, $project, $workspace] = agentMember();
    $project->update(['settings' => ['agent' => ['sandbox_limit' => 1]]]);
    Conversation::factory()->forWorkspace($workspace)->create(['sandbox' => ['status' => Conversation::SANDBOX_READY, 'name' => 'c9', 'branch' => 'sandbox/maria-9']]);
    // a removed one no longer counts
    Conversation::factory()->forWorkspace($workspace)->create(['sandbox' => ['status' => Conversation::SANDBOX_REMOVED, 'name' => 'c8', 'branch' => 'sandbox/maria-8']]);
    Http::fake();

    Livewire::actingAs($user)->test(Shell::class, ['project' => $project])->call('newSandbox')->assertNoRedirect();
    expect(Conversation::query()->count())->toBe(2);
    Http::assertNotSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/sandboxes');

    $project->update(['settings' => ['agent' => ['sandboxes' => false]]]);
    Livewire::actingAs($user)->test(Shell::class, ['project' => $project])
        ->assertDontSee('Conversation in a sandbox')
        ->call('newSandbox')
        ->assertNoRedirect();
});

test('merging commits the sandbox and merges its branch into the workspace\'s; pushing pushes the branch', function () {
    [$user, , $workspace] = agentMember();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create(['title' => 'Checkout in two steps', 'sandbox' => ['status' => Conversation::SANDBOX_READY, 'name' => 'c1', 'branch' => 'sandbox/maria-1']]);
    $conversation->updateSandbox(['name' => $conversation->sandboxName()]);
    $name = $conversation->sandboxName();
    Http::fake([
        "http://bridge.test:4455/sandboxes/{$name}/commit" => Http::response(sandboxCreated($name)),
        "http://bridge.test:4455/sandboxes/{$name}" => Http::response(sandboxCreated($name)),
        'http://bridge.test:4455/git/*' => Http::response(['branch' => 'main', 'status' => [], 'files' => []]),
    ]);

    Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation])->call('mergeSandbox');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), "/sandboxes/{$name}/commit") && $request['message'] === 'Checkout in two steps');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/git/merge' && $request['branch'] === 'sandbox/maria-1' && ! $request->hasHeader('X-Studio-Sandbox'));
    expect($conversation->fresh()->sandbox['merged_at'])->not->toBeNull();

    Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation->fresh()])->call('pushSandbox');
    Http::assertSent(fn (Request $request) => $request->url() === 'http://bridge.test:4455/git/push' && $request->hasHeader('X-Studio-Sandbox', $name));
    expect($conversation->fresh()->sandbox['pushed_at'])->not->toBeNull();
});

test('removing the sandbox keeps the conversation to read; deleting the conversation removes its sandbox and pictures', function () {
    [$user, $project, $workspace] = agentMember();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create(['sandbox' => ['status' => Conversation::SANDBOX_READY, 'name' => 'c1', 'branch' => 'sandbox/maria-1']]);
    $name = $conversation->sandboxName();
    Http::fake(['http://bridge.test:4455/*' => Http::response(['removed' => true])]);

    Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation])->call('removeSandbox', true);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_starts_with($request->url(), "http://bridge.test:4455/sandboxes/{$name}") && str_contains($request->url(), 'branch=1'));
    $conversation->refresh();
    expect($conversation->sandboxStatus())->toBe(Conversation::SANDBOX_REMOVED)
        ->and($conversation->acceptsMessages())->toBeFalse();

    Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation])
        ->assertSee('The sandbox was removed')
        ->set('draft', 'Ancora una cosa')
        ->call('send')
        ->assertHasErrors('draft');

    // another one, deleted with its screenshot
    $other = Conversation::factory()->forWorkspace($workspace)->create(['sandbox' => ['status' => Conversation::SANDBOX_READY, 'name' => 'c2', 'branch' => 'sandbox/maria-2']]);
    postEvents($workspace, $other, [['type' => 'tool_result', 'tool_use_id' => 't', 'content' => '[image]', 'images' => [['media_type' => 'image/png', 'data' => SCREENSHOT]]]]);
    expect(Storage::disk('local')->allFiles('conversations/'.$other->uuid))->toHaveCount(1);

    Livewire::actingAs($user)->test(ConversationList::class, ['project' => $project, 'workspace' => $workspace])
        ->call('confirmDelete', $other->uuid)
        ->call('delete');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_starts_with($request->url(), 'http://bridge.test:4455/sandboxes/'.$other->sandboxName()) && str_contains($request->url(), 'branch=0'));
    expect(Storage::disk('local')->allFiles('conversations/'.$other->uuid))->toBe([]);
});

test('the project settings choose the agent\'s browser and sandboxes', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();

    expect($project->agentSettings())->toBe(['browser' => true, 'sandboxes' => true, 'sandbox_limit' => 3]);

    Livewire::actingAs($admin)->test(Projects::class)
        ->call('settings', $project->uuid)
        ->set('settingsTab', 'agent')
        ->assertSee('Browser for the agent')
        ->set('agent_browser', false)
        ->set('agent_sandbox_limit', 5)
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect($project->fresh()->agentSettings())->toBe(['browser' => false, 'sandboxes' => true, 'sandbox_limit' => 5]);

    Livewire::actingAs($admin)->test(Projects::class)
        ->call('settings', $project->uuid)
        ->set('agent_sandbox_limit', 50)
        ->call('saveSettings')
        ->assertHasErrors('agent_sandbox_limit');
});

test('a sandbox host fits in a DNS label', function () {
    $user = User::factory()->create(['handle' => str_repeat('a', 28)]);
    $project = Project::factory()->create(['slug' => str_repeat('b', 28)]);

    $label = explode('.', $project->sandboxHostFor($user, 1234567))[0];

    expect(strlen($label))->toBeLessThanOrEqual(63)
        ->and($label)->toEndWith('--c1234567');
});
