<?php

use App\Claude\ModelName;
use App\Enums\ClaudeAuthMode;
use App\Livewire\Studio\Chat;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

function chatFor(User $user, array $conversation = []): Conversation
{
    $workspace = Workspace::factory()->running()->create(['user_id' => $user->id]);

    return Conversation::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, ...$conversation]);
}

test('on an API key the bar says so and shows the estimated cost of the last turn', function () {
    $user = User::factory()->create(['claude_auth_mode' => ClaudeAuthMode::ApiKey, 'claude_token' => 'sk-ant-api03-key']);
    $conversation = chatFor($user, [
        'model' => null, 'effort' => 'high', 'permission_mode' => 'auto',
        'runtime' => ['model' => 'claude-haiku-4-5-20251001', 'auth' => 'api_key'],
        'last_result' => ['total_cost_usd' => 0.0712],
    ]);

    Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation])
        ->assertSee('API key')
        ->assertSee('0.07 $')
        ->assertSee('Default')
        ->assertSee('Haiku 4.5')
        ->assertSee('effort high')
        ->assertSee($conversation->modeLabel())
        ->assertDontSeeText('claude-haiku-4-5-20251001');
});

test('on a subscription the bar shows the plan\'s usage, never a cost', function () {
    $user = User::factory()->create([
        'claude_auth_mode' => ClaudeAuthMode::Subscription, 'claude_signed_in' => true,
        'claude_usage' => ['status' => 'allowed_warning', 'percent' => 82, 'resets_at' => now()->addHours(2)->toIso8601String(), 'at' => now()->toIso8601String()],
    ]);
    $conversation = chatFor($user, ['model' => 'claude-opus-5-5', 'last_result' => ['total_cost_usd' => 0.0712], 'runtime' => ['auth' => 'subscription']]);

    Livewire::actingAs($user)->test(Chat::class, ['conversation' => $conversation])
        ->assertSee('Subscription')
        ->assertSee('5h 82%')
        ->assertSee('resets')
        ->assertSee('Opus 5.5')
        ->assertSee('effort default')
        ->assertDontSee('0.07 $')
        ->assertDontSee('last turn');
});

test('without a connected account the bar says so', function () {
    $user = User::factory()->create(['claude_auth_mode' => ClaudeAuthMode::Subscription, 'claude_signed_in' => false]);

    Livewire::actingAs($user)->test(Chat::class, ['conversation' => chatFor($user)])->assertSee('No Claude account');
});

test('the bridge\'s init and rate limit events become what the bar shows', function () {
    $user = User::factory()->create(['claude_auth_mode' => ClaudeAuthMode::Subscription, 'claude_signed_in' => true]);
    $conversation = chatFor($user);
    $workspace = $conversation->workspace;

    $this->withToken((string) $workspace->callback_token)->postJson(route('api.bridge.events'), [
        'conversation' => (string) $conversation->id,
        'events' => [
            ['type' => 'init', 'session_id' => 's', 'model' => 'claude-sonnet-5-5', 'api_key_source' => 'none'],
            ['type' => 'status', 'status' => 'rate_limit', 'info' => ['status' => 'allowed', 'utilization' => 0.42, 'resetsAt' => now()->addHour()->getTimestamp()]],
        ],
    ])->assertOk();

    expect($conversation->fresh()->runtime)->toMatchArray(['model' => 'claude-sonnet-5-5', 'auth' => 'subscription'])
        ->and($user->fresh()->claude_usage)->toMatchArray(['status' => 'allowed', 'percent' => 42]);

    $this->withToken((string) $workspace->callback_token)->postJson(route('api.bridge.events'), [
        'conversation' => (string) $conversation->id,
        'events' => [['type' => 'init', 'session_id' => 's', 'api_key_source' => 'ANTHROPIC_API_KEY']],
    ])->assertOk();
    expect($conversation->fresh()->runtime['auth'])->toBe('api_key');
});

test('model ids read the way people say them', function () {
    expect(ModelName::label('claude-haiku-4-5-20251001'))->toBe('Haiku 4.5')
        ->and(ModelName::label('claude-sonnet-5'))->toBe('Sonnet 5')
        ->and(ModelName::label('claude-opus-5-5'))->toBe('Opus 5.5')
        ->and(ModelName::label('claude-sonnet-4-20250514'))->toBe('Sonnet 4')
        ->and(ModelName::label(null))->toBeNull()
        ->and(ModelName::label('something-else'))->toBe('something-else');
});

test('after a turn the bridge brings what /usage shows: the five hours and the week, in percent', function () {
    $user = User::factory()->create(['claude_auth_mode' => ClaudeAuthMode::Subscription, 'claude_signed_in' => true]);
    $conversation = chatFor($user, ['runtime' => ['auth' => 'subscription']]);
    $workspace = $conversation->workspace;
    $post = fn (array $events) => $this->withToken((string) $workspace->callback_token)
        ->postJson(route('api.bridge.events'), ['conversation' => (string) $conversation->id, 'events' => $events])
        ->assertOk();

    // during the turn: within limits, no percentage
    $post([['type' => 'status', 'status' => 'rate_limit', 'info' => ['status' => 'allowed', 'rateLimitType' => 'five_hour', 'resetsAt' => now()->addHours(3)->getTimestamp()]]]);
    expect($user->fresh()->claude_usage['percent'])->toBeNull();

    // after it: the windows
    $post([['type' => 'plan_usage', 'subscription_type' => 'max', 'rate_limits' => [
        'five_hour' => ['utilization' => 23.4, 'resets_at' => now()->addHours(3)->toIso8601String()],
        'seven_day' => ['utilization' => 41, 'resets_at' => now()->addDays(4)->toIso8601String()],
    ]]]);
    expect($user->fresh()->claude_usage)->toMatchArray(['status' => 'allowed', 'percent' => 23, 'week_percent' => 41, 'plan' => 'max']);

    // the next turn's event without a percentage does not forget the one of its window
    $post([['type' => 'status', 'status' => 'rate_limit', 'info' => ['status' => 'allowed', 'rateLimitType' => 'five_hour', 'resetsAt' => now()->addHours(3)->getTimestamp()]]]);
    expect($user->fresh()->claude_usage)->toMatchArray(['percent' => 23, 'week_percent' => 41]);

    Livewire::actingAs($user->fresh())->test(Chat::class, ['conversation' => $conversation->fresh()])
        ->assertSee('5h 23%')
        ->assertSee('week 41%')
        ->assertSee('Five-hour window: 23% used');
});

test('a weekly warning lands on the week, not on the five hours', function () {
    $user = User::factory()->create(['claude_auth_mode' => ClaudeAuthMode::Subscription, 'claude_signed_in' => true]);
    $conversation = chatFor($user);

    $this->withToken((string) $conversation->workspace->callback_token)->postJson(route('api.bridge.events'), [
        'conversation' => (string) $conversation->id,
        'events' => [['type' => 'status', 'status' => 'rate_limit', 'info' => ['status' => 'allowed_warning', 'rateLimitType' => 'seven_day', 'utilization' => 0.85, 'resetsAt' => now()->addDays(2)->getTimestamp()]]],
    ])->assertOk();

    expect($user->fresh()->claude_usage)->toMatchArray(['status' => 'allowed_warning', 'week_percent' => 85])
        ->and($user->fresh()->claude_usage['percent'] ?? null)->toBeNull();
});
