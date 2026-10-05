<?php

use Illuminate\Support\Facades\Broadcast;

// Private per-user chat channel: a user may only listen to their own channel.
Broadcast::channel('chat.user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
