<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeadSupplier;
use App\Models\Quote;
use App\Models\Receipt;
use App\Models\SupplierReply;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** The home dashboard: one call that returns only the widgets the signed-in user may see. */
class HomeController extends Controller
{
    private const TZ = 'Asia/Kolkata';
    private const CLOSED_LEADS = ['Booked', 'Completed', 'Lost'];

    public function overview(Request $request)
    {
        $user = $request->user();
        $now = Carbon::now(self::TZ);
        $today = $now->copy()->startOfDay();
        $can = fn (string ...$p) => $user->hasAnyPermission(...$p);

        $out = [
            'generated_at' => $now->toIso8601String(),
            'kpis' => [], 'focus' => null, 'pipeline' => null, 'target' => null, 'months' => null, 'departures' => null,
            'collections' => null, 'payments' => null, 'leaderboard' => null, 'destinations' => null, 'supplier' => null,
        ];

        $dealsOn = $can('deals.view');
        $deals = $dealsOn ? DealController::scopeForUser(Deal::query(), $user) : null;
        $leadsOn = $can('leads.view_all', 'leads.create', 'leads.edit', 'leads.edit_limited');
        $monthStart = $today->copy()->startOfMonth();

        // ------------------------------------------------------------------ KPIs
        if ($dealsOn) {
            $open = (clone $deals)->whereNotIn('stage', ['won', 'lost'])->get(['selling_amount', 'probability']);
            $won = (clone $deals)->where('stage', 'won')->whereBetween('closed_at', [$monthStart->copy()->utc(), $now->copy()->utc()])->get(['selling_amount', 'supplier_cost']);
            $out['kpis'][] = ['key' => 'pipeline', 'label' => 'Open pipeline', 'value' => round((float) $open->sum('selling_amount'), 2), 'format' => 'inr',
                'sub' => $open->count().' open deal'.($open->count() === 1 ? '' : 's').' · '.number_format($open->sum(fn ($d) => $d->selling_amount * $d->probability / 100)).' weighted', 'href' => '/deals'];
            $out['kpis'][] = ['key' => 'won', 'label' => 'Won this month', 'value' => round((float) $won->sum('selling_amount'), 2), 'format' => 'inr',
                'sub' => $won->count().' trip'.($won->count() === 1 ? '' : 's').' booked', 'href' => '/forecasts'];
        }
        if ($can('invoices.view')) {
            $inv = InvoiceController::scopeForUser(Invoice::query()->with('installments'), $user)->where('status', 'issued')->get();
            $owing = $inv->filter(fn (Invoice $i) => $i->balance() > 0.005);
            $overdue = $owing->filter(fn (Invoice $i) => $i->paymentStatus() === 'overdue');
            $out['kpis'][] = ['key' => 'collect', 'label' => 'To collect', 'value' => round((float) $owing->sum(fn ($i) => $i->balance()), 2), 'format' => 'inr',
                'sub' => $overdue->count() ? $overdue->count().' overdue · ₹'.number_format($overdue->sum(fn ($i) => $i->balance())) : 'Nothing overdue', 'tone' => $overdue->count() ? 'warn' : null, 'href' => '/invoices?status=unpaid'];
        }
        if ($can('quotes.view')) {
            $sent = QuoteController::scopeForUser(Quote::query(), $user)->where('status', 'sent')->where(fn ($w) => $w->whereNull('valid_until')->orWhere('valid_until', '>=', $today->toDateString()))->get(['total']);
            $out['kpis'][] = ['key' => 'quotes', 'label' => 'Quotes awaiting reply', 'value' => $sent->count(), 'format' => 'number',
                'sub' => '₹'.number_format($sent->sum('total')).' offered', 'href' => '/quotes?status=sent'];
        }

        // ------------------------------------------------------------------ today's focus: tasks and follow-ups (Activities)
        $focus = [];
        $tasksDue = 0;
        if ($can('activities.view')) {
            $q = Activity::query()->with('lead:id,lead_id,customer_name', 'customer:id,name', 'deal:id,name')->whereNull('completed_at')->whereNotNull('due_at')->where('due_at', '<=', $today->copy()->endOfDay()->utc());
            if (! $can('activities.view_all')) {
                $q->where(fn ($w) => $w->where('assigned_to', $user->id)->orWhere('created_by', $user->id)
                    ->orWhereIn('lead_id', Lead::query()->where('staff_id', $user->id)->select('lead_id')));
            }
            $rows = $q->orderBy('due_at')->limit(30)->get();
            $tasksDue = $rows->count();
            foreach ($rows as $a) {
                $due = $a->due_at->copy()->timezone(self::TZ);
                $who = $a->lead?->customer_name ?? $a->customer?->name ?? $a->deal?->name;
                $focus[] = ['kind' => 'task', 'id' => (string) $a->id, 'type' => $a->type, 'title' => $a->title, 'sub' => $who, 'due_at' => $due->toIso8601String(), 'overdue' => $due->lt($now),
                    'href' => $a->lead ? '/leads/'.$a->lead->lead_id : ($a->deal_id ? '/deals/'.$a->deal_id : ($a->customer_id ? '/customers/'.$a->customer_id : '/activities'))];
            }
        }
        if ($leadsOn) {
            $out['kpis'][] = ['key' => 'followups', 'label' => 'Follow-ups due', 'value' => $tasksDue, 'format' => 'number',
                'sub' => collect($focus)->where('overdue', true)->count().' overdue', 'tone' => collect($focus)->where('overdue', true)->count() ? 'warn' : null, 'href' => '/activities'];
            $week = LeadController::scopeForUser(Lead::query(), $user)->where('created_at', '>=', $today->copy()->subDays(6)->utc())->count();
            $out['kpis'][] = ['key' => 'leads', 'label' => 'New leads · 7 days', 'value' => $week, 'format' => 'number',
                'sub' => LeadController::scopeForUser(Lead::query(), $user)->where('created_at', '>=', $today->copy()->utc())->count().' today', 'href' => '/leads'];
        }
        if ($focus || $can('activities.view') || $leadsOn) {
            usort($focus, fn ($a, $b) => ($b['overdue'] <=> $a['overdue']) ?: strcmp($a['due_at'], $b['due_at']));
            $out['focus'] = ['items' => array_slice($focus, 0, 9), 'total' => count($focus), 'overdue' => collect($focus)->where('overdue', true)->count()];
        }

        // ------------------------------------------------------------------ pipeline by stage
        if ($dealsOn) {
            $stages = [];
            foreach (['enquiry' => 'Enquiry', 'quoted' => 'Quoted', 'negotiation' => 'Negotiation'] as $k => $label) {
                $r = (clone $deals)->where('stage', $k)->selectRaw('COUNT(*) as c, COALESCE(SUM(selling_amount),0) as v')->first();
                $stages[] = ['stage' => $k, 'label' => $label, 'count' => (int) $r->c, 'value' => round((float) $r->v, 2)];
            }
            $fyStart = Carbon::create(ForecastController::fyOf($today), 4, 1, 0, 0, 0, self::TZ);
            $r = (clone $deals)->where('stage', 'won')->where('closed_at', '>=', $fyStart->copy()->utc())->selectRaw('COUNT(*) as c, COALESCE(SUM(selling_amount),0) as v')->first();
            $stages[] = ['stage' => 'won', 'label' => 'Won this year', 'count' => (int) $r->c, 'value' => round((float) $r->v, 2)];
            $lost = (clone $deals)->where('stage', 'lost')->where('closed_at', '>=', $fyStart->copy()->utc())->count();
            $out['pipeline'] = ['stages' => $stages, 'lost' => $lost];
        }

        // ------------------------------------------------------------------ this quarter against target
        if ($can('forecasts.view')) {
            $fy = ForecastController::fyOf($today);
            $qn = ForecastController::quarterOf($today);
            $qs = Carbon::create($fy, 4, 1, 0, 0, 0, self::TZ)->addMonthsNoOverflow(($qn - 1) * 3);
            $qe = $qs->copy()->addMonthsNoOverflow(3);
            $all = $can('forecasts.view_all');
            $t = DB::table('forecast_targets')->where('fy', $fy)->where('quarter', $qn);
            $target = $all ? (float) ((clone $t)->where('user_id', 0)->value('amount') ?? (clone $t)->sum('amount')) : (float) ((clone $t)->where('user_id', $user->id)->value('amount') ?? 0);
            $wq = Deal::query()->where('stage', 'won')->whereBetween('closed_at', [$qs->copy()->utc(), $qe->copy()->utc()]);
            if (! $all) {
                $wq->where('owner_id', $user->id);
            }
            $won = round((float) $wq->sum('selling_amount'), 2);
            $out['target'] = ['label' => "Q{$qn} · ".['Apr–Jun', 'Jul–Sep', 'Oct–Dec', 'Jan–Mar'][$qn - 1], 'target' => $target, 'won' => $won,
                'pct' => $target > 0 ? round($won / $target * 100, 1) : null, 'days_left' => max(0, (int) $today->diffInDays($qe->copy()->subDay(), false)), 'scope' => $all ? 'team' : 'you'];
        }

        // ------------------------------------------------------------------ six months of wins and new leads
        if ($dealsOn || $leadsOn) {
            $months = [];
            for ($i = 5; $i >= 0; $i--) {
                $m = $monthStart->copy()->subMonthsNoOverflow($i);
                $e = $m->copy()->addMonthNoOverflow();
                $row = ['label' => $m->format('M'), 'won' => 0.0, 'leads' => 0];
                if ($dealsOn) {
                    $row['won'] = round((float) (clone $deals)->where('stage', 'won')->whereBetween('closed_at', [$m->copy()->utc(), $e->copy()->utc()])->sum('selling_amount'), 2);
                }
                if ($leadsOn) {
                    $row['leads'] = LeadController::scopeForUser(Lead::query(), $user)->whereBetween('created_at', [$m->copy()->utc(), $e->copy()->utc()])->count();
                }
                $months[] = $row;
            }
            $out['months'] = $months;
        }

        // ------------------------------------------------------------------ upcoming departures (next 21 days)
        if ($dealsOn) {
            $trips = (clone $deals)->with('customer:id,customer_no,name')->where('stage', 'won')->whereBetween('travel_date', [$today->toDateString(), $today->copy()->addDays(21)->toDateString()])
                ->orderBy('travel_date')->limit(8)->get();
            $due = Invoice::query()->with('installments')->whereIn('deal_id', $trips->pluck('id'))->where('status', 'issued')->get()->groupBy('deal_id')
                ->map(fn ($g) => round((float) $g->sum(fn (Invoice $i) => $i->balance()), 2));
            $out['departures'] = $trips->map(fn (Deal $d) => [
                'id' => (string) $d->id, 'name' => $d->name, 'customer' => $d->customer?->name, 'destination' => $d->destination, 'travel_date' => $d->travel_date->format('Y-m-d'),
                'days' => (int) $today->diffInDays(Carbon::parse($d->travel_date->format('Y-m-d'), self::TZ), false), 'pax' => $d->adults + $d->children, 'trip_status' => $d->trip_status,
                'balance_due' => (float) ($due[$d->id] ?? 0),
            ])->values();
        }

        // ------------------------------------------------------------------ payments to chase
        if ($can('invoices.view')) {
            $list = InvoiceController::scopeForUser(Invoice::query()->with(['customer:id,name', 'installments']), $user)->where('status', 'issued')->get()
                ->filter(fn (Invoice $i) => $i->balance() > 0.005)
                ->map(function (Invoice $i) use ($today) {
                    $next = collect($i->scheduleStatus())->first(fn ($s) => $s['status'] !== 'paid');
                    $due = $next['due_date'] ?? null;

                    return ['id' => (string) $i->id, 'invoice_no' => $i->invoice_no, 'customer' => $i->customer?->name, 'balance' => $i->balance(), 'due_date' => $due,
                        'overdue' => $i->paymentStatus() === 'overdue', 'days' => $due ? (int) $today->diffInDays(Carbon::parse($due, self::TZ), false) : null];
                })
                ->filter(fn ($r) => $r['overdue'] || ($r['days'] !== null && $r['days'] <= 7))
                ->sortBy(fn ($r) => [$r['overdue'] ? 0 : 1, $r['due_date']])->values();
            $out['collections'] = ['items' => $list->take(6)->values(), 'total' => $list->count(), 'amount' => round((float) $list->sum('balance'), 2)];
        }
        if ($can('receipts.view')) {
            $out['payments'] = ReceiptController::scopeForUser(Receipt::query()->with(['customer:id,name', 'invoice:id,invoice_no']), $user)->where('status', 'valid')->where('kind', 'receipt')
                ->orderByDesc('received_date')->orderByDesc('id')->limit(5)->get()
                ->map(fn (Receipt $r) => ['id' => (string) $r->id, 'receipt_no' => $r->receipt_no, 'customer' => $r->customer?->name, 'invoice_no' => $r->invoice?->invoice_no, 'amount' => $r->amount, 'date' => $r->received_date->format('Y-m-d')])->values();
        }

        // ------------------------------------------------------------------ team leaderboard and where trips are going
        if ($dealsOn && $can('deals.view_all')) {
            $fyStart ??= Carbon::create(ForecastController::fyOf($today), 4, 1, 0, 0, 0, self::TZ);
            $out['leaderboard'] = Deal::query()->with('owner:id,name')->where('stage', 'won')->where('closed_at', '>=', $monthStart->copy()->utc())
                ->selectRaw('owner_id, COUNT(*) as c, SUM(selling_amount) as v')->groupBy('owner_id')->orderByDesc('v')->limit(5)->get()
                ->map(fn ($r) => ['name' => User::find($r->owner_id)?->name ?? 'Unassigned', 'deals' => (int) $r->c, 'value' => round((float) $r->v, 2)])->values();
        }
        if ($dealsOn) {
            $out['destinations'] = (clone $deals)->whereNotNull('destination')->where('destination', '!=', '')->whereNotIn('stage', ['lost'])
                ->selectRaw('destination, COUNT(*) as c, SUM(selling_amount) as v')->groupBy('destination')->orderByDesc('v')->limit(5)->get()
                ->map(fn ($r) => ['destination' => $r->destination, 'deals' => (int) $r->c, 'value' => round((float) $r->v, 2)])->values();
        }

        // ------------------------------------------------------------------ supplier desk
        if ($can('supplier_dashboard.view', 'rsq.manage')) {
            $awaiting = LeadSupplier::where('reply_received', false)->get(['last_email_at']);
            $out['supplier'] = [
                'awaiting' => $awaiting->count(),
                'overdue' => $awaiting->filter(fn ($r) => $r->last_email_at && $r->last_email_at->lt(now()->subHours(24)))->count(),
                'unmatched' => SupplierReply::where('status', 'unmatched')->count(),
                'replies_today' => LeadSupplier::where('reply_received', true)->where('reply_date', '>=', now()->subDay())->count(),
            ];
        }

        return $out;
    }
}
