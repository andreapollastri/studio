<?php

namespace App\Actions\Studio;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;

final class StartConversation
{
    public function handle(Workspace $workspace, User $user, ?string $title = null): Conversation
    {
        return $workspace->conversations()->create([
            'user_id' => $user->id,
            'title' => $title,
            'status' => ConversationStatus::Idle,
            'permission_mode' => $user->role->permissionMode(),
        ]);
    }
}
