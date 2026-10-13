<?php

use App\Bridge\BridgeClient;
use App\Enums\ClaudeAuthMode;
use App\Jobs\SyncWorkspaceCredentials;
use App\Livewire\Settings\Connections;
use App\Livewire\Studio\Chat;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use App\Ssh\Gateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'studio.driver' => 'native',
        'studio.native.helper' => base_path('tests/fixtures/fake-studio-admin'),
        'studio.native.sudo' => false,
        'app.url' => 'https://studio.example.com',
    ]);
    $this->log = tempnam(sys_get_temp_dir(), 'studio-admin-');
    $_ENV['FAKE_STUDIO_ADMIN_LOG'] = $_SERVER['FAKE_STUDIO_ADMIN_LOG'] = $this->log;
});

afterEach(function () {
    unset($_ENV['FAKE_STUDIO_ADMIN_LOG'], $_SERVER['FAKE_STUDIO_ADMIN_LOG'], $_ENV['FAKE_CLAUDE_METHOD'], $_SERVER['FAKE_CLAUDE_METHOD']);
    @unlink($this->log);
});

function signInAs(string $method): void
{
    $_ENV['FAKE_CLAUDE_METHOD'] = $_SERVER['FAKE_CLAUDE_METHOD'] = $method;
}

test('"Sign in with Claude" gives a ticket the gateway redeems for the person\'s own home, nothing else', function () {
    $user = User::factory()->create(['handle' => 'maria']);

    $info = Livewire::actingAs($user)->test(Connections::class)->call('claudeLoginTicket')->effects['returns'][0];

    expect($info['url'])->toBe('wss://studio.example.com/ssh/ws');

    $this->postJson(route('api.ssh.redeem'), ['ticket' => $info['ticket']], ['Authorization' => 'Bearer '.Gateway::token()])
        ->assertOk()
        ->assertExactJson(['kind' => 'claude-login', 'user_id' => $user->id, 'handle' => 'maria']);

    // Spent; and a client, someone without a home, or development get none.
    $this->postJson(route('api.ssh.redeem'), ['ticket' => $info['ticket']], ['Authorization' => 'Bearer '.Gateway::token()])->assertNotFound();
    $client = User::factory()->client()->create(['handle' => 'carla']);
    $this->postJson(route('api.ssh.redeem'), ['ticket' => Gateway::issueLogin($client)], ['Authorization' => 'Bearer '.Gateway::token()])->assertNotFound();
    Livewire::actingAs(User::factory()->create(['handle' => null]))->test(Connections::class)->call('claudeLoginTicket')->assertReturned(null);
    config(['studio.driver' => 'local']);
    Livewire::actingAs($user)->test(Connections::class)->call('claudeLoginTicket')->assertReturned(null);
});

test('after the sign-in, Studio asks Claude Code in the home and keeps only that the person is signed in', function () {
    Queue::fake();
    $user = User::factory()->create(['handle' => 'maria', 'claude_auth_mode' => ClaudeAuthMode::None]);
    Workspace::factory()->create(['user_id' => $user->id, 'driver' => 'native', 'path' => '/srv/x']);

    signInAs('none');
    Livewire::actingAs($user)->test(Connections::class)->call('refreshClaude');
    expect($user->fresh()->hasClaude())->toBeFalse();

    signInAs('claude.ai');
    Livewire::actingAs($user)->test(Connections::class)
        ->call('refreshClaude')
        ->assertSee('Signed in with your Claude subscription');

    $fresh = $user->fresh();
    expect($fresh->claude_auth_mode)->toBe(ClaudeAuthMode::Subscription)
        ->and($fresh->claude_signed_in)->toBeTrue()
        ->and($fresh->claude_token)->toBeNull()
        ->and($fresh->hasClaude())->toBeTrue()
        ->and($fresh->claude_checked_at)->not->toBeNull()
        ->and(file_get_contents($this->log))->toContain('claude-status maria');

    // The workspaces drop any credential they had: the login in the home is what they use now.
    Queue::assertPushed(SyncWorkspaceCredentials::class);

    // Signed out on the server (expired, or from the CLI): the next check says so.
    signInAs('none');
    Livewire::actingAs($user)->test(Connections::class)->call('refreshClaude');
    expect($user->fresh()->claude_signed_in)->toBeFalse()->and($user->fresh()->hasClaude())->toBeFalse();
});

test('signing out runs claude auth logout in the home', function () {
    Queue::fake();
    $user = User::factory()->create(['handle' => 'maria', 'claude_auth_mode' => ClaudeAuthMode::Subscription, 'claude_signed_in' => true]);

    Livewire::actingAs($user)->test(Connections::class)->call('logoutClaude');

    expect($user->fresh()->claude_auth_mode)->toBe(ClaudeAuthMode::None)
        ->and($user->fresh()->claude_signed_in)->toBeFalse()
        ->and(file_get_contents($this->log))->toContain('claude-logout maria');
});

test('a setup-token pasted as an API key is refused: subscriptions sign in', function () {
    $user = User::factory()->create(['handle' => 'maria']);

    Livewire::actingAs($user)->test(Connections::class)
        ->set('claude_auth_mode', 'api_key')
        ->set('claude_token', 'sk-ant-oat01-0123456789abcdefghijklmnopqrstuvwxyz')
        ->call('saveClaude')
        ->assertHasErrors('claude_token');

    expect($user->fresh()->claude_token)->toBeNull();
});

test('with a subscription and an API key the person chooses, a conversation may choose otherwise, and the bridge is told', function () {
    Queue::fake();
    $user = User::factory()->create(['handle' => 'maria', 'claude_signed_in' => true, 'claude_auth_mode' => ClaudeAuthMode::Subscription]);

    $page = Livewire::actingAs($user)->test(Connections::class)
        ->assertDontSee('Your conversations use')
        ->set('claude_token', 'sk-ant-api03-0123456789abcdefghijklmnop')
        ->call('saveClaude')
        ->assertSee('Your conversations use');

    expect($user->fresh()->claude_auth_mode)->toBe(ClaudeAuthMode::Subscription)
        ->and($user->fresh()->claudeAccounts())->toBe(['subscription', 'api_key']);

    $page->set('claude_auth_mode', 'api_key');
    expect($user->fresh()->claudeAccountFor())->toBe('api_key')
        ->and($user->fresh()->claudeAccountFor('subscription'))->toBe('subscription');

    // Not signed in any more: whatever was chosen, the API key it is.
    $user->forceFill(['claude_signed_in' => false, 'claude_auth_mode' => ClaudeAuthMode::Subscription])->save();
    expect($user->fresh()->claudeAccountFor('subscription'))->toBe('api_key');

    // Removing the key with nothing else left: no Claude.
    Livewire::actingAs($user)->test(Connections::class)->call('forgetClaude');
    expect($user->fresh()->claudeAccountFor())->toBeNull()->and($user->fresh()->hasClaude())->toBeFalse();
});

test('a conversation\'s account travels with the turn', function () {
    config(['studio.driver' => 'local']);
    $user = User::factory()->create(['claude_signed_in' => true, 'claude_token' => 'sk-ant-api03-key', 'claude_auth_mode' => ClaudeAuthMode::Subscription]);
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);
    $conversation = Conversation::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);

    Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation])
        ->set('account', 'api_key')
        ->call('saveSettings')
        ->assertHasNoErrors();
    expect($conversation->fresh()->claude_auth)->toBe('api_key');

    Http::fake(['*' => Http::response(['accepted' => true], 202)]);
    (new BridgeClient($workspace))->sendTurn($conversation->fresh(), 'ciao');
    Http::assertSent(fn ($request) => $request['auth'] === 'api_key');
});
