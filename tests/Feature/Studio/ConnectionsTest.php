<?php

use App\Livewire\Settings\Connections;
use App\Models\ClaudeAccount;
use App\Models\ConnectorAccount;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('the handle comes from the login of the first git account, once, and never changes with the accounts', function () {
    $user = User::factory()->withGit(attributes: ['login' => 'Maria-Rossi'])->create(['handle' => null]);

    expect($user->ensureHandle())->toBe('maria-rossi');

    ConnectorAccount::query()->where('user_id', $user->id)->delete();
    expect($user->fresh()->ensureHandle())->toBe('maria-rossi')->and($user->fresh()->hasGit())->toBeFalse();
});

test('when the login is already a handle, or spells an existing project host, a free one is picked; without a git account the e-mail gives it', function () {
    User::factory()->create(['handle' => 'maria']);
    Project::factory()->create(['slug' => 'maria-2-shop']);
    Project::factory()->create(['slug' => 'shop']);
    $user = User::factory()->withGit(attributes: ['login' => 'Maria'])->create(['handle' => null]);

    // "maria" is taken, "maria-2" would make the previews of "shop" land on the project "maria-2-shop"
    expect($user->ensureHandle())->toBe('maria-3')
        ->and(User::factory()->create(['handle' => null, 'email' => 'luca.bianchi@agency.test'])->ensureHandle())->toBe('luca-bianchi');
});

test('an API key is stored encrypted, with its name, and the first account becomes the default', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Connections::class)
        ->set('new_kind', 'api_key')
        ->set('new_name', 'Company key')
        ->set('new_token', 'sk-ant-api03-0123456789abcdefghijklmnop')
        ->call('addClaude')
        ->assertHasNoErrors();

    $account = ClaudeAccount::query()->sole();

    expect($account->token)->toBe('sk-ant-api03-0123456789abcdefghijklmnop')
        ->and(DB::table('claude_accounts')->value('token'))->not->toContain('sk-ant-')
        ->and($user->fresh()->claude_account_id)->toBe($account->id)
        ->and($user->fresh()->hasClaude())->toBeTrue();

    // names are the person's own: no two alike
    Livewire::actingAs($user)->test(Connections::class)
        ->set('new_kind', 'api_key')->set('new_name', 'Company key')->set('new_token', 'sk-ant-api03-another0123456789abcdef')
        ->call('addClaude')->assertHasErrors('new_name');
});

test('the connections page lists every git account with the identity its commits carry, and every Claude account with where it is used', function () {
    $user = User::factory()
        ->withGit(attributes: ['name' => 'Personal', 'git_email' => 'maria@personal.test'])
        ->withGit('glpat-client', 'gitlab', 'gitlab.client.test', ['name' => 'Client GitLab', 'git_email' => 'mrossi@client.test', 'credentials' => ['url' => 'https://gitlab.client.test']])
        ->withClaude('subscription')
        ->create();
    $team = ClaudeAccount::factory()->for($user)->subscription('.claude-team')->create(['name' => 'Company Team']);
    ProjectGroup::query()->create(['user_id' => $user->id, 'name' => 'Clients', 'claude_account_id' => $team->id]);

    $this->actingAs($user)->get(route('connections.edit'))->assertOk()
        ->assertSee(['Personal', 'maria@personal.test', 'Client GitLab', 'gitlab.client.test', 'mrossi@client.test'])
        ->assertSee(['Company Team', 'default of Clients', 'Claude subscription'])
        ->assertSee('Add a Claude account')
        ->assertDontSee('setup-token');
});
