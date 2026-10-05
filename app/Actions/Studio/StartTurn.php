<?php

namespace App\Actions\Studio;

use App\Enums\ConversationStatus;
use App\Events\ConversationUpdated;
use App\Jobs\SendTurn;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Records what the person said, marks the conversation as working, and
 * queues the hand-off to the bridge. The chat shows the message at once.
 */
final class StartTurn
{
    public function handle(Conversation $conversation, string $text): Message
    {
        $text = trim($text);

        if ($text === '') {
            throw new InvalidArgumentException(__('The message is empty.'));
        }

        if (! $conversation->workspace->isRunning()) {
            throw new InvalidArgumentException(__('The workspace is not running: start it before writing.'));
        }

        // One turn at a time: a second message would queue inside Claude Code and the
        // conversation's state would drift from the agent's. Interrupt first.
        if ($conversation->isBusy()) {
            throw new InvalidArgumentException(__('Claude is still working on the last message: wait for it, or interrupt it.'));
        }

        $message = $conversation->messages()->create([
            'role' => Message::ROLE_USER,
            'kind' => Message::KIND_TEXT,
            'content' => $text,
        ]);

        if (blank($conversation->title)) {
            $conversation->title = Str::limit(Str::of($text)->replaceMatches('/^\/[a-z0-9-]+\s*/i', '')->trim()->toString() ?: $text, 60, '…');
        }

        $conversation->setStatus(ConversationStatus::Running);

        SendTurn::dispatch($conversation, $text);

        ConversationUpdated::dispatch($conversation->id, 'messages', ['kinds' => ['user'], 'status' => ConversationStatus::Running->value]);

        return $message;
    }
}
