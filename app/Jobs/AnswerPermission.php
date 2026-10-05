<?php

namespace App\Jobs;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Enums\ConversationStatus;
use App\Events\ConversationUpdated;
use App\Models\Message;
use App\Models\PermissionRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnswerPermission implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  array<string, string>|null  $answers */
    public function __construct(public PermissionRequest $request, public bool $allow, public ?string $message = null, public ?array $answers = null) {}

    public function handle(): void
    {
        $conversation = $this->request->conversation;

        try {
            (new BridgeClient($conversation->workspace))->answerPermission($this->request, $this->allow, $this->message, $this->answers);
        } catch (BridgeException $e) {
            $conversation->messages()->create([
                'role' => Message::ROLE_SYSTEM,
                'kind' => Message::KIND_NOTICE,
                'content' => $e->getMessage(),
                'payload' => ['level' => 'error'],
            ]);

            $conversation->setStatus(ConversationStatus::Failed);

            ConversationUpdated::dispatch($conversation->id, 'messages', ['kinds' => ['error'], 'status' => ConversationStatus::Failed->value]);
        }
    }
}
