<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A chat message was deleted. Delivered immediately (no queue) to every listed user's private channel. */
class MessageDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param  list<int>  $userIds */
    public function __construct(public array $userIds, public string $conversationId, public string $messageId)
    {
    }

    public function broadcastOn(): array
    {
        return array_map(fn ($id) => new PrivateChannel('chat.user.'.$id), array_values(array_unique($this->userIds)));
    }

    public function broadcastAs(): string
    {
        return 'message.deleted';
    }

    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId, 'message_id' => $this->messageId];
    }
}
