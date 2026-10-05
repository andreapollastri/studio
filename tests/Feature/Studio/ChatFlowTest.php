<?php

use App\Enums\ConversationStatus;
use App\Enums\PermissionStatus;
use App\Events\ConversationUpdated;
use App\Livewire\Studio\Chat;
use App\Livewire\Studio\Shell;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PermissionRequest;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function memberWithRunningWorkspace(): array
{
    $user = User::factory()->pm()->create();
    $project = Project::factory()->create(['name' => 'Shop']);
    $project->members()->attach($user);
    $workspace = Workspace::factory()->running()->create(['project_id' => $project->id, 'user_id' => $user->id]);

    return [$user, $project, $workspace];
}

test('a new conversation is created from the shell and uses the role permission mode', function () {
    [$user, $project] = memberWithRunningWorkspace();

    Livewire::actingAs($user)
        ->test(Shell::class, ['project' => $project])
        ->call('newConversation')
        ->assertRedirect();

    $conversation = Conversation::query()->firstOrFail();

    expect($conversation->user_id)->toBe($user->id)
        ->and($conversation->permission_mode)->toBe('default')
        ->and($conversation->status)->toBe(ConversationStatus::Idle);
});

test('sending a message stores it, marks the conversation running and hands it to the bridge with the callback', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create();

    Http::fake(['http://bridge.test:4455/*' => Http::response(['accepted' => true], 202)]);

    Livewire::actingAs($user)
        ->test(Chat::class, ['conversation' => $conversation])
        ->set('draft', 'Il cliente vuole filtrare gli ordini per data')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('draft', '');

    $conversation->refresh();

    expect($conversation->status)->toBe(ConversationStatus::Running)
        ->and($conversation->title)->toBe('Il cliente vuole filtrare gli ordini per data')
        ->and($conversation->messages()->count())->toBe(1)
        ->and($conversation->messages()->first()->role)->toBe(Message::ROLE_USER);

    Http::assertSent(function (Request $request) use ($conversation, $workspace) {
        return $request->url() === "http://bridge.test:4455/conversations/{$conversation->id}/turns"
            && $request->hasHeader('Authorization', 'Bearer bridge-secret')
            && $request['text'] === 'Il cliente vuole filtrare gli ordini per data'
            && $request['permission_mode'] === 'default'
            && $request['callback']['token'] === $workspace->callback_token
            && str_ends_with($request['callback']['url'], '/api/bridge/events');
    });
});

test('a slash command title drops the command itself', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create();

    Http::fake(['http://bridge.test:4455/*' => Http::response(['accepted' => true], 202)]);

    Livewire::actingAs($user)
        ->test(Chat::class, ['conversation' => $conversation])
        ->set('draft', '/larapilot-triage il filtro per data è lento')
        ->call('send');

    expect($conversation->fresh()->title)->toBe('il filtro per data è lento');
});

test('sending to a stopped workspace is refused with a readable error', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $workspace->forceFill(['status' => 'stopped'])->save();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create();

    Http::fake();

    Livewire::actingAs($user)
        ->test(Chat::class, ['conversation' => $conversation])
        ->set('draft', 'ciao')
        ->call('send')
        ->assertHasErrors(['draft']);

    Http::assertNothingSent();
    expect($conversation->messages()->count())->toBe(0);
});

test('a second message while Claude is still working is refused: one turn at a time', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create(['status' => ConversationStatus::Running]);

    Http::fake();

    Livewire::actingAs($user)
        ->test(Chat::class, ['conversation' => $conversation])
        ->set('draft', 'and also this')
        ->call('send')
        ->assertHasErrors(['draft']);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/turns'));
    expect($conversation->messages()->count())->toBe(0);
});

test('interrupting when the bridge is gone marks the conversation failed so the person can write again', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create(['status' => ConversationStatus::Running]);

    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    Livewire::actingAs($user)
        ->test(Chat::class, ['conversation' => $conversation])
        ->call('interrupt');

    expect($conversation->fresh()->status)->toBe(ConversationStatus::Failed)
        ->and($conversation->fresh()->isBusy())->toBeFalse();
});

test('when the bridge is unreachable the conversation fails with a notice', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create();

    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    Livewire::actingAs($user)
        ->test(Chat::class, ['conversation' => $conversation])
        ->set('draft', 'ciao')
        ->call('send');

    $conversation->refresh();

    expect($conversation->status)->toBe(ConversationStatus::Failed)
        ->and($conversation->messages()->where('kind', Message::KIND_NOTICE)->first()?->content)->toContain('not answering');
});

test('events posted by the bridge become messages, a pending permission, then an idle result', function () {
    Event::fake([ConversationUpdated::class]);

    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create(['status' => ConversationStatus::Running]);

    $post = fn (array $events) => $this->withToken((string) $workspace->callback_token)->postJson(route('api.bridge.events'), [
        'conversation' => (string) $conversation->id,
        'events' => $events,
    ]);

    $post([
        ['type' => 'init', 'session_id' => 'sess-123', 'model' => 'claude-sonnet-5'],
        ['type' => 'text_delta', 'text' => 'Ho '],
        ['type' => 'text', 'text' => 'Ho letto la richiesta.', 'message_id' => 'msg_1'],
        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Bash', 'input' => ['command' => 'php artisan larapilot:spec-list']],
        ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => "US-001 TODO\n", 'is_error' => false],
        ['type' => 'permission_request', 'request_id' => 'req_9', 'tool_name' => 'Bash', 'input' => ['command' => 'npm run build'], 'description' => 'Build the assets'],
    ])->assertOk()->assertJson(['ok' => true, 'applied' => 6]);

    $conversation->refresh();

    expect($conversation->agent_session_id)->toBe('sess-123')
        ->and($conversation->model)->toBe('claude-sonnet-5')
        ->and($conversation->status)->toBe(ConversationStatus::WaitingPermission)
        ->and($conversation->messages()->pluck('kind')->all())->toBe(['text', 'tool_use', 'tool_result'])
        ->and($conversation->pendingPermission()?->summary())->toBe('npm run build');

    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $e) => $e->kind === 'text_delta' && $e->data['text'] === 'Ho ');
    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $e) => $e->kind === 'messages');

    // The person allows it from the chat: the bridge is told, the conversation runs again.
    Http::fake(['http://bridge.test:4455/*' => Http::response(['ok' => true])]);

    Livewire::actingAs($user)
        ->test(Chat::class, ['conversation' => $conversation])
        ->call('allow', $conversation->pendingPermission()->id);

    Http::assertSent(fn (Request $request) => $request->url() === "http://bridge.test:4455/conversations/{$conversation->id}/permissions/req_9"
        && $request['behavior'] === 'allow');

    $conversation->refresh();
    expect($conversation->status)->toBe(ConversationStatus::Running)
        ->and($conversation->permissionRequests()->first()->status)->toBe(PermissionStatus::Allowed)
        ->and($conversation->permissionRequests()->first()->decided_by)->toBe($user->id);

    $post([
        ['type' => 'text', 'text' => 'Fatto: assets ricompilati.'],
        ['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'session_id' => 'sess-123', 'duration_ms' => 4200, 'num_turns' => 3, 'total_cost_usd' => 0.12],
    ])->assertOk();

    $conversation->refresh();
    expect($conversation->status)->toBe(ConversationStatus::Idle)
        ->and($conversation->last_result['total_cost_usd'])->toBe(0.12);
});

test('a failed result leaves a notice and marks the conversation failed', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create(['status' => ConversationStatus::Running]);

    $this->withToken((string) $workspace->callback_token)->postJson(route('api.bridge.events'), [
        'conversation' => (string) $conversation->id,
        'events' => [['type' => 'result', 'subtype' => 'error_during_execution', 'is_error' => true, 'result' => 'Qualcosa è andato storto']],
    ])->assertOk();

    $conversation->refresh();
    expect($conversation->status)->toBe(ConversationStatus::Failed)
        ->and($conversation->messages()->where('kind', 'notice')->first()->content)->toBe('Qualcosa è andato storto');
});

test('the bridge endpoint refuses unknown tokens and foreign conversations', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create();
    $foreign = Conversation::factory()->create();

    $this->postJson(route('api.bridge.events'), ['conversation' => $conversation->id, 'events' => [['type' => 'init']]])->assertUnauthorized();
    $this->withToken('nope')->postJson(route('api.bridge.events'), ['conversation' => $conversation->id, 'events' => [['type' => 'init']]])->assertUnauthorized();
    $this->withToken((string) $workspace->callback_token)->postJson(route('api.bridge.events'), ['conversation' => $foreign->id, 'events' => [['type' => 'init']]])->assertNotFound();
});

test('unknown event types are kept raw and hidden from the chat', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create();

    $this->withToken((string) $workspace->callback_token)->postJson(route('api.bridge.events'), [
        'conversation' => (string) $conversation->id,
        'events' => [['type' => 'rate_limit', 'window' => 'five_hour']],
    ])->assertOk();

    expect($conversation->messages()->where('kind', 'raw')->count())->toBe(1);

    Livewire::actingAs($user)
        ->test(Chat::class, ['conversation' => $conversation])
        ->assertDontSee('five_hour');
});

test('the chat has no shortcut buttons above the input: skills are typed as slash commands', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create();

    Http::fake();

    Livewire::actingAs($user)
        ->test(Chat::class, ['conversation' => $conversation])
        ->assertDontSee('New idea')
        ->assertDontSee('larapilot-triage');

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/skills'));
});

function askedQuestions(Conversation $conversation): PermissionRequest
{
    $conversation->forceFill(['status' => ConversationStatus::WaitingPermission])->save();

    return PermissionRequest::factory()->create([
        'conversation_id' => $conversation->id,
        'request_id' => 'req_q',
        'tool_name' => 'AskUserQuestion',
        'description' => null,
        'input' => ['questions' => [
            ['question' => 'Effort (current: STANDARD) — how deep should Larapilot work?', 'header' => 'Effort', 'multiSelect' => false, 'options' => [
                ['label' => 'STANDARD', 'description' => 'normal depth (default)'],
                ['label' => 'ECO', 'description' => 'save tokens'],
                ['label' => 'MAX', 'description' => 'deep on every flow'],
            ]],
            ['question' => 'Which seams should the plan cover?', 'header' => 'Seams', 'multiSelect' => true, 'options' => [
                ['label' => 'API', 'description' => 'the JSON API'],
                ['label' => 'Admin', 'description' => 'the back office'],
            ]],
        ]],
    ]);
}

test('Claude\'s questions show as a card with their options, not as a permission to allow', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create();
    askedQuestions($conversation);

    Livewire::actingAs($user)
        ->test(Chat::class, ['conversation' => $conversation])
        ->assertSee('Claude has a question for you')
        ->assertSee('how deep should Larapilot work?')
        ->assertSee('deep on every flow')
        ->assertSee('Which seams should the plan cover?')
        ->assertDontSee('Claude wants to run AskUserQuestion');
});

test('answering sends each question with the chosen labels, or the person\'s own words, to the bridge', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create();
    $request = askedQuestions($conversation);

    Http::fake(['http://bridge.test:4455/*' => Http::response(['state' => 'running'])]);

    Livewire::actingAs($user)
        ->test(Chat::class, ['conversation' => $conversation])
        ->call('answer', $request->id)
        ->assertHasErrors(['choices.0', 'choices.1'])
        ->set('choices.0', 'MAX')
        ->set('choices.1', ['Admin', 'API', 'Not an option'])
        ->set('others.1', 'the webhooks')
        ->call('answer', $request->id)
        ->assertHasNoErrors();

    Http::assertSent(fn (Request $sent) => $sent->url() === "http://bridge.test:4455/conversations/{$conversation->id}/permissions/req_q"
        && $sent['behavior'] === 'allow'
        && $sent['answers'] === [
            'Effort (current: STANDARD) — how deep should Larapilot work?' => 'MAX',
            'Which seams should the plan cover?' => 'API, Admin, the webhooks',
        ]);

    expect($request->refresh()->status)->toBe(PermissionStatus::Allowed)
        ->and($request->input['answers']['Which seams should the plan cover?'])->toBe('API, Admin, the webhooks')
        ->and($conversation->refresh()->status)->toBe(ConversationStatus::Running);
});

test('skipping Claude\'s questions lets it go on with its own judgement', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create();
    $request = askedQuestions($conversation);

    Http::fake(['http://bridge.test:4455/*' => Http::response(['state' => 'running'])]);

    Livewire::actingAs($user)
        ->test(Chat::class, ['conversation' => $conversation])
        ->call('skip', $request->id);

    Http::assertSent(fn (Request $sent) => str_ends_with($sent->url(), '/permissions/req_q')
        && $sent['behavior'] === 'deny'
        && str_contains((string) $sent['message'], 'own judgement')
        && ! isset($sent['answers']));

    expect($request->refresh()->status)->toBe(PermissionStatus::Denied);
});
