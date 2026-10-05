<?php

use Illuminate\Support\Facades\Broadcast;

// Private per-user chat channel: a user may only listen to their own channel.
Broadcast::channel('chat.user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Private per-user notification channel (supplier replies etc.): own id only.
Broadcast::channel('notifications.user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
