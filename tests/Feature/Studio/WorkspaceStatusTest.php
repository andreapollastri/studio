<?php

use App\Enums\WorkspaceStatus as Status;
use App\Events\WorkspaceUpdated;
use App\Jobs\StartWorkspace;
use App\Jobs\StopWorkspace;
use App\Livewire\Studio\WorkspaceStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('start turns the badge to "starting" at once and polls until the workspace runs', function () {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'status' => Status::Stopped, 'path' => '/srv/ws']);
    $workspace->project->members()->attach($user);

    $badge = Livewire::actingAs($user)->test(WorkspaceStatus::class, ['workspace' => $workspace])
        ->assertSee('stopped')
        ->call('start')
        ->assertSee('starting')
        ->assertSeeHtml('wire:poll.3s="refreshStatus"')
        ->assertDispatched('workspace-updated', status: 'starting');

    Queue::assertPushed(StartWorkspace::class);

    // The job finishes elsewhere; the next poll (or the broadcast) brings it in, and tells the page.
    $workspace->forceFill(['status' => Status::Running])->save();
    $badge->call('refreshStatus')
        ->assertSee('running')
        ->assertDispatched('workspace-updated', status: 'running')
        ->assertDontSeeHtml('wire:poll.3s');

    $badge->call('stop')->assertSee('stopping');
    Queue::assertPushed(StopWorkspace::class);
});

test('a status change is broadcast on the workspace channel, to its owner and administrators only', function () {
    Event::fake([WorkspaceUpdated::class]);
    $workspace = Workspace::factory()->create(['status' => Status::Stopped]);

    $workspace->forceFill(['status' => Status::Starting])->save();
    $workspace->forceFill(['last_seen_at' => now()])->save();

    Event::assertDispatchedTimes(WorkspaceUpdated::class, 1);
    Event::assertDispatched(WorkspaceUpdated::class, fn (WorkspaceUpdated $e) => $e->broadcastOn()->name === 'private-workspaces.'.$workspace->uuid && $e->status === 'starting');
});

test('only one visit claims a workspace to create, so it is provisioned once', function () {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'status' => Status::New]);

    expect($workspace->claimForProvisioning())->toBeTrue()
        ->and($workspace->status)->toBe(Status::Creating)
        ->and($workspace->fresh()->claimForProvisioning())->toBeFalse();

    $workspace->markFailed('boom');
    expect($workspace->claimForProvisioning())->toBeTrue();
});

test('links and channels carry uuids, not ids', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $project->members()->attach($user);
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id, 'project_id' => $project->id]);
    $conversation = $workspace->conversations()->create(['user_id' => $user->id]);

    expect(route('studio.conversation', [$project, $conversation]))->toEndWith('/c/'.$conversation->uuid)
        ->and($conversation->uuid)->toMatch('/^[0-9a-f-]{36}$/');

    $this->actingAs($user)->get(route('studio.conversation', [$project, $conversation]))->assertOk();
    $this->actingAs($user)->get('/p/'.$project->slug.'/c/'.$conversation->id)->assertNotFound();
});
