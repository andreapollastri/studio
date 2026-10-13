<?php

use App\Bridge\BridgeClient;
use App\Enums\ClaudeAuthMode;
use App\Jobs\SyncWorkspaceCredentials;
use App\Livewire\Settings\Connections;
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

test('one account or the other: saving a key signs the subscription out, signing in removes the key', function () {
    Queue::fake();
    $user = User::factory()->create(['handle' => 'maria', 'claude_signed_in' => true, 'claude_auth_mode' => ClaudeAuthMode::Subscription]);
    Workspace::factory()->create(['user_id' => $user->id, 'driver' => 'native', 'path' => '/srv/x']);

    Livewire::actingAs($user)->test(Connections::class)
        ->assertSet('claude_auth_mode', 'subscription')
        ->set('claude_auth_mode', 'api_key')
        ->set('claude_token', 'sk-ant-api03-0123456789abcdefghijklmnop')
        ->call('saveClaude')
        ->assertHasNoErrors();

    $fresh = $user->fresh();
    expect($fresh->claude_auth_mode)->toBe(ClaudeAuthMode::ApiKey)
        ->and($fresh->claude_signed_in)->toBeFalse()
        ->and($fresh->claudeAccount())->toBe('api_key')
        ->and(file_get_contents($this->log))->toContain('claude-logout maria');

    // A quiet check on page load changes nothing; signing in does.
    signInAs('claude.ai');
    Livewire::actingAs($user)->test(Connections::class)->call('refreshClaude', true);
    expect($user->fresh()->claude_auth_mode)->toBe(ClaudeAuthMode::ApiKey)->and($user->fresh()->claude_token)->not->toBeNull();

    Livewire::actingAs($user)->test(Connections::class)->call('refreshClaude');
    $fresh = $user->fresh();
    expect($fresh->claude_auth_mode)->toBe(ClaudeAuthMode::Subscription)
        ->and($fresh->claude_token)->toBeNull()
        ->and($fresh->claudeAccount())->toBe('subscription');
    Queue::assertPushed(SyncWorkspaceCredentials::class);
});

test('the chosen account travels with every turn, and only an API key in use reaches the workspace', function () {
    config(['studio.driver' => 'local']);
    $user = User::factory()->create(['claude_signed_in' => true, 'claude_auth_mode' => ClaudeAuthMode::Subscription]);
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);
    $conversation = Conversation::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);

    Http::fake(['*' => Http::response(['accepted' => true], 202)]);
    (new BridgeClient($workspace))->sendTurn($conversation, 'ciao');
    Http::assertSent(fn ($request) => $request['auth'] === 'subscription');

    // Not signed in any more: no account to show, but the turn still runs on the mode chosen in
    // Settings (and fails saying so) rather than on whatever else the workspace could find.
    $user->forceFill(['claude_signed_in' => false])->save();
    expect($user->fresh()->hasClaude())->toBeFalse()->and($user->fresh()->claudeAccount())->toBeNull();
    (new BridgeClient($workspace))->sendTurn($conversation->fresh(), 'ancora');
    Http::assertSent(fn ($request) => $request['text'] === 'ancora' && $request['auth'] === 'subscription');

    // The API key chosen in Settings goes for this conversation too: there is no per-project choice.
    $user->forceFill(['claude_auth_mode' => ClaudeAuthMode::ApiKey, 'claude_token' => 'sk-ant-test'])->save();
    (new BridgeClient($workspace))->sendTurn($conversation->fresh(), 'con la chiave');
    Http::assertSent(fn ($request) => $request['text'] === 'con la chiave' && $request['auth'] === 'api_key');
});
