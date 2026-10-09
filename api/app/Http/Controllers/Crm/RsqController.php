<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadSupplier;
use App\Models\RsqRequest;
use App\Models\Supplier;
use App\Models\SupplierMessage;
use App\Models\SupplierReply;
use App\Services\SupplierPortal\PortalNotices;
use App\Support\ActivityLogger;
use App\Support\Ids;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * RSQ (supplier quotation) workflow + supplier response tracker.
 *   Sales creates RSQ request       -> status = new
 *   Ops sends supplier mail         -> pending_reply
 *   Any supplier replies            -> response_received
 *   Lead moves to Quote Sent/Closed -> completed
 */
class RsqController extends Controller
{
    public function create(Request $request)
    {
        $user = $request->user();
        $b = Fail::validate(Fail::body($request), ['lead_id' => 'required|string', 'note' => 'nullable|string']);
        $lead = Lead::where('lead_id', $b['lead_id'])->first();
        if (! $lead) {
            Fail::notFound('Lead not found');
        }
        if (! $user->hasPermission('leads.view_all') && (int) $lead->staff_id !== (int) $user->id) {
            Fail::forbidden('Not your lead');
        }

        // prevent duplicate active RSQ (completed ones may be re-opened)
        $existing = RsqRequest::where('lead_id', $b['lead_id'])->first();
        if ($existing && in_array($existing->status, ['new', 'pending_reply', 'response_received'], true)) {
            return array_merge($existing->toArray(), ['already_exists' => true]);
        }

        RsqRequest::upsertFor($b['lead_id'], [
            'status' => 'new',
            'requested_by' => $user->email,
            'requested_by_name' => $user->name ?: $user->email,
            'requested_at' => now(),
            'note' => $b['note'] ?? '',
            'sent_at' => null, 'first_reply_at' => null, 'completed_at' => null,
        ]);
        $lead->fill(['quote_type' => 'rsq', 'rsq_status' => 'new'])->save();
        ActivityLogger::log($user, 'create_rsq_request', 'lead', $b['lead_id'], null, ['note' => $b['note'] ?? null]);

        return RsqRequest::where('lead_id', $b['lead_id'])->first()->toEnriched();
    }

    public function index()
    {
        $rows = RsqRequest::orderByDesc('updated_at')->limit(1000)->get()->map(fn ($r) => $r->toEnriched());
        $out = [];
        foreach (RsqRequest::LANES as $lane) {
            $out[$lane] = $rows->where('status', $lane)->values()->all();
        }
        return $out;
    }

    public function show(Request $request, string $leadId)
    {
        $user = $request->user();
        $row = RsqRequest::where('lead_id', $leadId)->first();
        if (! $row) {
            return response()->json(null);
        }
        if (! $user->hasPermission('leads.view_all')) {
            $lead = Lead::where('lead_id', $leadId)->first();
            if (! $lead || (int) $lead->staff_id !== (int) $user->id) {
                Fail::forbidden('Not your lead');
            }
        }
        return $row->toEnriched();
    }

    // ---------------------------------------------------------- supplier tracker

    public function leadSuppliers(string $leadId)
    {
        $now = now()->getTimestamp();
        $canFiles = (bool) auth()->user()?->hasPermission('rsq.manage');
        return LeadSupplier::where('lead_id', $leadId)->orderBy('sequence')->limit(200)->get()->map(function ($t) use ($now, $canFiles) {
            $a = $t->toArray();
            $a['hours_since_email'] = $t->last_email_at ? (int) floor(($now - $t->last_email_at->getTimestamp()) / 3600) : 0;
            $a['portal'] = $t->portal_invited_at !== null;
            $a['messages'] = $a['portal'] ? SupplierMessage::where('lead_supplier_id', $t->id)->count() : 0;
            // the documents of the quote the team treats as final (else the latest quote-like reply), for the comparison
            $a['quote_files'] = [];
            if ($canFiles && $t->quote_received) {
                $r = SupplierReply::with('attachments')->where('lead_supplier_id', $t->id)->where('status', 'matched')->where('relevance', 'quote')
                    ->orderByDesc('is_final')->orderByDesc('id')->first();
                $a['quote_files'] = $r ? $r->attachments->where('skipped', false)->map(fn ($f) => ['id' => (string) $f->id, 'filename' => $f->filename])->values()->all() : [];
            }
            return $a;
        })->values();
    }

    public function updateSupplier(Request $request, string $trackerId)
    {
        $user = $request->user();
        $id = Ids::parse($trackerId);
        $row = LeadSupplier::find($id);
        if (! $row) {
            Fail::notFound('Tracker not found');
        }
        $d = Fail::validate(Fail::body($request), [
            'status' => 'nullable|string', 'reply_received' => 'nullable|boolean',
            'reply_date' => 'nullable|string', 'quote_received' => 'nullable|boolean',
            'quote_amount' => 'nullable|numeric', 'currency' => 'nullable|string',
            'hotel_offered' => 'nullable|string', 'flight_included' => 'nullable|boolean',
            'transfer_included' => 'nullable|boolean', 'activities_included' => 'nullable|string',
            'valid_until' => 'nullable|string', 'remarks' => 'nullable|string',
            'respond_by' => 'nullable|date',
        ]);
        $updates = array_filter($d, fn ($v) => $v !== null);

        if (! empty($updates['quote_received']) && ! array_key_exists('quote_amount', $updates) && ! $row->quote_amount) {
            Fail::bad('Quote Amount required when Quote Received = Yes');
        }
        if (($updates['status'] ?? null) === 'Quote Received') {
            $updates['reply_received'] ??= true;
            $updates['reply_date'] ??= now()->toIso8601String();
            $updates['quote_received'] ??= true;
        }
        if (isset($updates['reply_date'])) {
            try {
                $updates['reply_date'] = Carbon::parse($updates['reply_date'], 'UTC');
            } catch (\Throwable) {
                Fail::unprocessable(['reply_date' => 'Input should be a valid datetime']);
            }
        }
        $updates['last_update'] = now();
        $updates['updated_by'] = $user->email;
        $row->fill($updates)->save();

        $log = $updates;
        foreach (['reply_date', 'last_update'] as $k) {
            if (isset($log[$k]) && $log[$k] instanceof \DateTimeInterface) {
                $log[$k] = Carbon::instance($log[$k])->toIso8601String();
            }
        }
        ActivityLogger::log($user, 'update_supplier_response', 'lead_supplier', (string) $id, null, $log);

        if (! empty($updates['reply_received']) || ($updates['status'] ?? null) === 'Quote Received') {
            $this->markResponseReceived($row->lead_id);
        }
        return $row->fresh()->toArray();
    }

    /** pending_reply/new -> response_received */
    public static function markResponseReceived(?string $leadId): void
    {
        if (! $leadId) {
            return;
        }
        $rsq = RsqRequest::where('lead_id', $leadId)->first();
        if ($rsq && in_array($rsq->status, ['new', 'pending_reply'], true)) {
            RsqRequest::upsertFor($leadId, ['status' => 'response_received', 'first_reply_at' => now()]);
            Lead::where('lead_id', $leadId)->update(['rsq_status' => 'response_received']);
        }
    }

    // ---------------------------------------------------------- supplier portal: messages, reminders, choosing a quote

    private function portalTracker(string $trackerId): LeadSupplier
    {
        $row = LeadSupplier::find(Ids::parse($trackerId));
        if (! $row || $row->portal_invited_at === null) {
            Fail::notFound('This supplier was not sent the portal link for this enquiry');
        }

        return $row;
    }

    private function thread(LeadSupplier $t): array
    {
        return SupplierMessage::where('lead_supplier_id', $t->id)->orderBy('id')->limit(200)->get()->map(fn ($m) => [
            'id' => (string) $m->id, 'from' => $m->direction, 'body' => $m->body,
            'at' => $m->created_at?->utc()->toIso8601String(),
            'seen' => $m->direction === 'team' ? $m->supplier_read_at !== null : null,
        ])->values()->all();
    }

    public function messages(string $trackerId)
    {
        return $this->thread($this->portalTracker($trackerId));
    }

    public function postMessage(Request $request, string $trackerId)
    {
        $t = $this->portalTracker($trackerId);
        $d = Fail::validate(Fail::body($request), ['body' => 'required|string|min:1|max:2000']);
        SupplierMessage::create(['lead_supplier_id' => $t->id, 'direction' => 'team', 'body' => trim($d['body']), 'user_id' => $request->user()->id]);
        $mailed = PortalNotices::newMessage($t);
        ActivityLogger::log($request->user(), 'message_supplier', 'lead_supplier', (string) $t->id, null, ['lead_id' => $t->lead_id, 'mailed' => $mailed]);

        return ['messages' => $this->thread($t), 'notified' => $mailed];
    }

    public function portalRemind(Request $request, string $trackerId)
    {
        $t = $this->portalTracker($trackerId);
        if (\App\Services\SupplierPortal\PortalRequests::stateFor($t) !== 'open') {
            Fail::http(409, 'This supplier has already answered or the enquiry is closed.');
        }
        if (! PortalNotices::reminder($t)) {
            Fail::http(502, 'The reminder could not be sent. Check the supplier has an active portal user and the mailbox is connected.');
        }
        ActivityLogger::log($request->user(), 'remind_supplier', 'lead_supplier', (string) $t->id, null, ['lead_id' => $t->lead_id]);

        return ['success' => true, 'portal_reminders' => (int) $t->fresh()->portal_reminders];
    }

    /** Take this supplier's figure as the lead's supplier quote (INR) and mark their quote accepted. */
    public function useQuote(Request $request, string $trackerId)
    {
        $user = $request->user();
        $t = LeadSupplier::find(Ids::parse($trackerId));
        if (! $t) {
            Fail::notFound('Tracker not found');
        }
        $d = Fail::validate(Fail::body($request), ['amount_inr' => 'nullable|numeric|gt:0']);
        if (! $t->quote_received || ! $t->quote_amount) {
            Fail::bad('This supplier has no quote amount yet');
        }
        $currency = strtoupper((string) ($t->currency ?: 'INR'));
        $inr = $currency === 'INR' ? (float) $t->quote_amount : (isset($d['amount_inr']) ? (float) $d['amount_inr'] : null);
        if ($inr === null) {
            Fail::unprocessable(['amount_inr' => "This quote is in $currency. Enter the amount in INR to use it."]);
        }
        $lead = Lead::where('lead_id', $t->lead_id)->first();
        if (! $lead) {
            Fail::notFound('Lead not found');
        }
        $before = $lead->only(['supplier_quote_amount', 'supplier_name']);
        $lead->fill([
            'supplier_quote_amount' => $inr, 'supplier_name' => $t->supplier_name, 'quote_received' => true,
            'quote_received_date' => now()->toIso8601String(), 'updated_by' => $user->email,
        ])->save();
        $t->fill(['status' => 'Quote Accepted', 'last_update' => now(), 'updated_by' => $user->email])->save();
        ActivityLogger::log($user, 'use_supplier_quote', 'lead', $lead->lead_id, $before, ['supplier_quote_amount' => $inr, 'supplier' => $t->supplier_name, 'currency' => $currency]);

        return ['success' => true, 'supplier_quote_amount' => $inr, 'supplier_name' => $t->supplier_name];
    }

    public function followup(string $trackerId)
    {
        $row = LeadSupplier::find(Ids::parse($trackerId));
        if (! $row) {
            Fail::notFound('Not found');
        }
        $row->fill([
            'last_followup_at' => now(), 'status' => 'Follow-up Required', 'last_update' => now(),
            'followups' => $row->followups + 1, 'emails_sent' => $row->emails_sent + 1,
        ])->save();
        return ['success' => true];
    }

    public function dashboard()
    {
        $now = now();
        $today = $now->copy()->utc()->format('Y-m-d');
        $all = LeadSupplier::limit(10000)->get();

        $sameDay = fn ($dt) => $dt && $dt->copy()->utc()->format('Y-m-d') === $today;
        $sentToday = $all->filter(fn ($r) => $sameDay($r->last_email_at))->count();
        $awaiting = $all->filter(fn ($r) => in_array($r->status, ['Email Sent', 'Awaiting Reply', 'Follow-up Required'], true) && ! $r->reply_received)->count();
        $quotesToday = $all->filter(fn ($r) => $r->quote_received && $sameDay($r->reply_date))->count();

        $overdue = 0;
        $responseHours = [];
        foreach ($all as $r) {
            if (! $r->last_email_at) {
                continue;
            }
            $hrs = ($now->getTimestamp() - $r->last_email_at->getTimestamp()) / 3600;
            if ($hrs > 24 && ! $r->reply_received) {
                $overdue++;
            }
            if ($r->reply_received && $r->reply_date) {
                $ft = $r->first_email_at ?: $r->last_email_at;
                $responseHours[] = ($r->reply_date->getTimestamp() - $ft->getTimestamp()) / 3600;
            }
        }
        $avg = $responseHours ? round(array_sum($responseHours) / count($responseHours), 1) : 0;

        $by = [];
        foreach ($all as $r) {
            if (! $r->reply_received) {
                continue;
            }
            $sid = $r->supplier_id;
            $by[$sid] ??= ['name' => $r->supplier_name, 'hours' => [], 'replies' => 0];
            $by[$sid]['replies']++;
            if ($r->first_email_at && $r->reply_date) {
                $by[$sid]['hours'][] = ($r->reply_date->getTimestamp() - $r->first_email_at->getTimestamp()) / 3600;
            }
        }
        $fastest = [];
        foreach ($by as $d) {
            if ($d['hours']) {
                $fastest[] = ['name' => $d['name'], 'avg_hours' => round(array_sum($d['hours']) / count($d['hours']), 1), 'replies' => $d['replies']];
            }
        }
        usort($fastest, fn ($a, $b) => $a['avg_hours'] <=> $b['avg_hours']);

        $noEmail = Supplier::all()->filter(fn ($s) => trim((string) $s->email) === '')->count();
        $need = $all->filter(fn ($r) => ! $r->reply_received && $r->followups < 3 && $r->last_email_at
            && ($now->getTimestamp() - $r->last_email_at->getTimestamp()) > 86400)->count();

        return [
            'sent_today' => $sentToday,
            'awaiting_replies' => $awaiting,
            'quotes_received_today' => $quotesToday,
            'overdue' => $overdue,
            'avg_response_hours' => $avg,
            'top_fastest' => array_slice($fastest, 0, 5),
            'suppliers_no_email' => $noEmail,
            'need_followup' => $need,
        ];
    }
}
