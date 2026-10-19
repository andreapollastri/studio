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
        ->and($conversation->model)->toBeNull()
        ->and($conversation->runtime['model'])->toBe('claude-sonnet-5')
        ->and($conversation->status)->toBe(ConversationStatus::WaitingPermission)
        ->and($conversation->messages()->pluck('kind')->all())->toBe(['text', 'tool_use', 'tool_result'])
        ->and($conversation->pendingPermission()?->summary())->toBe('npm run build');

    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $e) => $e->kind === 'text_delta' && $e->data['text'] === 'Ho ');
    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $e) => $e->kind === 'messages');

    // The person allows it from the chat: the bridge is told, the conversation runs again.
    Http::fake(['http://bridge.test:4455/*' => Http::response(['ok' => true])]);

    Livewire::actingAs($user)
        ->test(Chat::class, ['conversation' => $conversation])
        ->call('allow', $conversation->pendingPermission()->uuid);

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

test('a process that dies shows its reason once, not as error and result both', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create(['status' => ConversationStatus::Running]);
    $reason = 'Claude is not signed in, or its credentials are no longer valid.';

    $this->withToken((string) $workspace->callback_token)->postJson(route('api.bridge.events'), [
        'conversation' => (string) $conversation->id,
        'events' => [
            ['type' => 'error', 'code' => 'process_exit', 'message' => $reason],
            ['type' => 'result', 'subtype' => 'error_during_execution', 'is_error' => true, 'result' => $reason],
        ],
    ])->assertOk();

    expect($conversation->messages()->where('kind', 'notice')->pluck('content')->all())->toBe([$reason])
        ->and($conversation->refresh()->status)->toBe(ConversationStatus::Failed);
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

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/skills'));
});

test('"/" lists the conversation\'s skills and commands, read from the bridge once a minute; Enter sends', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create();

    Http::fake(['http://bridge.test:4455/skills*' => Http::response(['skills' => [
        ['name' => 'larapilot-triage', 'description' => 'Turn a request into a story', 'source' => '.claude/skills'],
        ['name' => 'deploy-check', 'description' => 'Check a deploy', 'source' => '.claude/commands', 'argument_hint' => '[environment]'],
        ['description' => 'no name: dropped'],
    ]])]);

    $chat = Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation])
        ->assertSee('x-data="chatComposer"', false)
        ->assertSee('⏎ to send, ⇧⏎ new line, / for skills');

    $chat->call('skills')->assertReturned(['skills' => [
        ['name' => 'larapilot-triage', 'description' => 'Turn a request into a story', 'source' => '.claude/skills'],
        ['name' => 'deploy-check', 'description' => 'Check a deploy', 'source' => '.claude/commands', 'argument_hint' => '[environment]'],
    ], 'error' => null]);
    $chat->call('skills');

    Http::assertSentCount(1);

    // a stopped workspace has none, without asking the bridge
    $workspace->forceFill(['status' => 'stopped'])->save();
    cache()->forget('studio.skills.'.$conversation->id);
    Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation->fresh()])->call('skills')->assertReturned(['skills' => [], 'error' => 'The workspace is not running.']);
    Http::assertSentCount(1);
});

test('when the bridge cannot list the skills, "/" says why, and nothing is kept to ask again', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create();
    Http::fake(['http://bridge.test:4455/skills*' => Http::sequence()
        ->push(['error' => 'boom'], 500)
        ->push(['skills' => [['name' => 'larapilot-inception', 'description' => 'PRD', 'source' => '.claude/skills']]])]);

    $chat = Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation]);
    $chat->call('skills')->assertReturned(fn (array $answer) => $answer['skills'] === [] && str_contains((string) $answer['error'], 'could not be read') && str_contains((string) $answer['error'], 'boom'));
    $chat->call('skills')->assertReturned(fn (array $answer) => $answer['error'] === null && $answer['skills'][0]['name'] === 'larapilot-inception');
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
        ->assertSee('Claude has 2 questions for you')
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
        ->call('answer', $request->uuid)
        ->assertHasErrors(['choices.0', 'choices.1'])
        ->assertDispatched('question-step', step: 0)
        ->set('choices.0', 'MAX')
        ->call('answer', $request->uuid)
        ->assertHasErrors(['choices.1'])
        ->assertDispatched('question-step', step: 1)
        ->set('choices.1', ['Admin', 'API', 'Not an option'])
        ->set('others.1', 'the webhooks')
        ->call('answer', $request->uuid)
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
        ->call('skip', $request->uuid);

    Http::assertSent(fn (Request $sent) => str_ends_with($sent->url(), '/permissions/req_q')
        && $sent['behavior'] === 'deny'
        && str_contains((string) $sent['message'], 'own judgement')
        && ! isset($sent['answers']));

    expect($request->refresh()->status)->toBe(PermissionStatus::Denied);
});

test('about to write, the bridge starts the CLI of the next turn with the turn\'s settings', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create(['model' => 'claude-opus-5-5']);
    Http::fake(['http://bridge.test:4455/*' => Http::response(['warmed' => true])]);

    Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation])->call('warm');
    app()->terminate();

    Http::assertSent(fn (Request $sent) => $sent->url() === "http://bridge.test:4455/conversations/{$conversation->id}/warm"
        && $sent['model'] === 'claude-opus-5-5' && ! isset($sent['text']) && isset($sent['callback']['token']) && is_array($sent['allowed_tools']));
});

test('no warming while Claude works', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create(['status' => ConversationStatus::Running]);
    Http::fake();

    Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation])->call('warm');
    app()->terminate();

    Http::assertNotSent(fn (Request $sent) => str_ends_with($sent->url(), '/warm'));
});

test('a turn Claude starts on its own (background subagents finishing) ends like any other: the person can write again', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create(['status' => ConversationStatus::Idle]);
    $post = fn (array $events) => $this->withToken((string) $workspace->callback_token)->postJson(route('api.bridge.events'), ['conversation' => (string) $conversation->id, 'events' => $events])->assertOk();

    // woken: it writes, which marks the conversation working…
    $post([['type' => 'text', 'text' => 'Riprendo dal punto in cui ero: i due inventari del legacy sono arrivati.', 'message_id' => 'msg_w']]);
    expect($conversation->fresh()->status)->toBe(ConversationStatus::Running);

    // …and its result, marked woken, sets it free
    $post([['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'session_id' => 'sess-1', 'woken' => true, 'result' => 'Domande per te']]);
    expect($conversation->fresh()->status)->toBe(ConversationStatus::Idle);
});

test('Interrupt frees a conversation the bridge has nothing running for', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $conversation = Conversation::factory()->forWorkspace($workspace)->create(['status' => ConversationStatus::Running]);
    Http::fake(['http://bridge.test:4455/*' => Http::response(['key' => (string) $conversation->id, 'state' => 'idle', 'alive' => true, 'pending_permissions' => []])]);

    Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation])->call('interrupt');

    expect($conversation->fresh()->status)->toBe(ConversationStatus::Idle);
});

test('while the bridge still works on a turn, Interrupt leaves the state to the events that will come', function () {
    [$user, $project, $workspace] = memberWithRunningWorkspace();
    $busy = Conversation::factory()->forWorkspace($workspace)->create(['status' => ConversationStatus::Running]);
    Http::fake(['http://bridge.test:4455/*' => Http::response(['state' => 'running'])]);

    Livewire::actingAs($user)->test(Chat::class, ['conversation' => $busy])->call('interrupt');

    expect($busy->fresh()->status)->toBe(ConversationStatus::Running);
});
