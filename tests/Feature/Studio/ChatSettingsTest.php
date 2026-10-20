<?php

use App\Actions\Studio\StartConversation;
use App\Livewire\Studio\Chat;
use App\Models\Conversation;
use App\Models\CursorAccount;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function pmWithConversation(): array
{
    $user = User::factory()->pm()->create();
    $project = Project::factory()->create();
    $project->members()->attach($user);
    $workspace = Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $user->id]);
    $conversation = Conversation::factory()->forWorkspace($workspace)->create();

    return [$user, $conversation];
}

test('model, effort and mode are saved on the conversation and reach the bridge with the next message', function () {
    [$user, $conversation] = pmWithConversation();
    Http::fake(['http://bridge.test:4455/*' => Http::response(['accepted' => true], 202)]);

    Livewire::actingAs($user)
        ->test(Chat::class, ['conversation' => $conversation])
        ->set('model', 'claude-opus-5-5')
        ->set('effort', 'high')
        ->set('mode', 'auto')
        ->call('saveSettings')
        ->assertHasNoErrors()
        ->set('draft', 'go')
        ->call('send');

    $conversation->refresh();
    expect($conversation->model)->toBe('claude-opus-5-5')
        ->and($conversation->effort)->toBe('high')
        ->and($conversation->permission_mode)->toBe('auto')
        ->and($conversation->modeLabel())->toBe('Autopilot');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/turns')
        && $request['model'] === 'claude-opus-5-5'
        && $request['effort'] === 'high'
        && $request['permission_mode'] === 'auto');
});

test('a role may only switch to the modes it is allowed', function () {
    config(['studio.modes_by_role.pm' => ['default', 'plan']]);
    [$user, $conversation] = pmWithConversation();

    Livewire::actingAs($user)
        ->test(Chat::class, ['conversation' => $conversation])
        ->set('mode', 'auto')
        ->call('saveSettings')
        ->assertHasErrors(['mode']);

    expect($conversation->fresh()->permission_mode)->toBe('default');
});

test('a new conversation copies the person\'s agent, and switching to Cursor forgets the other session', function () {
    [$user, $conversation] = pmWithConversation();
    $user->forceFill(['agent' => 'cursor'])->save();
    CursorAccount::factory()->for($user)->create(['name' => 'Work', 'dir' => '.cursor-work', 'signed_in' => true]);

    $started = app(StartConversation::class)->handle($conversation->workspace, $user->fresh());
    expect($started->agent)->toBe('cursor');

    $conversation->forceFill(['agent_session_id' => 'sess-claude'])->save();
    Http::fake(['http://bridge.test:4455/*' => Http::response(['accepted' => true], 202)]);

    Livewire::actingAs($user)
        ->test(Chat::class, ['conversation' => $conversation])
        ->set('agent', 'cursor')
        ->call('saveSettings')
        ->assertHasNoErrors()
        ->set('draft', 'go')
        ->call('send');

    expect($conversation->fresh()->agent)->toBe('cursor')
        ->and($conversation->fresh()->agent_session_id)->toBeNull();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/turns')
        && $request['agent'] === 'cursor'
        && $request['cursor_dir'] === '.cursor-work'
        && $request['session_id'] === null
        && ! array_key_exists('api_key', $request->data()));
});

test('an unknown model is refused', function () {
    [$user, $conversation] = pmWithConversation();

    Livewire::actingAs($user)
        ->test(Chat::class, ['conversation' => $conversation])
        ->set('model', 'gpt-99')
        ->call('saveSettings')
        ->assertHasErrors(['model']);
});
