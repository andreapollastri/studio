<?php

namespace App\Actions\Studio;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;

/**
 * A conversation removed by its owner: its messages and permission requests go
 * with it (the foreign keys cascade). A Claude Code still running for it in the
 * workspace is stopped first; a workspace that is off or out of reach does not
 * keep the conversation alive, and its late events find nothing to land on.
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

        $conversation->delete();
    }
}
