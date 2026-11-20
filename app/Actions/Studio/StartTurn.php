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
 * hands it to the bridge right after the response has gone: no queue, so a
 * message never waits behind a workspace being built or a site deploying.
 * The chat shows the message at once.
 *
 * While a turn still runs, a further message is kept as the conversation's
 * follow-up and started automatically when the turn ends (one at a time on
 * the agent; Studio holds the next one).
 */
final class StartTurn
{
    /**
     * @return Message|null the user message when a turn started; null when the text was queued as a follow-up
     */
    public function handle(Conversation $conversation, string $text): ?Message
    {
        $text = trim($text);

        if ($text === '') {
            throw new InvalidArgumentException(__('The message is empty.'));
        }

        if (! $conversation->workspace->isRunning()) {
            throw new InvalidArgumentException(__('The workspace is not running: start it before writing.'));
        }

        if (! $conversation->acceptsMessages()) {
            throw new InvalidArgumentException($conversation->sandboxStatus() === Conversation::SANDBOX_CREATING
                ? __('The sandbox is still being prepared: write once it is ready.')
                : __('This conversation\'s sandbox is gone: start a new conversation to go on.'));
        }

        // One turn at a time on the agent: keep the next message here and send it when free.
        if ($conversation->isBusy()) {
            $conversation->forceFill(['follow_up' => $text])->save();

            return null;
        }

        return $this->begin($conversation, $text);
    }

    /**
     * After a turn ends (or a stuck conversation is healed), start the waiting follow-up if any.
     */
    public function flushFollowUp(Conversation $conversation): ?Message
    {
        $conversation->refresh();

        $text = trim((string) $conversation->follow_up);

        if ($text === '' || $conversation->isBusy() || ! $conversation->acceptsMessages() || ! $conversation->workspace->isRunning()) {
            return null;
        }

        $conversation->forceFill(['follow_up' => null])->save();

        return $this->begin($conversation, $text);
    }

    private function begin(Conversation $conversation, string $text): Message
    {
        $message = $conversation->messages()->create([
            'role' => Message::ROLE_USER,
            'kind' => Message::KIND_TEXT,
            'content' => $text,
        ]);

        if (blank($conversation->title)) {
            $conversation->title = Str::limit(Str::of($text)->replaceMatches('/^\/[a-z0-9-]+\s*/i', '')->trim()->toString() ?: $text, 60, '…');
        }

        $conversation->setStatus(ConversationStatus::Running);

        SendTurn::dispatchAfterResponse($conversation, $text);

        ConversationUpdated::dispatch($conversation->uuid, 'messages', ['kinds' => ['user'], 'status' => ConversationStatus::Running->value]);

        return $message;
    }
}
