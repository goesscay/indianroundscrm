<?php

namespace App\Services\Mail;

use App\Events\SupplierReplyReceived;
use App\Http\Controllers\Crm\RsqController;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\LeadSupplier;
use App\Models\SupplierReply;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Side effects of a supplier reply, shared by mail ingestion and manual linking.
 * Idempotent: `notified_at` is claimed atomically, so tracker/RSQ/notification/activity happen once per reply.
 */
class ReplyProcessor
{
    /** Tracker statuses a real reply may move to "Awaiting Quote" (anything further along is never downgraded). */
    private const UPGRADABLE = ['', 'Email Sent', 'Follow-up Required', 'Pending'];

    public function handle(SupplierReply $reply): void
    {
        if ($reply->status === 'matched') {
            $this->processMatched($reply);
        } elseif ($reply->status === 'bounce') {
            $this->processBounce($reply);
        }
    }

    public function processMatched(SupplierReply $reply): void
    {
        if (! $this->claim($reply)) {
            return;
        }
        $tracker = $reply->lead_supplier_id ? LeadSupplier::find($reply->lead_supplier_id) : null;
        $lead = $reply->lead_id ? Lead::where('lead_id', $reply->lead_id)->first() : null;

        $recipients = [];
        DB::transaction(function () use ($reply, $tracker, $lead, &$recipients) {
            if ($tracker) {
                $upd = [
                    'reply_received' => true,
                    'reply_date' => $reply->received_at ?? now(),
                    'last_update' => now(),
                    'inbound_snippet' => mb_substr((string) $reply->body_preview, 0, 300),
                ];
                if (in_array((string) $tracker->status, self::UPGRADABLE, true)) {
                    $upd['status'] = 'Awaiting Quote';
                }
                $tracker->fill($upd)->save();
            }
            RsqController::markResponseReceived($reply->lead_id);

            $recipients = $this->recipients($lead);
            $this->persistUnread($reply, $recipients);
            $this->createActivity($reply, $lead, $tracker);
        });

        $this->broadcast($reply, $lead, $tracker, $recipients, 'supplier_reply');
    }

    public function processBounce(SupplierReply $reply): void
    {
        if (! $this->claim($reply)) {
            return;
        }
        $tracker = $reply->lead_supplier_id ? LeadSupplier::find($reply->lead_supplier_id) : null;
        $lead = $reply->lead_id ? Lead::where('lead_id', $reply->lead_id)->first() : null;

        $recipients = [];
        DB::transaction(function () use ($reply, $tracker, $lead, &$recipients) {
            $tracker?->fill(['delivery_status' => 'bounced', 'last_update' => now()])->save();
            $recipients = $this->recipients($lead);
            $this->persistUnread($reply, $recipients);
        });

        $this->broadcast($reply, $lead, $tracker, $recipients, 'supplier_bounce');
    }

    /** Atomically mark the side effects as done; false when another run already did them. */
    private function claim(SupplierReply $reply): bool
    {
        $n = SupplierReply::whereKey($reply->id)->whereNull('notified_at')->update(['notified_at' => now()]);
        if ($n === 1) {
            $reply->notified_at = now();
        }

        return $n === 1;
    }

    /**
     * Every active user holding rsq.manage (Operations, Admin), plus the lead owner when the owner is one of them.
     * A sales owner is not told: supplier names and mail ids are not for sales.
     *
     * @return list<int>
     */
    public function recipients(?Lead $lead): array
    {
        $ids = [];
        if ($lead && $lead->staff_id) {
            $owner = User::find($lead->staff_id);
            if ($owner && $this->isActive($owner) && $owner->hasPermission('rsq.manage')) {
                $ids[] = (int) $owner->id;
            }
        }
        foreach (User::where('active', true)->where('account_status', 'active')->get() as $u) {
            if ($u->hasPermission('rsq.manage')) {
                $ids[] = (int) $u->id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function isActive(User $u): bool
    {
        return (bool) $u->active && $u->account_status === 'active';
    }

    private function persistUnread(SupplierReply $reply, array $userIds): void
    {
        foreach ($userIds as $uid) {
            DB::table('supplier_reply_reads')->insertOrIgnore(['reply_id' => $reply->id, 'user_id' => $uid, 'read_at' => null]);
        }
    }

    private function supplierName(SupplierReply $reply, ?LeadSupplier $tracker): string
    {
        $n = $tracker?->supplier_name ?: ($reply->from_name ?: $reply->from_email);

        return (string) $n;
    }

    private function createActivity(SupplierReply $reply, ?Lead $lead, ?LeadSupplier $tracker): void
    {
        if ($reply->activity_id || ! $lead) {
            return;
        }
        $owner = $lead->staff_id ? User::find($lead->staff_id) : null;
        // activities on a lead are visible to its sales owner, so no supplier name or subject goes in here
        $title = mb_substr('Review supplier reply — '.$lead->lead_id, 0, 200);
        $activity = Activity::create([
            'type' => 'email', 'title' => $title,
            'details' => null,
            'priority' => 'medium', 'due_at' => now(),
            'assigned_to' => $owner && $this->isActive($owner) && $owner->hasPermission('rsq.manage') ? $owner->id : null,
            'created_by' => null, 'lead_id' => $lead->lead_id,
        ]);
        SupplierReply::whereKey($reply->id)->update(['activity_id' => $activity->id]);
        $reply->activity_id = (string) $activity->id;
    }

    /** Push to the private channel of every recipient; never lets a broadcast failure surface. */
    private function broadcast(SupplierReply $reply, ?Lead $lead, ?LeadSupplier $tracker, array $userIds, string $kind): void
    {
        if (! $userIds) {
            return;
        }
        try {
            SupplierReplyReceived::dispatch($userIds, [
                'id' => (string) $reply->id,
                'lead_id' => $reply->lead_id,
                'customer_name' => $lead?->customer_name,
                'supplier_name' => $this->supplierName($reply, $tracker),
                'subject' => (string) $reply->subject,
                'preview' => (string) $reply->body_preview,
                'received_at' => $reply->received_at?->utc()->toIso8601String(),
                'kind' => $kind,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Supplier reply broadcast failed: '.$e->getMessage());
        }
    }
}
