<?php

use App\Enums\ConversationStatus;
use App\Livewire\Studio\Chat;
use App\Livewire\Studio\ConversationList;
use App\Livewire\Studio\Shell;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

function listOwner(): array
{
    $user = User::factory()->pm()->create();
    $project = Project::factory()->create();
    $project->members()->attach($user);
    $workspace = Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $user->id]);

    return [$user, $project, $workspace];
}

test('the dot says working, asking, failed, done or quiet', function () {
    [, , $workspace] = listOwner();
    $make = fn (array $state) => Conversation::factory()->forWorkspace($workspace)->create($state);

    expect($make(['status' => ConversationStatus::Running])->signal())->toBe('working')
        ->and($make(['status' => ConversationStatus::WaitingPermission])->signal())->toBe('asking')
        ->and($make(['status' => ConversationStatus::Failed])->signal())->toBe('failed')
        // never ran: nothing to see
        ->and($make(['status' => ConversationStatus::Idle])->signal())->toBe('quiet')
        // finished after the person last opened it
        ->and($make(['last_result' => ['subtype' => 'success'], 'last_activity_at' => now(), 'seen_at' => now()->subMinute()])->signal())->toBe('done')
        // finished, then opened
        ->and($make(['last_result' => ['subtype' => 'success'], 'last_activity_at' => now()->subMinute(), 'seen_at' => now()])->signal())->toBe('quiet');
});

test('opening a finished conversation turns its dot grey and keeps the list order', function () {
    [$user, $project, $workspace] = listOwner();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create([
        'last_result' => ['subtype' => 'success'],
        'last_activity_at' => now()->subMinute(),
    ]);
    $updated = $conversation->updated_at;

    $this->travel(5)->minutes();

    Livewire::actingAs($user)->test(Shell::class, ['project' => $project, 'conversation' => $conversation]);

    $conversation->refresh();
    expect($conversation->signal())->toBe('quiet')
        ->and($conversation->updated_at->equalTo($updated))->toBeTrue();
});

test('the list follows its conversations: polled while one works, redrawn when the open one moves on', function () {
    [$user, $project, $workspace] = listOwner();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create(['title' => 'Filtro date']);

    $list = Livewire::actingAs($user)
        ->test(ConversationList::class, ['project' => $project, 'workspace' => $workspace, 'current' => $conversation->uuid])
        ->assertSee('Filtro date')
        ->assertSeeHtml('data-signal="quiet"')
        ->assertSeeHtml('wire:poll.30s.visible');

    $conversation->setStatus(ConversationStatus::Running);

    $list->dispatch('conversation-signal')
        ->assertSeeHtml('data-signal="working"')
        ->assertSeeHtml('wire:poll.3s');

    $conversation->forceFill(['status' => ConversationStatus::Idle, 'last_result' => ['subtype' => 'success'], 'last_activity_at' => now()->addSecond()])->save();

    $list->call('refreshList')->assertSeeHtml('data-signal="done"');
});

test('the chat tells the list when its dot changes', function () {
    [$user, , $workspace] = listOwner();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create();

    $chat = Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation]);

    $chat->call('poll')->assertNotDispatched('conversation-signal');

    $conversation->setStatus(ConversationStatus::WaitingPermission);

    $chat->call('poll')->assertDispatched('conversation-signal')->assertSet('signal', 'asking');
});
