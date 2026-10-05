<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A supplier reply (or bounce alert) arrived: pushed immediately to each notified user's private channel. */
class SupplierReplyReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<int>  $userIds
     * @param  array<string,mixed>  $reply  {id, lead_id, customer_name, supplier_name, subject, preview, received_at, kind}
     */
    public function __construct(public array $userIds, public array $reply)
    {
    }

    public function broadcastOn(): array
    {
        return array_map(fn ($id) => new PrivateChannel('notifications.user.'.$id), array_values(array_unique($this->userIds)));
    }

    public function broadcastAs(): string
    {
        return 'supplier.reply';
    }

    public function broadcastWith(): array
    {
        return ['reply' => $this->reply];
    }
}
