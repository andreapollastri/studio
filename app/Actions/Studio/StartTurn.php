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
            if ($this->queue($conversation, $text)) {
                return null;
            }

            // the turn ended (and took the queue) while this was written: start it now
            $conversation->refresh();

            if ($conversation->isBusy()) {
                $this->queue($conversation, $text, force: true);

                return null;
            }
        }

        return $this->begin($conversation, $text);
    }

    /**
     * Add the text to the waiting follow-up: a second message while the turn runs goes after the
     * first, it does not replace it. Compare-and-set on what was read, so a follow-up the ending
     * turn takes meanwhile is not written back (and sent twice). False when the queue moved.
     */
    public function queue(Conversation $conversation, string $text, bool $force = false): bool
    {
        $text = trim($text);
        $before = $conversation->getRawOriginal('follow_up');
        $waiting = trim((string) $before);
        $next = $waiting === '' ? $text : $waiting."\n\n".$text;

        $query = Conversation::query()->whereKey($conversation->getKey());

        if (! $force) {
            $before === null ? $query->whereNull('follow_up') : $query->where('follow_up', $before);
        }

        if ($query->update(['follow_up' => $next]) !== 1) {
            return false;
        }

        $conversation->follow_up = $next;
        $conversation->syncOriginalAttribute('follow_up');

        return true;
    }

    /**
     * After a turn ends (or a stuck conversation is healed), start the waiting follow-up if any.
     * The follow-up is taken with a conditional update: of two requests that end the same turn
     * (the bridge's result and the browser's interrupt) only one sends it.
     */
    public function flushFollowUp(Conversation $conversation): ?Message
    {
        $conversation->refresh();

        $waiting = $conversation->getRawOriginal('follow_up');
        $text = trim((string) $waiting);

        if ($text === '' || $conversation->isBusy() || ! $conversation->acceptsMessages() || ! $conversation->workspace->isRunning()) {
            return null;
        }

        $taken = Conversation::query()->whereKey($conversation->getKey())->where('follow_up', $waiting)->update(['follow_up' => null]);

        if ($taken !== 1) {
            return null;
        }

        $conversation->follow_up = null;
        $conversation->syncOriginalAttribute('follow_up');

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
