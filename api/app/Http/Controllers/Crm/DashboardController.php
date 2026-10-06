<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadSupplier;
use App\Models\RsqRequest;
use App\Models\SupplierReply;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $now = Carbon::now('UTC');
        $today = $now->format('Y-m-d');
        $monthPrefix = $now->format('Y-m');

        $leads = LeadController::scopeForUser(Lead::query(), $request->user())
            ->get(['id', 'created_at', 'booking_status', 'lead_status', 'mail_status', 'quote_received',
                'selling_amount', 'supplier_quote_amount', 'follow_up_date', 'payment_status', 'destination']);

        $created = fn ($l) => $l->created_at ? $l->created_at->copy()->utc()->format('Y-m-d') : '';
        $booked = $leads->where('booking_status', 'Booked');
        $profitOf = fn ($l) => (float) $l->selling_amount - (float) $l->supplier_quote_amount;

        $statusCounts = [];
        $dest = [];
        $monthly = [];
        foreach ($leads as $l) {
            $s = $l->lead_status ?: 'Unknown';
            $statusCounts[$s] = ($statusCounts[$s] ?? 0) + 1;
            $d = $l->destination ?: 'Unknown';
            $dest[$d] = ($dest[$d] ?? 0) + 1;
            $key = substr($created($l), 0, 7);
            if (strlen($key) >= 7) {
                $monthly[$key] ??= ['leads' => 0, 'revenue' => 0, 'profit' => 0];
                $monthly[$key]['leads']++;
                if ($l->booking_status === 'Booked') {
                    $monthly[$key]['revenue'] += (float) $l->selling_amount;
                    $monthly[$key]['profit'] += $profitOf($l);
                }
            }
        }
        ksort($monthly);
        $monthlyList = [];
        foreach ($monthly as $k => $v) {
            $monthlyList[] = ['month' => $k] + $v;
        }
        $monthlyList = array_slice($monthlyList, -6);

        $top = [];
        foreach ($dest as $k => $v) {
            $top[] = ['destination' => $k, 'count' => $v];
        }
        usort($top, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'total_leads' => $leads->count(),
            'today_leads' => $leads->filter(fn ($l) => $created($l) === $today)->count(),
            'month_leads' => $leads->filter(fn ($l) => str_starts_with($created($l), $monthPrefix))->count(),
            'booked' => $booked->count(),
            'cancelled' => $leads->where('lead_status', 'Cancelled')->count(),
            'quote_pending' => $leads->filter(fn ($l) => $l->mail_status === 'Sent' && ! $l->quote_received)->count(),
            'revenue' => $booked->sum(fn ($l) => (float) $l->selling_amount),
            'profit' => $booked->sum($profitOf),
            'pending_payments' => $booked->filter(fn ($l) => $l->payment_status !== 'Paid')->count(),
            'status_counts' => (object) $statusCounts,
            'monthly' => $monthlyList,
            'top_destinations' => array_slice($top, 0, 5),
        ];
    }

    public function leaderboard()
    {
        $by = [];
        foreach (Lead::get(['staff_id', 'staff_name', 'booking_status', 'selling_amount']) as $l) {
            $sid = $l->staff_id !== null ? (string) $l->staff_id : 'unknown';
            $by[$sid] ??= ['staff_id' => $sid, 'staff_name' => $l->staff_name ?? '', 'leads' => 0, 'booked' => 0, 'revenue' => 0];
            $by[$sid]['leads']++;
            if ($l->booking_status === 'Booked') {
                $by[$sid]['booked']++;
                $by[$sid]['revenue'] += (float) $l->selling_amount;
            }
        }
        $ranked = array_values($by);
        usort($ranked, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        foreach ($ranked as &$r) {
            $r['conversion_rate'] = $r['leads'] ? round(($r['booked'] / $r['leads']) * 100, 1) : 0;
        }
        return $ranked;
    }

    public function notifications(Request $request)
    {
        $user = $request->user();
        $now = Carbon::now('UTC');
        $today = $now->format('Y-m-d');
        $iso = fn ($dt) => $dt ? Carbon::instance($dt)->utc()->toIso8601String() : null;
        $alerts = [];

        if ($user->hasPermission('rsq.manage')) {
            $new = RsqRequest::where('status', 'new')->orderByDesc('requested_at')->limit(15)->get();
            $leadMap = Lead::whereIn('lead_id', $new->pluck('lead_id'))->get()->keyBy('lead_id');
            foreach ($new as $r) {
                $lead = $leadMap[$r->lead_id] ?? null;
                $alerts[] = [
                    'kind' => 'rsq_created', 'icon' => "\u{1F4E8}",
                    'title' => 'New RSQ from '.($r->requested_by_name ?: $r->requested_by),
                    'detail' => "Lead {$r->lead_id} · ".($lead->customer_name ?? '').' · '.($lead->destination ?? ''),
                    'lead_id' => $r->lead_id, 'timestamp' => $iso($r->requested_at),
                ];
            }

            $recent = LeadSupplier::where('reply_received', true)
                ->where('reply_date', '>=', $now->copy()->subHours(24))
                ->orderByDesc('reply_date')->limit(10)->get();
            foreach ($recent as $r) {
                $alerts[] = [
                    'kind' => 'reply_received', 'icon' => "\u{1F514}",
                    'title' => "New reply from #{$r->sequence} {$r->supplier_name}",
                    'detail' => "Lead {$r->lead_id} · Quote ".($r->currency ?: '').' '.($r->quote_amount ?: ''),
                    'lead_id' => $r->lead_id, 'timestamp' => $iso($r->reply_date),
                ];
            }

            foreach (LeadSupplier::where('reply_received', false)->limit(500)->get() as $r) {
                if (! $r->last_email_at) {
                    continue;
                }
                $hrs = ($now->getTimestamp() - $r->last_email_at->getTimestamp()) / 3600;
                if ($hrs > 24) {
                    $alerts[] = [
                        'kind' => 'overdue', 'icon' => "\u{1F534}",
                        'title' => "#{$r->sequence} {$r->supplier_name} overdue ".(int) $hrs.'h',
                        'detail' => "Lead {$r->lead_id} · {$r->followups} follow-ups",
                        'lead_id' => $r->lead_id, 'timestamp' => $iso($r->last_email_at),
                    ];
                }
            }
        }

        // unread supplier replies / bounces (only rows persisted for this user: lead owner + rsq.manage holders)
        $unread = SupplierReply::query()
            ->join('supplier_reply_reads as rd', 'rd.reply_id', '=', 'supplier_replies.id')
            ->where('rd.user_id', $user->id)->whereNull('rd.read_at')
            ->whereIn('supplier_replies.status', ['matched', 'bounce'])
            ->with(['lead', 'leadSupplier'])
            ->select('supplier_replies.*')
            ->orderByDesc('supplier_replies.received_at')->limit(20)->get();
        foreach ($unread as $r) {
            $bounce = $r->status === 'bounce';
            $who = $r->leadSupplier?->supplier_name ?: ($r->from_name ?: $r->from_email);
            $alerts[] = [
                'kind' => $bounce ? 'supplier_bounce' : 'supplier_reply',
                'icon' => $bounce ? "\u{26A0}\u{FE0F}" : "\u{1F4E9}",
                'title' => ($bounce ? 'Delivery failed: ' : 'Reply from ').$who,
                'detail' => $r->lead_id.' · '.($r->lead?->customer_name ?? ''),
                'lead_id' => $r->lead_id, 'timestamp' => $iso($r->received_at),
                'reply_id' => (string) $r->id,
            ];
        }

        usort($alerts, fn ($a, $b) => strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? '')));
        return array_slice($alerts, 0, 20);
    }

    public function activity(Request $request)
    {
        $q = ActivityLog::query();
        if ($v = $request->query('user_email')) {
            $q->where('user_email', $v);
        }
        if ($v = $request->query('action')) {
            $q->where('action', $v);
        }
        if ($v = $request->query('entity')) {
            $q->where('entity', $v);
        }
        try {
            if ($v = $request->query('date_from')) {
                $q->where('timestamp', '>=', Carbon::parse($v, 'UTC'));
            }
            if ($v = $request->query('date_to')) {
                $q->where('timestamp', '<=', Carbon::parse($v.'T23:59:59', 'UTC'));
            }
        } catch (\Throwable) {
            // ignore unparseable dates
        }
        if (($s = (string) $request->query('q', '')) !== '') {
            $like = '%'.addcslashes($s, '\\%_').'%';
            $q->where(function ($w) use ($like) {
                foreach (['entity_id', 'user_name', 'user_email'] as $c) {
                    $w->orWhere($c, \App\Crm\Fail::ilike(), $like);
                }
            });
        }
        $q->orderByDesc('timestamp')->orderByDesc('id');
        $map = function ($d) {
            $a = $d->toArray();
            $a['timestamp'] = $d->timestamp ? Carbon::instance($d->timestamp)->utc()->toIso8601String() : null;
            return $a;
        };
        if (\App\Support\Paging::requested($request)) {
            return \App\Support\Paging::paginate($request, $q, $map);
        }
        $limit = max(1, (int) $request->query('limit', 500));
        return $q->limit($limit)->get()->map($map)->values();
    }

    public function activityFacets()
    {
        $distinct = fn (string $col) => ActivityLog::query()->whereNotNull($col)->distinct()->orderBy($col)->pluck($col)->values();
        return ['actions' => $distinct('action'), 'entities' => $distinct('entity'), 'users' => $distinct('user_email')];
    }
}
