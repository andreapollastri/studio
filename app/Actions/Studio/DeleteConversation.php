<?php

namespace App\Actions\Studio;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Jobs\RemoveSandbox;
use App\Models\Conversation;
use App\Support\ConversationImages;
use Illuminate\Support\Facades\Log;

/**
 * A conversation removed by its owner: its messages and permission requests go
 * with it (the foreign keys cascade). A Claude Code still running for it in the
 * workspace is stopped first; a workspace that is off or out of reach does not
 * keep the conversation alive, and its late events find nothing to land on.
 * Its sandbox goes too (its branch stays, it may hold work), and its screenshots.
 */
final class DeleteConversation
{
    public function handle(Conversation $conversation): void
    {
        $workspace = $conversation->workspace;

        if ($workspace->isRunning()) {
            try {
                (new BridgeClient($workspace))->forget($conversation);
            } catch (BridgeException $e) {
                Log::warning('Deleting conversation: the bridge did not forget it', ['conversation' => $conversation->uuid, 'error' => $e->getMessage()]);
            }
        }

        if ($conversation->sandboxAlive()) {
            RemoveSandbox::dispatch($workspace, $conversation->id, false);
        }

        ConversationImages::deleteAll($conversation);

        $conversation->delete();
    }
}
