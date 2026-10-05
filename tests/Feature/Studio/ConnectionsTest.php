<?php

use App\Enums\ClaudeAuthMode;
use App\Jobs\SyncWorkspaceCredentials;
use App\Livewire\Settings\Connections;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('a valid GitHub token is verified, stored encrypted and gives the person a handle', function () {
    $user = User::factory()->create(['handle' => null]);
    Http::fake(['https://api.github.com/user' => Http::response(['login' => 'Maria-Rossi', 'name' => 'Maria Rossi'])]);

    Livewire::actingAs($user)
        ->test(Connections::class)
        ->set('git_token', 'github_pat_11AAAAAAA0123456789abcdef')
        ->call('saveGit')
        ->assertHasNoErrors()
        ->assertSet('git_token', '');

    $user->refresh();
    expect($user->git_login)->toBe('Maria-Rossi')
        ->and($user->handle)->toBe('maria-rossi')
        ->and($user->git_token)->toBe('github_pat_11AAAAAAA0123456789abcdef')
        ->and($user->getRawOriginal('git_token'))->not->toContain('github_pat_')
        ->and($user->hasGit())->toBeTrue();
});

test('when the GitHub login is already a handle, or spells an existing project host, a free one is picked', function () {
    User::factory()->create(['handle' => 'maria']);
    Project::factory()->create(['slug' => 'maria-2-shop']);
    Project::factory()->create(['slug' => 'shop']);
    $user = User::factory()->create(['handle' => null]);
    Http::fake(['https://api.github.com/user' => Http::response(['login' => 'Maria', 'name' => 'Maria Rossi'])]);

    Livewire::actingAs($user)
        ->test(Connections::class)
        ->set('git_token', 'github_pat_11AAAAAAA0123456789abcdef')
        ->call('saveGit')
        ->assertHasNoErrors();

    // "maria" is taken, "maria-2" would make the previews of "shop" land on the project "maria-2-shop"
    expect($user->fresh()->handle)->toBe('maria-3');
});

test('an invalid GitHub token is refused with GitHub\'s answer', function () {
    $user = User::factory()->create();
    Http::fake(['https://api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401)]);

    Livewire::actingAs($user)
        ->test(Connections::class)
        ->set('git_token', 'github_pat_definitely_not_valid_000')
        ->call('saveGit')
        ->assertHasErrors(['git_token']);

    expect($user->fresh()->git_token)->toBeNull();
});

test('a Claude credential is stored with its kind and pushed to existing native workspaces', function () {
    Queue::fake();
    $user = User::factory()->create();
    Workspace::factory()->create(['user_id' => $user->id, 'driver' => 'native', 'path' => '/srv/x']);

    Livewire::actingAs($user)
        ->test(Connections::class)
        ->set('claude_auth_mode', 'api_key')
        ->set('claude_token', 'sk-ant-api03-0123456789abcdefghijklmnop')
        ->call('saveClaude')
        ->assertHasNoErrors();

    expect($user->fresh()->claude_auth_mode)->toBe(ClaudeAuthMode::ApiKey)
        ->and($user->fresh()->claude_token)->toBe('sk-ant-api03-0123456789abcdefghijklmnop')
        ->and($user->fresh()->hasClaude())->toBeTrue();

    Queue::assertPushed(SyncWorkspaceCredentials::class, fn (SyncWorkspaceCredentials $job) => $job->user->is($user));
});

test('the connections page is where a new person starts', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('connections.edit'))->assertOk()->assertSee('claude setup-token')->assertSee('github_pat_');
});
