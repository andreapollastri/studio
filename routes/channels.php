<?php

use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

Broadcast::channel('conversations.{uuid}', function (User $user, string $uuid) {
    $conversation = Conversation::query()->where('uuid', $uuid)->first();

    return $conversation !== null && ($conversation->user_id === $user->id || $user->isAdmin());
});

// A workspace's status, for its owner's page (and administrators).
Broadcast::channel('workspaces.{uuid}', function (User $user, string $uuid) {
    $workspace = Workspace::query()->where('uuid', $uuid)->first();

    return $workspace !== null && ($workspace->user_id === $user->id || $user->isAdmin());
});
