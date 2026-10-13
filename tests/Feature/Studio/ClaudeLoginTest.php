<?php

use App\Accounts\Accounts;
use App\Bridge\BridgeClient;
use App\Livewire\Settings\Connections;
use App\Models\ClaudeAccount;
use App\Models\Conversation;
use App\Models\ProjectGroup;
use App\Models\User;
use App\Models\Workspace;
use App\Ssh\Gateway;
use Illuminate\Support\Facades\Http;
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

test('"Sign in with Claude" gives a ticket the gateway redeems for the folder of that subscription in the person\'s home, nothing else', function () {
    $user = User::factory()->create(['handle' => 'maria']);
    $first = ClaudeAccount::factory()->for($user)->subscription(signedIn: false)->create();
    $work = ClaudeAccount::factory()->for($user)->subscription('.claude-work', signedIn: false)->create();

    $info = Livewire::actingAs($user)->test(Connections::class)->call('claudeLoginTicket', $work->uuid)->effects['returns'][0];

    expect($info['url'])->toBe('wss://studio.example.com/ssh/ws');

    $this->postJson(route('api.ssh.redeem'), ['ticket' => $info['ticket']], ['Authorization' => 'Bearer '.Gateway::token()])
        ->assertOk()
        ->assertExactJson(['kind' => 'claude-login', 'user_id' => $user->id, 'handle' => 'maria', 'dir' => '.claude-work']);

    // Spent; and a client, an API key, someone else's account, or development get none.
    $this->postJson(route('api.ssh.redeem'), ['ticket' => $info['ticket']], ['Authorization' => 'Bearer '.Gateway::token()])->assertNotFound();
    $client = User::factory()->client()->create(['handle' => 'carla']);
    $clientAccount = ClaudeAccount::factory()->for($client)->subscription()->create();
    $this->postJson(route('api.ssh.redeem'), ['ticket' => Gateway::issueLogin($clientAccount)], ['Authorization' => 'Bearer '.Gateway::token()])->assertNotFound();
    $key = ClaudeAccount::factory()->for($user)->create();
    Livewire::actingAs($user)->test(Connections::class)->call('claudeLoginTicket', $key->uuid)->assertReturned(null);
    Livewire::actingAs(User::factory()->create())->test(Connections::class)->call('claudeLoginTicket', $first->uuid)->assertStatus(404);
    config(['studio.driver' => 'local']);
    Livewire::actingAs($user)->test(Connections::class)->call('claudeLoginTicket', $first->uuid)->assertReturned(null);
});

test('after the sign-in, Studio asks Claude Code in that account\'s folder and keeps only whether it is signed in', function () {
    $user = User::factory()->create(['handle' => 'maria']);
    $work = ClaudeAccount::factory()->for($user)->subscription('.claude-work', signedIn: false)->create(['name' => 'Work']);

    signInAs('none');
    Livewire::actingAs($user)->test(Connections::class)->call('refreshClaude', $work->uuid);
    expect($user->fresh()->hasClaude())->toBeFalse();

    signInAs('claude.ai');
    Livewire::actingAs($user)->test(Connections::class)
        ->call('refreshClaude', $work->uuid)
        ->assertSee('signed in');

    expect($work->fresh()->signed_in)->toBeTrue()
        ->and($work->fresh()->checked_at)->not->toBeNull()
        ->and($user->fresh()->hasClaude())->toBeTrue()
        ->and(file_get_contents($this->log))->toContain('claude-status maria .claude-work');

    // Signed out on the server (expired, or from the CLI): the next check says so.
    signInAs('none');
    Livewire::actingAs($user)->test(Connections::class)->call('refreshClaude', $work->uuid);
    expect($work->fresh()->signed_in)->toBeFalse()->and($user->fresh()->hasClaude())->toBeFalse();
});

test('subscriptions and API keys live side by side: each one signs out, is removed, and the default moves on', function () {
    $user = User::factory()->withClaude('subscription')->create(['handle' => 'maria']);
    $personal = $user->claudeAccounts()->first();

    // a second subscription gets a folder of its own; a key is saved as it is
    $uuid = Livewire::actingAs($user)->test(Connections::class)
        ->set('new_kind', 'subscription')->set('new_name', 'Company Team')
        ->call('addClaude')->assertHasNoErrors()->effects['returns'][0];
    $team = ClaudeAccount::query()->where('uuid', $uuid)->sole();

    Livewire::actingAs($user)->test(Connections::class)
        ->set('new_kind', 'api_key')->set('new_name', 'Client key')->set('new_token', 'sk-ant-api03-0123456789abcdefghijklmnop')
        ->call('addClaude')->assertHasNoErrors();

    expect($team->dir)->toStartWith('.claude-')->not->toBe('.claude')
        ->and($user->claudeAccounts()->count())->toBe(3)
        ->and($user->fresh()->claude_account_id)->toBe($personal->id);

    Livewire::actingAs($user)->test(Connections::class)
        ->call('makeDefault', $team->uuid)
        ->call('logoutClaude', $personal->uuid)
        ->call('removeClaude', $team->uuid);

    $log = file_get_contents($this->log);
    expect($log)->toContain('claude-logout maria')->toContain("claude-forget maria {$team->dir}")
        ->and($personal->fresh()->signed_in)->toBeFalse()
        ->and(ClaudeAccount::query()->whereKey($team->id)->exists())->toBeFalse()
        // the default went with the team account: the first one ready takes its place
        ->and($user->fresh()->defaultClaudeAccount->name)->toBe('Client key');
});

test('a setup-token pasted as an API key is refused: subscriptions sign in', function () {
    $user = User::factory()->create(['handle' => 'maria']);

    Livewire::actingAs($user)->test(Connections::class)
        ->set('new_kind', 'api_key')
        ->set('new_name', 'Mine')
        ->set('new_token', 'sk-ant-oat01-0123456789abcdefghijklmnopqrstuvwxyz')
        ->call('addClaude')
        ->assertHasErrors('new_token');

    expect($user->claudeAccounts()->count())->toBe(0);
});

test('every turn runs on the project\'s Claude account: its own, its group\'s, the person\'s default', function () {
    config(['studio.driver' => 'local']);
    $user = User::factory()->withClaude('subscription')->create();
    $personal = $user->claudeAccounts()->first();
    $company = ClaudeAccount::factory()->for($user)->subscription('.claude-company')->create(['name' => 'Company']);
    $key = ClaudeAccount::factory()->for($user)->create(['name' => 'Client key', 'token' => 'sk-ant-api03-client']);
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);
    $conversation = Conversation::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    $send = function (string $text) use ($workspace, $conversation) {
        (new BridgeClient($workspace))->sendTurn($conversation->fresh(), $text);
    };

    Http::fake(['*' => Http::response(['accepted' => true], 202)]);

    // the default: the first subscription, in ~/.claude, no key
    $send('uno');
    Http::assertSent(fn ($r) => $r['text'] === 'uno' && $r['auth'] === 'subscription' && $r['account'] === $personal->uuid && $r['claude_dir'] === '.claude' && $r['api_key'] === null);

    // the project's group runs on the company's plan
    $group = ProjectGroup::query()->create(['user_id' => $user->id, 'name' => 'Work', 'claude_account_id' => $company->id]);
    Accounts::choose($user, $workspace->project, ['project_group_id' => $group->id]);
    $send('due');
    Http::assertSent(fn ($r) => $r['text'] === 'due' && $r['account'] === $company->uuid && $r['claude_dir'] === '.claude-company');

    // the project itself on the client's key, which goes with the turn
    Accounts::choose($user, $workspace->project, ['claude_account_id' => $key->id]);
    $send('tre');
    Http::assertSent(fn ($r) => $r['text'] === 'tre' && $r['auth'] === 'api_key' && $r['api_key'] === 'sk-ant-api03-client' && $r['claude_dir'] === null);

    // signed out: the turn still says which account, so it fails saying so rather than run on another login
    $key->forceFill(['token' => null])->save();
    $send('quattro');
    Http::assertSent(fn ($r) => $r['text'] === 'quattro' && $r['account'] === $key->uuid);
});
