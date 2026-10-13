<?php

use App\Enums\ConversationStatus;
use App\Livewire\Studio\Chat;
use App\Livewire\Studio\ConversationList;
use App\Livewire\Studio\Shell;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

test('a conversation is deleted only after the confirmation, with its messages, and its Claude Code is stopped', function () {
    [$user, $project, $workspace] = listOwner();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create(['title' => 'Da buttare', 'status' => ConversationStatus::Running]);
    $conversation->messages()->create(['role' => 'user', 'kind' => 'text', 'content' => 'ciao']);

    Http::fake(["http://bridge.test:4455/conversations/{$conversation->id}" => Http::response(['forgotten' => true])]);

    $list = Livewire::actingAs($user)
        ->test(ConversationList::class, ['project' => $project, 'workspace' => $workspace, 'current' => $conversation->uuid])
        ->call('confirmDelete', $conversation->uuid)
        ->assertSet('deleting', $conversation->uuid)
        ->assertSee('Delete this conversation?')
        ->assertSee('Claude is still working on it: it will be stopped.');

    // asking is not deleting
    expect(Conversation::query()->whereKey($conversation->id)->exists())->toBeTrue();

    $list->call('delete')->assertRedirect(route('studio.show', $project));

    expect(Conversation::query()->whereKey($conversation->id)->exists())->toBeFalse()
        ->and(Message::query()->where('conversation_id', $conversation->id)->exists())->toBeFalse();
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === "http://bridge.test:4455/conversations/{$conversation->id}");
});

test('a workspace out of reach does not keep a conversation alive', function () {
    [$user, $project, $workspace] = listOwner();
    $open = Conversation::factory()->forWorkspace($workspace)->create();
    $other = Conversation::factory()->forWorkspace($workspace)->create();

    Http::fake(fn () => throw new ConnectionException('refused'));

    Livewire::actingAs($user)
        ->test(ConversationList::class, ['project' => $project, 'workspace' => $workspace, 'current' => $open->uuid])
        ->call('confirmDelete', $other->uuid)
        ->call('delete')
        ->assertNoRedirect();

    expect(Conversation::query()->whereKey($other->id)->exists())->toBeFalse()
        ->and(Conversation::query()->whereKey($open->id)->exists())->toBeTrue();
});

test('nobody deletes someone else\'s conversation, not even an administrator', function () {
    [$owner, $project, $workspace] = listOwner();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(ConversationList::class, ['project' => $project, 'workspace' => $workspace])
        ->call('confirmDelete', $conversation->uuid)
        ->assertForbidden();

    Livewire::actingAs($admin)
        ->test(ConversationList::class, ['project' => $project, 'workspace' => $workspace])
        ->set('deleting', $conversation->uuid)
        ->call('delete')
        ->assertForbidden();

    expect($conversation->fresh())->not->toBeNull();
});
