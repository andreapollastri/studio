<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

Broadcast::channel('conversations.{id}', function (User $user, int $id) {
    $conversation = Conversation::query()->find($id);

    return $conversation !== null && ($conversation->user_id === $user->id || $user->isAdmin());
});
