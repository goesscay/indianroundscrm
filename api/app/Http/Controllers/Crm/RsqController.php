<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadSupplier;
use App\Models\RsqRequest;
use App\Models\Supplier;
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
        if ($user->role === 'sales' && (int) $lead->staff_id !== (int) $user->id) {
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
        if ($user->role === 'sales') {
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
        return LeadSupplier::where('lead_id', $leadId)->orderBy('sequence')->limit(200)->get()->map(function ($t) use ($now) {
            $a = $t->toArray();
            $a['hours_since_email'] = $t->last_email_at ? (int) floor(($now - $t->last_email_at->getTimestamp()) / 3600) : 0;
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
