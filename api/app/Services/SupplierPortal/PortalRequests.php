<?php

namespace App\Services\SupplierPortal;

use App\Models\Lead;
use App\Models\LeadSupplier;
use App\Models\RsqRequest;
use App\Models\SupplierQuote;
use App\Models\SupplierReply;
use App\Models\SupplierUser;
use App\Services\Mail\AttachmentData;
use App\Services\Mail\AttachmentStore;
use App\Services\Mail\ReplyProcessor;
use App\Services\Mail\ReplyText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What a supplier sees and does in the portal. Every query is scoped to the signed-in supplier's own tracker rows;
 * the lead is shown only as the requirement (no customer name, phone, e-mail, budget, price or owner).
 * A submission becomes a synthetic matched "reply", so the tracker, RSQ lane, bell, task and inbox all work exactly as for e-mail.
 */
class PortalRequests
{
    public const CURRENCIES = ['INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD', 'THB', 'LKR', 'NPR', 'IDR', 'VND', 'MYR'];
    public const MAX_FILES = 5;

    /** Tracker states after which a revision would overwrite a decision the team has already made. */
    private const LOCKED = ['Quote Accepted', 'Quote Rejected', 'Booking Confirmed'];

    public static function tracker(SupplierUser $u, string $leadId): ?LeadSupplier
    {
        return LeadSupplier::where('supplier_id', $u->supplier_id)->where('lead_id', $leadId)->whereNotNull('portal_invited_at')->first();
    }

    private static function closed(LeadSupplier $t): bool
    {
        if (in_array($t->status, self::LOCKED, true)) {
            return true;
        }

        return RsqRequest::where('lead_id', $t->lead_id)->value('status') === 'completed';
    }

    private static function date($v): ?string
    {
        if (! $v) {
            return null;
        }
        try {
            return Carbon::parse($v)->format('Y-m-d');
        } catch (\Throwable) {
            return substr((string) $v, 0, 10) ?: null;
        }
    }

    private static function latest(LeadSupplier $t): ?SupplierQuote
    {
        return SupplierQuote::where('lead_supplier_id', $t->id)->where('status', '<>', 'superseded')->orderByDesc('version')->first();
    }

    private static function state(LeadSupplier $t, ?SupplierQuote $q): string
    {
        if ($q && $q->status === 'submitted') {
            return self::closed($t) ? 'closed' : 'quoted';
        }
        if ($q && $q->status === 'declined') {
            return 'declined';
        }

        return self::closed($t) ? 'closed' : 'open';
    }

    public static function list(SupplierUser $u): array
    {
        $trackers = LeadSupplier::where('supplier_id', $u->supplier_id)->whereNotNull('portal_invited_at')
            ->orderByDesc('portal_invited_at')->limit(300)->get();
        $leads = Lead::whereIn('lead_id', $trackers->pluck('lead_id'))->get()->keyBy('lead_id');

        return $trackers->map(function (LeadSupplier $t) use ($leads) {
            $lead = $leads->get($t->lead_id);
            $q = self::latest($t);

            return [
                'lead_id' => $t->lead_id,
                'destination' => $lead?->destination,
                'travel_date' => self::date($lead?->travel_date),
                'duration' => $lead?->duration_label,
                'adults' => $lead?->adults, 'children' => $lead?->children,
                'state' => self::state($t, $q),
                'invited_at' => $t->portal_invited_at?->utc()->toIso8601String(),
                'quote' => $q && $q->status === 'submitted' ? ['amount' => $q->amount, 'currency' => $q->currency, 'version' => $q->version, 'at' => $q->created_at?->utc()->toIso8601String()] : null,
            ];
        })->values()->all();
    }

    public static function detail(LeadSupplier $t): ?array
    {
        $lead = Lead::with('stayCities')->where('lead_id', $t->lead_id)->first();
        if (! $lead) {
            return null;
        }
        $pp = $lead->passport_available;
        $q = self::latest($t);
        $quotes = SupplierQuote::with('reply.attachments')->where('lead_supplier_id', $t->id)->orderByDesc('version')->get();

        return [
            'lead_id' => $lead->lead_id,
            'state' => self::state($t, $q),
            'requirement' => [
                'destination' => $lead->destination,
                'travel_date' => self::date($lead->travel_date), 'trip_end_date' => self::date($lead->trip_end_date),
                'duration' => $lead->duration_label, 'package_type' => $lead->package_type,
                'stay_cities' => $lead->stayCitiesArray(),
                'hotel_category' => $lead->hotel_category, 'meal_plan' => $lead->meal_plan, 'rooms' => $lead->rooms, 'room_type' => $lead->room_type,
                'adults' => $lead->adults, 'children' => $lead->children, 'child_ages' => $lead->child_ages,
                'passport' => $pp === true ? 'Yes' : ($pp === false ? 'No' : null),
                'passport_reason' => $pp === false ? $lead->passport_reason : null,
                'special_requests' => $lead->special_requests,
            ],
            'quotes' => $quotes->map(fn (SupplierQuote $x) => [
                'id' => (string) $x->id, 'version' => $x->version, 'status' => $x->status,
                'amount' => $x->amount, 'currency' => $x->currency, 'hotel_offered' => $x->hotel_offered,
                'flight_included' => $x->flight_included, 'transfer_included' => $x->transfer_included,
                'activities_included' => $x->activities_included, 'valid_until' => $x->valid_until?->format('Y-m-d'),
                'remarks' => $x->remarks, 'decline_reason' => $x->decline_reason,
                'at' => $x->created_at?->utc()->toIso8601String(),
                'files' => ($x->reply?->attachments ?? collect())->where('skipped', false)->map(fn ($a) => ['id' => (string) $a->id, 'filename' => $a->filename, 'size' => (int) $a->size])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** True when this attachment belongs to one of the supplier's own submissions. */
    public static function ownsAttachment(SupplierUser $u, int $attachmentId): ?\App\Models\SupplierReplyAttachment
    {
        $att = \App\Models\SupplierReplyAttachment::find($attachmentId);
        if (! $att || $att->skipped || ! $att->storage_path) {
            return null;
        }
        $mine = SupplierQuote::where('reply_id', $att->reply_id)->where('supplier_id', $u->supplier_id)->exists();

        return $mine ? $att : null;
    }

    /**
     * @param  array<string,mixed>  $d  validated fields
     * @param  list<UploadedFile>  $files
     */
    public static function submitQuote(SupplierUser $u, LeadSupplier $t, array $d, array $files): SupplierQuote
    {
        $currency = $d['currency'] ?: 'INR';
        $amount = isset($d['amount']) && $d['amount'] !== '' ? (float) $d['amount'] : null;
        $lines = [
            "Quote submitted through the supplier portal by {$u->name} ({$u->email}).",
            $amount !== null ? 'Total: '.$currency.' '.number_format($amount, 2) : null,
            ! empty($d['hotel_offered']) ? 'Hotel: '.$d['hotel_offered'] : null,
            array_key_exists('flight_included', $d) && $d['flight_included'] !== null ? 'Flights included: '.($d['flight_included'] ? 'Yes' : 'No') : null,
            array_key_exists('transfer_included', $d) && $d['transfer_included'] !== null ? 'Transfers included: '.($d['transfer_included'] ? 'Yes' : 'No') : null,
            ! empty($d['activities_included']) ? 'Activities: '.$d['activities_included'] : null,
            ! empty($d['valid_until']) ? 'Valid until: '.$d['valid_until'] : null,
            ! empty($d['remarks']) ? "Remarks:\n".$d['remarks'] : null,
            $files ? count($files).' document'.(count($files) === 1 ? '' : 's').' attached.' : null,
        ];
        $reply = null;
        $quote = DB::transaction(function () use ($u, $t, $d, $files, $currency, $amount, $lines, &$reply) {
            SupplierQuote::where('lead_supplier_id', $t->id)->where('status', '<>', 'superseded')->update(['status' => 'superseded']);
            $version = (int) SupplierQuote::where('lead_supplier_id', $t->id)->max('version') + 1;
            $reply = self::syntheticReply($u, $t, 'Quote submitted via supplier portal - '.$t->lead_id, implode("\n", array_filter($lines)), 'quote', true);
            if ($files) {
                $items = [];
                foreach ($files as $i => $f) {
                    $ext = strtolower(pathinfo($f->getClientOriginalName(), PATHINFO_EXTENSION));
                    $items[] = new AttachmentData(id: 'p'.$i, name: $f->getClientOriginalName(), contentType: (string) AttachmentStore::canonicalType($ext),
                        size: (int) $f->getSize(), isFile: true, isInline: false, loader: null, content: (string) file_get_contents($f->getRealPath()));
                }
                app(AttachmentStore::class)->storeAll($reply, $items);
            }
            $quote = SupplierQuote::create([
                'lead_supplier_id' => $t->id, 'lead_id' => $t->lead_id, 'supplier_id' => $t->supplier_id, 'supplier_user_id' => $u->id,
                'version' => $version, 'status' => 'submitted', 'amount' => $amount, 'currency' => $amount !== null ? $currency : null,
                'hotel_offered' => $d['hotel_offered'] ?? null, 'flight_included' => $d['flight_included'] ?? null,
                'transfer_included' => $d['transfer_included'] ?? null, 'activities_included' => $d['activities_included'] ?? null,
                'valid_until' => $d['valid_until'] ?? null, 'remarks' => $d['remarks'] ?? null, 'reply_id' => $reply->id,
            ]);
            $t->fill([
                'status' => 'Quote Received', 'reply_received' => true, 'quote_received' => true, 'reply_date' => now(), 'last_update' => now(),
                'updated_by' => 'portal:'.$u->email,
                'quote_amount' => $amount, 'currency' => $amount !== null ? $currency : $t->currency,
                'hotel_offered' => $d['hotel_offered'] ?? null, 'flight_included' => $d['flight_included'] ?? null,
                'transfer_included' => $d['transfer_included'] ?? null, 'activities_included' => $d['activities_included'] ?? null,
                'valid_until' => $d['valid_until'] ?? null, 'remarks' => $d['remarks'] ?? null,
            ])->save();

            return $quote;
        });
        app(ReplyProcessor::class)->processMatched($reply);

        return $quote;
    }

    public static function decline(SupplierUser $u, LeadSupplier $t, string $reason): SupplierQuote
    {
        $reply = null;
        $quote = DB::transaction(function () use ($u, $t, $reason, &$reply) {
            SupplierQuote::where('lead_supplier_id', $t->id)->where('status', '<>', 'superseded')->update(['status' => 'superseded']);
            $version = (int) SupplierQuote::where('lead_supplier_id', $t->id)->max('version') + 1;
            $reply = self::syntheticReply($u, $t, 'Supplier cannot quote via portal - '.$t->lead_id,
                "{$u->name} ({$u->email}) cannot quote for this enquiry.\nReason: ".$reason, 'normal', false);
            $quote = SupplierQuote::create([
                'lead_supplier_id' => $t->id, 'lead_id' => $t->lead_id, 'supplier_id' => $t->supplier_id, 'supplier_user_id' => $u->id,
                'version' => $version, 'status' => 'declined', 'decline_reason' => $reason, 'reply_id' => $reply->id,
            ]);
            $t->fill(['status' => 'Supplier Declined', 'reply_received' => true, 'quote_received' => false, 'reply_date' => now(),
                'last_update' => now(), 'updated_by' => 'portal:'.$u->email, 'remarks' => $reason])->save();

            return $quote;
        });
        app(ReplyProcessor::class)->processMatched($reply);

        return $quote;
    }

    private static function syntheticReply(SupplierUser $u, LeadSupplier $t, string $subject, string $body, string $relevance, bool $final): SupplierReply
    {
        if ($final) {
            SupplierReply::where('lead_supplier_id', $t->id)->where('is_final', true)->update(['is_final' => false]);
        }
        $text = ReplyText::toPlain($body, 'text');

        return SupplierReply::create([
            'lead_id' => $t->lead_id, 'supplier_id' => $t->supplier_id, 'lead_supplier_id' => $t->id,
            'graph_message_id' => 'portal:'.Str::uuid(), 'conversation_id' => $t->conversation_id,
            'from_email' => mb_substr($u->email, 0, 255), 'from_name' => mb_substr((string) $u->name, 0, 255),
            'to_emails' => [], 'cc_emails' => [], 'subject' => mb_substr($subject, 0, 998),
            'body_text' => $text, 'body_preview' => ReplyText::preview($text, 300),
            'received_at' => now(), 'status' => 'matched', 'matched_by' => 'portal', 'relevance' => $relevance, 'is_final' => $final,
        ]);
    }
}
