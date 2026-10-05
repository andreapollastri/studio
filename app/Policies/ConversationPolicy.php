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
}
