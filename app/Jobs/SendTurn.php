<?php

namespace App\Jobs;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Enums\ConversationStatus;
use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hands one user message to the bridge. The bridge answers at once and the
 * conversation then fills in through the events it posts back.
 */
class SendTurn implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Conversation $conversation, public string $text) {}

    public function handle(): void
    {
        $workspace = $this->conversation->workspace;

        try {
            (new BridgeClient($workspace))->sendTurn($this->conversation, $this->text);
            $workspace->touchSeen();
        } catch (BridgeException $e) {
            $this->conversation->messages()->create([
                'role' => Message::ROLE_SYSTEM,
                'kind' => Message::KIND_NOTICE,
                'content' => $e->getMessage(),
                'payload' => ['level' => 'error'],
            ]);

            $this->conversation->setStatus(ConversationStatus::Failed);

            ConversationUpdated::dispatch($this->conversation->id, 'messages', ['kinds' => ['error'], 'status' => ConversationStatus::Failed->value]);
        }
    }
}
