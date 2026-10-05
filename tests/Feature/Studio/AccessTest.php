<?php

use App\Enums\WorkspaceStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;

test('a non-member cannot open a project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $this->actingAs($user)->get(route('studio.show', $project))->assertForbidden();
});

test('opening a project provisions a running local workspace for the member', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $project->members()->attach($user);

    $this->actingAs($user)->get(route('studio.show', $project))->assertOk();

    $workspace = Workspace::query()->where('project_id', $project->id)->where('user_id', $user->id)->firstOrFail();

    expect($workspace->status)->toBe(WorkspaceStatus::Running)
        ->and($workspace->driver)->toBe('local')
        ->and($workspace->bridge_url)->toBe('http://bridge.test:4455')
        ->and($workspace->bridge_token)->toBe('test-bridge-token')
        ->and($workspace->app_url)->toBe('http://preview.test')
        ->and($workspace->callback_token)->not->toBeNull()
        ->and(Workspace::findByCallbackToken((string) $workspace->callback_token)?->id)->toBe($workspace->id);
});

test('a client can look at the project but gets no chat', function () {
    $client = User::factory()->client()->create();
    $project = Project::factory()->create();
    $project->members()->attach($client);

    $this->actingAs($client)
        ->get(route('studio.show', $project))
        ->assertOk()
        ->assertSee('Follow the project')
        ->assertDontSee('New conversation');

    // A record exists so the page has something to show, but nothing was provisioned for a role that cannot chat.
    $workspace = Workspace::query()->where('user_id', $client->id)->firstOrFail();

    expect($workspace->status)->toBe(WorkspaceStatus::New)
        ->and($workspace->bridge_url)->toBeNull();
});

test('a member cannot open another member\'s conversation', function () {
    $project = Project::factory()->create();
    [$a, $b] = User::factory()->count(2)->create();
    $project->members()->attach([$a->id, $b->id]);

    $workspaceB = Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $b->id]);
    $conversation = $workspaceB->conversations()->create(['user_id' => $b->id, 'title' => 'Segreta']);

    $this->actingAs($a)->get(route('studio.conversation', [$project, $conversation]))->assertForbidden();
});

test('admin pages are closed to developers', function () {
    $dev = User::factory()->create();

    $this->actingAs($dev)->get(route('admin.projects'))->assertForbidden();
    $this->actingAs($dev)->get(route('admin.users'))->assertForbidden();
});
