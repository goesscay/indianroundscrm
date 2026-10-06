<?php

namespace App\Services\Mail;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\CustomerReply;
use App\Models\Deal;
use App\Models\User;

/** Side effects of a matched customer reply: a to-do for the owner, once. */
class CustomerReplyProcessor
{
    public function handle(CustomerReply $reply): void
    {
        // claim atomically so a retry never creates a second activity
        if (CustomerReply::whereKey($reply->id)->whereNull('notified_at')->update(['notified_at' => now()]) !== 1) {
            return;
        }
        $customer = Customer::find($reply->customer_id);
        $deal = $reply->deal_id ? Deal::find($reply->deal_id) : null;
        $ownerId = $deal?->owner_id ?: $customer?->owner_id;
        $owner = $ownerId ? User::find($ownerId) : null;
        $ref = $reply->quote?->quote_no ?? $reply->invoice?->invoice_no ?? $deal?->deal_no ?? $customer?->customer_no;

        $activity = Activity::create([
            'type' => 'email',
            'title' => mb_substr('Customer replied — '.($customer?->name ?? $reply->from_email).($ref ? " · $ref" : ''), 0, 200),
            'details' => 'Subject: '.mb_substr($reply->subject, 0, 200)."\n".$reply->body_preview,
            'priority' => 'medium', 'due_at' => now(),
            'assigned_to' => $owner && $owner->active && $owner->account_status === 'active' ? $owner->id : null,
            'created_by' => null, 'lead_id' => $deal?->lead_id, 'customer_id' => $reply->customer_id, 'deal_id' => $reply->deal_id,
        ]);
        CustomerReply::whereKey($reply->id)->update(['activity_id' => $activity->id]);
    }
}
