<?php

namespace App\Bridge;

use App\Accounts\Accounts;
use App\Enums\ConversationStatus;
use App\Enums\PermissionStatus;
use App\Events\ConversationUpdated;
use App\Models\ClaudeAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PermissionRequest;
use App\Models\Workspace;
use App\Support\ConversationImages;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Turns the bridge's normalised events into rows and broadcasts. The bridge
 * has already flattened Claude Code's stream-json into a handful of kinds;
 * anything it does not recognise arrives as `raw` and is kept, not shown.
 */
final class IngestBridgeEvents
{
    /**
     * @param  array<int, mixed>  $events  as decoded from the request body
     * @return int how many events were applied
     */
    public function handle(Workspace $workspace, Conversation $conversation, array $events): int
    {
        $workspace->touchSeen();
        $applied = 0;
        $kinds = [];

        foreach ($events as $event) {
            if (! is_array($event) || ! isset($event['type'])) {
                continue;
            }

            $type = (string) $event['type'];
            $applied++;

            match ($type) {
                'init' => $this->init($conversation, $event),
                'text' => $this->prose($conversation, Message::ROLE_ASSISTANT, Message::KIND_TEXT, $event),
                'thinking' => $this->prose($conversation, Message::ROLE_ASSISTANT, Message::KIND_THINKING, $event),
                'tool_use' => $this->toolUse($conversation, $event),
                'tool_result' => $this->toolResult($conversation, $event),
                'permission_request' => $this->permissionRequest($conversation, $event),
                'permission_resolved' => $this->permissionResolved($conversation, $event),
                'result' => $this->result($conversation, $event),
                'error' => $this->error($conversation, $event),
                'text_delta' => ConversationUpdated::dispatch($conversation->uuid, 'text_delta', ['text' => (string) ($event['text'] ?? '')]),
                'status' => $this->status($conversation, $event),
                'plan_usage' => $this->planUsage($conversation, $event),
                'task' => $this->task($conversation, $event),
                default => $this->raw($conversation, $event),
            };

            $kinds[] = $type;
        }

        $stored = array_values(array_diff(array_unique($kinds), ['text_delta', 'status']));

        if ($stored !== []) {
            ConversationUpdated::dispatch($conversation->uuid, 'messages', ['kinds' => $stored, 'status' => $conversation->refresh()->status->value]);
        }

        return $applied;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function init(Conversation $conversation, array $event): void
    {
        $source = $event['api_key_source'] ?? null;

        $conversation->forceFill([
            'agent_session_id' => $event['session_id'] ?? $conversation->agent_session_id,
            // What it really runs on; the model the person chose (or "default") stays as chosen.
            'runtime' => [
                'model' => $event['model'] ?? null,
                'auth' => match (true) {
                    ! is_string($source) => null,
                    $source === 'none' => 'subscription',
                    default => 'api_key',
                },
                'started_at' => now()->toIso8601String(),
            ],
            'status' => ConversationStatus::Running,
            'last_activity_at' => now(),
        ])->save();
    }

    /**
     * A status for the chat; a rate_limit one is also the CLI's word on the plan of the
     * subscription the conversation runs on, kept on that account for the status bar.
     *
     * @param  array<string, mixed>  $event
     */
    private function status(Conversation $conversation, array $event): void
    {
        $status = (string) ($event['status'] ?? '');

        if ($status === 'rate_limit' && is_array($event['info'] ?? null)) {
            $this->rateLimit($conversation, $event['info']);
        }

        ConversationUpdated::dispatch($conversation->uuid, 'status', ['status' => $status]);
    }

    /**
     * Claude Code's word on the plan as it runs a turn: whether it is within its limits, the
     * window it speaks of (five hours, or the week) and when that resets. The percentage comes
     * only near a threshold: without one, the last known for that window stays until it resets.
     *
     * @param  array<string, mixed>  $info
     */
    private function rateLimit(Conversation $conversation, array $info): void
    {
        if (! $account = $this->subscriptionOf($conversation)) {
            return;
        }

        $usage = (array) ($account->usage ?? []);
        $type = is_string($info['rateLimitType'] ?? null) ? $info['rateLimitType'] : 'five_hour';
        $usage['status'] = is_string($info['status'] ?? null) ? $info['status'] : null;
        $usage['at'] = now()->toIso8601String();

        // overage is billed credit, not a window of the plan
        if ($type !== 'overage') {
            $prefix = str_starts_with($type, 'seven_day') ? 'week_' : '';
            $resets = is_numeric($info['resetsAt'] ?? null) ? (int) $info['resetsAt'] : null;
            $utilization = is_numeric($info['utilization'] ?? null) ? (float) $info['utilization'] : null;
            $previous = filled($usage[$prefix.'resets_at'] ?? null) ? Carbon::parse($usage[$prefix.'resets_at']) : null;

            if ($utilization !== null) {
                // a fraction or a percentage, depending on the CLI: a percentage here
                $usage[$prefix.'percent'] = (int) round(max(0, min(100, $utilization <= 1 ? $utilization * 100 : $utilization)));
            } elseif ($previous === null || $previous->isPast()) {
                $usage[$prefix.'percent'] = null;
            }

            if ($resets !== null) {
                // seconds or milliseconds since the epoch
                $usage[$prefix.'resets_at'] = Carbon::createFromTimestamp($resets > 100_000_000_000 ? intdiv($resets, 1000) : $resets)->toIso8601String();
            }
        }

        $account->forceFill(['usage' => $usage])->save();
    }

    /** The subscription the conversation runs on: the plan whose limits the CLI speaks of. */
    private function subscriptionOf(Conversation $conversation): ?ClaudeAccount
    {
        $account = Accounts::claude($conversation->user, $conversation->workspace->project)?->account;

        return $account instanceof ClaudeAccount && $account->isSubscription() ? $account : null;
    }

    /**
     * What `/usage` shows, asked of Claude Code by the bridge after a turn: the five-hour and the
     * weekly window of the plan, each as a percentage (0-100) with its reset.
     *
     * @param  array<string, mixed>  $event
     */
    private function planUsage(Conversation $conversation, array $event): void
    {
        if (! $account = $this->subscriptionOf($conversation)) {
            return;
        }

        $usage = (array) ($account->usage ?? []);
        $limits = is_array($event['rate_limits'] ?? null) ? $event['rate_limits'] : [];

        foreach (['five_hour' => '', 'seven_day' => 'week_'] as $window => $prefix) {
            if (! is_array($limits[$window] ?? null)) {
                continue;
            }

            $utilization = $limits[$window]['utilization'] ?? null;
            $resets = $limits[$window]['resets_at'] ?? null;
            $usage[$prefix.'percent'] = is_numeric($utilization) ? (int) round(max(0, min(100, (float) $utilization))) : null;
            $usage[$prefix.'resets_at'] = is_string($resets) && $resets !== '' ? Carbon::parse($resets)->toIso8601String() : null;
        }

        $usage['plan'] = is_string($event['subscription_type'] ?? null) ? $event['subscription_type'] : ($usage['plan'] ?? null);
        $usage['at'] = now()->toIso8601String();

        $account->forceFill(['usage' => $usage])->save();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function prose(Conversation $conversation, string $role, string $kind, array $event): void
    {
        $text = trim((string) ($event['text'] ?? ''));

        if ($text === '') {
            return;
        }

        $conversation->messages()->create([
            'role' => $role,
            'kind' => $kind,
            'content' => $text,
            'agent_uuid' => $event['uuid'] ?? null,
            'payload' => array_filter(['message_id' => $event['message_id'] ?? null, 'parent_tool_use_id' => $event['parent_tool_use_id'] ?? null]),
        ]);

        $conversation->setStatus(ConversationStatus::Running);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function toolUse(Conversation $conversation, array $event): void
    {
        $conversation->messages()->create([
            'role' => Message::ROLE_ASSISTANT,
            'kind' => Message::KIND_TOOL_USE,
            'content' => (string) ($event['name'] ?? 'tool'),
            'tool_use_id' => $event['id'] ?? null,
            'agent_uuid' => $event['uuid'] ?? null,
            'payload' => [
                'name' => $event['name'] ?? null,
                'input' => $event['input'] ?? [],
                'parent_tool_use_id' => $event['parent_tool_use_id'] ?? null,
                // calls of one assistant message ran together: the chat shows parallel subagents side by side
                'message_id' => $event['message_id'] ?? null,
            ],
        ]);

        $conversation->setStatus(ConversationStatus::Running);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function toolResult(Conversation $conversation, array $event): void
    {
        $content = $event['content'] ?? '';

        $conversation->messages()->create([
            'role' => Message::ROLE_TOOL,
            'kind' => Message::KIND_TOOL_RESULT,
            'content' => Str::limit(is_string($content) ? $content : (json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''), 20000, '…'),
            'tool_use_id' => $event['tool_use_id'] ?? null,
            'payload' => array_filter([
                'is_error' => (bool) ($event['is_error'] ?? false),
                'parent_tool_use_id' => $event['parent_tool_use_id'] ?? null,
                'images' => $this->images($conversation, $event['images'] ?? null),
            ], fn ($value) => $value !== null && $value !== []),
        ]);
    }

    /**
     * The screenshots of a tool result, stored on Studio's disk; one the bridge left out for its
     * size is kept as a note of it.
     *
     * @return list<array{file?: string, type: string, bytes: int, omitted?: bool}>
     */
    private function images(Conversation $conversation, mixed $images): array
    {
        $kept = [];

        foreach (array_slice(is_array($images) ? $images : [], 0, 4) as $image) {
            if (! is_array($image) || ! is_string($image['media_type'] ?? null)) {
                continue;
            }

            if (! is_string($image['data'] ?? null)) {
                $kept[] = ['type' => $image['media_type'], 'bytes' => (int) ($image['omitted_bytes'] ?? 0), 'omitted' => true];

                continue;
            }

            $stored = ConversationImages::store($conversation, $image['media_type'], $image['data']);

            if ($stored !== null) {
                $kept[] = $stored;
            }
        }

        return $kept;
    }

    /**
     * A subagent (or a background command) started or ended: kept on the tool call that
     * started it, which the chat draws as the agent's card.
     *
     * @param  array<string, mixed>  $event
     */
    private function task(Conversation $conversation, array $event): void
    {
        $toolUseId = (string) ($event['tool_use_id'] ?? '');
        $call = $toolUseId === '' ? null : $conversation->messages()->where('kind', Message::KIND_TOOL_USE)->where('tool_use_id', $toolUseId)->first();

        if ($call === null) {
            return;
        }

        $payload = (array) $call->payload;
        $payload['task'] = array_filter([
            ...(array) ($payload['task'] ?? []),
            'id' => $event['task_id'] ?? null,
            'status' => $event['status'] ?? null,
            'summary' => $event['summary'] ?? null,
            ($event['phase'] ?? '') === 'finished' ? 'finished_at' : 'started_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null);

        $call->forceFill(['payload' => $payload])->save();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function permissionRequest(Conversation $conversation, array $event): void
    {
        $requestId = (string) ($event['request_id'] ?? '');

        if ($requestId === '') {
            return;
        }

        PermissionRequest::query()->firstOrCreate(
            ['conversation_id' => $conversation->id, 'request_id' => $requestId],
            [
                'tool_name' => (string) ($event['tool_name'] ?? 'tool'),
                'description' => isset($event['description']) ? Str::limit(strip_tags((string) $event['description']), 1000) : null,
                'input' => is_array($event['input'] ?? null) ? $event['input'] : [],
                'status' => PermissionStatus::Pending,
            ],
        );

        $conversation->setStatus(ConversationStatus::WaitingPermission);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function permissionResolved(Conversation $conversation, array $event): void
    {
        $request = $conversation->permissionRequests()->where('request_id', (string) ($event['request_id'] ?? ''))->first();

        if ($request && $request->isPending()) {
            $request->forceFill([
                'status' => ($event['behavior'] ?? 'deny') === 'allow' ? PermissionStatus::Allowed : PermissionStatus::Denied,
                'decided_at' => now(),
            ])->save();
        }

        if (! $conversation->pendingPermission()) {
            $conversation->setStatus(ConversationStatus::Running);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function result(Conversation $conversation, array $event): void
    {
        $isError = (bool) ($event['is_error'] ?? false);

        $conversation->forceFill([
            'agent_session_id' => $event['session_id'] ?? $conversation->agent_session_id,
            'status' => $isError ? ConversationStatus::Failed : ConversationStatus::Idle,
            'last_result' => [
                'subtype' => $event['subtype'] ?? null,
                'duration_ms' => $event['duration_ms'] ?? null,
                'num_turns' => $event['num_turns'] ?? null,
                'total_cost_usd' => $event['total_cost_usd'] ?? null,
                'usage' => $event['usage'] ?? null,
                'context_window' => $event['context_window'] ?? null,
            ],
            'last_activity_at' => now(),
        ])->save();

        // Any permission still pending after a result is moot: the turn is over.
        $conversation->permissionRequests()->where('status', PermissionStatus::Pending->value)->update([
            'status' => PermissionStatus::Denied->value,
            'decided_at' => now(),
        ]);

        if ($isError) {
            $content = (string) (($event['result'] ?? '') ?: __('The turn ended with an error (:subtype).', ['subtype' => $event['subtype'] ?? 'error']));

            // A process that dies sends its reason as an error and again as the result: say it once.
            $last = $conversation->messages()->latest('id')->first();
            if ($last?->kind === Message::KIND_NOTICE && $last->content === Str::limit($content, 2000)) {
                return;
            }

            $conversation->messages()->create([
                'role' => Message::ROLE_SYSTEM,
                'kind' => Message::KIND_NOTICE,
                'content' => $content,
                'payload' => ['level' => 'error'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function error(Conversation $conversation, array $event): void
    {
        $conversation->messages()->create([
            'role' => Message::ROLE_SYSTEM,
            'kind' => Message::KIND_NOTICE,
            'content' => Str::limit((string) ($event['message'] ?? __('Bridge error')), 2000),
            'payload' => ['level' => 'error', 'code' => $event['code'] ?? null],
        ]);

        $conversation->setStatus(ConversationStatus::Failed);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function raw(Conversation $conversation, array $event): void
    {
        $conversation->messages()->create([
            'role' => Message::ROLE_SYSTEM,
            'kind' => 'raw',
            'content' => null,
            'payload' => $event,
        ]);
    }
}
