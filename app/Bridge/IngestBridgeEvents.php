<?php

namespace App\Bridge;

use App\Enums\ConversationStatus;
use App\Enums\PermissionStatus;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PermissionRequest;
use App\Models\Workspace;
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
                'text_delta' => ConversationUpdated::dispatch($conversation->id, 'text_delta', ['text' => (string) ($event['text'] ?? '')]),
                'status' => ConversationUpdated::dispatch($conversation->id, 'status', ['status' => (string) ($event['status'] ?? '')]),
                default => $this->raw($conversation, $event),
            };

            $kinds[] = $type;
        }

        $stored = array_values(array_diff(array_unique($kinds), ['text_delta', 'status']));

        if ($stored !== []) {
            ConversationUpdated::dispatch($conversation->id, 'messages', ['kinds' => $stored, 'status' => $conversation->refresh()->status->value]);
        }

        return $applied;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function init(Conversation $conversation, array $event): void
    {
        $conversation->forceFill([
            'agent_session_id' => $event['session_id'] ?? $conversation->agent_session_id,
            'model' => $event['model'] ?? $conversation->model,
            'status' => ConversationStatus::Running,
            'last_activity_at' => now(),
        ])->save();
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
            'payload' => ['is_error' => (bool) ($event['is_error'] ?? false)],
        ]);
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
            $conversation->messages()->create([
                'role' => Message::ROLE_SYSTEM,
                'kind' => Message::KIND_NOTICE,
                'content' => (string) (($event['result'] ?? '') ?: __('The turn ended with an error (:subtype).', ['subtype' => $event['subtype'] ?? 'error'])),
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
