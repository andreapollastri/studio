<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->user_id === $user->id || $user->isAdmin();
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return $conversation->user_id === $user->id;
    }

    /** Only its owner: an administrator reads other people's conversations, never removes them. */
    public function delete(User $user, Conversation $conversation): bool
    {
        return $conversation->user_id === $user->id;
    }
}
