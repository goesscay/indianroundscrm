<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What each person has done, from the records the system already keeps (contact log, notes, activities, quotes, deals, receipts,
 * supplier mails, trip checklist, activity log). Only what was recorded counts. Used by the Team Performance page and the dashboard.
 */
class TeamReport
{
    private const TZ = 'Asia/Kolkata';
    public const OPEN = "('New Lead','In Progress','Quote Sent to Customer')";

    /** Red flags: a person with more than this many overdue items is shown as behind. */
    public const FOLLOW_UPS_OVERDUE = 5;
    public const TRIP_TASKS_OVERDUE = 3;

    /** @return array{0:string,1:string} UTC [start, end) for IST dates from..to inclusive */
    public static function window(string $from, string $to): array
    {
        $s = Carbon::parse($from.' 00:00:00', self::TZ)->utc();
        $e = Carbon::parse($to.' 00:00:00', self::TZ)->addDay()->utc();

        return [$s->format('Y-m-d H:i:s'), $e->format('Y-m-d H:i:s')];
    }

    /** The same number of days straight before from..to. @return array{0:string,1:string} */
    public static function previous(string $from, string $to): array
    {
        $a = Carbon::parse($from, self::TZ);
        $days = $a->diffInDays(Carbon::parse($to, self::TZ)) + 1;

        return [$a->copy()->subDays($days)->toDateString(), $a->copy()->subDay()->toDateString()];
    }

    /** Everyone who works in the system, except Super Admins (the people reading the report). @return Collection<int,User> */
    public static function people(): Collection
    {
        return User::where('active', true)->where('role', '<>', 'super_admin')->orderBy('name')->get(['id', 'name', 'email', 'role', 'last_login_at']);
    }

    public static function group(string $role): string
    {
        return match ($role) {
            'sales' => 'sales',
            'operations' => 'operations',
            default => 'other',
        };
    }

    /** All counters for the window, per user id. @return array<int,array<string,float|int|null>> */
    public static function metrics(string $from, string $to): array
    {
        [$s, $e] = self::window($from, $to);
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');
        $cutoff = Carbon::now('UTC')->subDay()->format('Y-m-d H:i:s');
        $todayIst = Carbon::now(self::TZ)->toDateString();
        $by = fn ($rows, string $key) => collect($rows)->keyBy($key);

        $untouched = "archived_at is null and lead_status = 'New Lead' and not exists (select 1 from contact_events c where c.lead_id = leads.lead_id) and not exists (select 1 from lead_notes n where n.lead_id = leads.lead_id)";
        $leads = $by(DB::table('leads')->whereNotNull('staff_id')->selectRaw("staff_id,
            count(*) filter (where archived_at is null and lead_status in ".self::OPEN.") as open_leads,
            count(*) filter (where created_at >= ? and created_at < ?) as new_leads,
            count(*) filter (where lead_status = 'Lost' and updated_at >= ? and updated_at < ?) as lost,
            count(*) filter (where $untouched) as untouched,
            count(*) filter (where $untouched and created_at < ?) as untouched_24h", [$s, $e, $s, $e, $cutoff])->groupBy('staff_id')->get(), 'staff_id');

        $first = $by(DB::select("select l.staff_id, avg(extract(epoch from (fc.t - l.created_at)) / 3600.0) as hours from leads l
            join (select lead_id, min(timestamp) t from contact_events group by lead_id) fc on fc.lead_id = l.lead_id
            where l.staff_id is not null and l.created_at >= ? and l.created_at < ? and fc.t >= l.created_at group by l.staff_id", [$s, $e]), 'staff_id');

        $contact = $by(DB::table('contact_events')->whereNotNull('user_id')->where('timestamp', '>=', $s)->where('timestamp', '<', $e)->selectRaw("user_id,
            count(*) filter (where kind = 'call') as calls, count(*) filter (where kind = 'whatsapp') as whatsapps, count(*) filter (where kind = 'email') as emails,
            count(distinct lead_id) as leads_touched")->groupBy('user_id')->get(), 'user_id');

        $notes = $by(DB::table('lead_notes')->whereNotNull('created_by_id')->where('created_at', '>=', $s)->where('created_at', '<', $e)
            ->selectRaw('created_by_id as user_id, count(*) as notes')->groupBy('created_by_id')->get(), 'user_id');

        $acts = $by(DB::table('activities')->whereNotNull('assigned_to')->selectRaw("assigned_to as user_id,
            count(*) filter (where type = 'follow_up' and due_at >= ? and due_at < ?) as fu_due,
            count(*) filter (where type = 'follow_up' and due_at >= ? and due_at < ? and completed_at is not null) as fu_done,
            count(*) filter (where type = 'follow_up' and completed_at is null and due_at < ?) as fu_overdue,
            count(*) filter (where completed_at is null and due_at < ?) as activities_overdue", [$s, $e, $s, $e, $now, $now])->groupBy('assigned_to')->get(), 'user_id');

        $done = $by(DB::table('activities')->whereNotNull('completed_by')->where('completed_at', '>=', $s)->where('completed_at', '<', $e)
            ->selectRaw('completed_by as user_id, count(*) as activities_done')->groupBy('completed_by')->get(), 'user_id');

        $quotes = $by(DB::table('quotes')->whereNotNull('owner_id')->whereNotNull('sent_at')->where('sent_at', '>=', $s)->where('sent_at', '<', $e)
            ->selectRaw('owner_id as user_id, count(*) as quotes_sent')->groupBy('owner_id')->get(), 'user_id');

        $deals = $by(DB::table('deals')->whereNotNull('owner_id')->where('stage', 'won')->where('closed_at', '>=', $s)->where('closed_at', '<', $e)
            ->selectRaw('owner_id as user_id, count(*) as booked, coalesce(sum(selling_amount), 0) as booked_value')->groupBy('owner_id')->get(), 'user_id');

        $money = $by(DB::table('receipts')->whereNotNull('created_by')->where('kind', 'receipt')->where('status', 'valid')->where('created_at', '>=', $s)->where('created_at', '<', $e)
            ->selectRaw('created_by as user_id, count(*) as payments, coalesce(sum(amount), 0) as payments_value')->groupBy('created_by')->get(), 'user_id');

        $days = $by(DB::select("select user_id, count(distinct (timestamp at time zone 'Asia/Kolkata')::date) as active_days from activity_log
            where user_id is not null and timestamp >= ? and timestamp < ? group by user_id", [$s, $e]), 'user_id');

        $mails = $by(DB::select("select u.id as user_id, count(*) as supplier_mails, count(distinct m.lead_id) as enquiry_leads from mail_history m
            join users u on lower(u.email) = lower(m.sent_by) where m.sent_at >= ? and m.sent_at < ? group by u.id", [$s, $e]), 'user_id');

        $tasks = $by(DB::table('trip_tasks')->whereNotNull('assigned_to')->selectRaw("assigned_to as user_id,
            count(*) filter (where status in ('pending','in_progress')) as tasks_open,
            count(*) filter (where status in ('pending','in_progress') and due_date < ?) as tasks_overdue", [$todayIst])->groupBy('assigned_to')->get(), 'user_id');

        $tasksDone = $by(DB::table('trip_tasks')->whereNotNull('completed_by')->where('status', 'done')->where('completed_at', '>=', $s)->where('completed_at', '<', $e)
            ->selectRaw('completed_by as user_id, count(*) as tasks_done')->groupBy('completed_by')->get(), 'user_id');

        $docs = $by(DB::table('trip_documents')->whereNotNull('verified_by')->where('verified_at', '>=', $s)->where('verified_at', '<', $e)
            ->selectRaw('verified_by as user_id, count(*) as docs_verified')->groupBy('verified_by')->get(), 'user_id');

        $out = [];
        foreach (User::where('active', true)->pluck('id') as $id) {
            $g = fn ($set, string $k, $d = 0) => $set->has($id) ? ($set[$id]->$k ?? $d) : $d;
            $out[$id] = [
                'open_leads' => (int) $g($leads, 'open_leads'), 'new_leads' => (int) $g($leads, 'new_leads'), 'lost' => (int) $g($leads, 'lost'),
                'untouched' => (int) $g($leads, 'untouched'), 'untouched_24h' => (int) $g($leads, 'untouched_24h'),
                'first_contact_hours' => $first->has($id) ? round((float) $first[$id]->hours, 1) : null,
                'calls' => (int) $g($contact, 'calls'), 'whatsapps' => (int) $g($contact, 'whatsapps'), 'emails' => (int) $g($contact, 'emails'),
                'leads_touched' => (int) $g($contact, 'leads_touched'), 'notes' => (int) $g($notes, 'notes'),
                'fu_due' => (int) $g($acts, 'fu_due'), 'fu_done' => (int) $g($acts, 'fu_done'), 'fu_overdue' => (int) $g($acts, 'fu_overdue'),
                'activities_overdue' => (int) $g($acts, 'activities_overdue'), 'activities_done' => (int) $g($done, 'activities_done'),
                'quotes_sent' => (int) $g($quotes, 'quotes_sent'),
                'booked' => (int) $g($deals, 'booked'), 'booked_value' => round((float) $g($deals, 'booked_value'), 2),
                'payments' => (int) $g($money, 'payments'), 'payments_value' => round((float) $g($money, 'payments_value'), 2),
                'active_days' => (int) $g($days, 'active_days'),
                'supplier_mails' => (int) $g($mails, 'supplier_mails'), 'enquiry_leads' => (int) $g($mails, 'enquiry_leads'),
                'tasks_open' => (int) $g($tasks, 'tasks_open'), 'tasks_overdue' => (int) $g($tasks, 'tasks_overdue'), 'tasks_done' => (int) $g($tasksDone, 'tasks_done'),
                'docs_verified' => (int) $g($docs, 'docs_verified'),
            ];
        }

        return $out;
    }

    /** Things to raise on the call. @return list<array{key:string,label:string}> */
    public static function flags(array $m, string $group): array
    {
        $f = [];
        if ($group === 'sales' && $m['untouched_24h'] > 0) {
            $f[] = ['key' => 'untouched', 'label' => $m['untouched_24h'].' new lead'.($m['untouched_24h'] === 1 ? '' : 's').' not contacted for over 24 hours'];
        }
        if ($m['fu_overdue'] >= self::FOLLOW_UPS_OVERDUE) {
            $f[] = ['key' => 'followups', 'label' => $m['fu_overdue'].' overdue follow-ups'];
        }
        if ($group === 'operations' && $m['tasks_overdue'] >= self::TRIP_TASKS_OVERDUE) {
            $f[] = ['key' => 'tasks', 'label' => $m['tasks_overdue'].' overdue trip checklist items'];
        }

        return $f;
    }

    /** The whole report: one row per person with this period, the one before, and the flags. */
    public static function report(string $from, string $to): array
    {
        [$pf, $pt] = self::previous($from, $to);
        $now = self::metrics($from, $to);
        $before = self::metrics($pf, $pt);
        $people = self::people()->map(function (User $u) use ($now, $before) {
            $g = self::group($u->role);

            return [
                'id' => (string) $u->id, 'name' => $u->name, 'role' => $u->role, 'role_name' => \App\Support\Permissions::roleName($u->role), 'group' => $g,
                'last_login_at' => $u->last_login_at?->utc()->toIso8601String(),
                'm' => $now[$u->id], 'prev' => $before[$u->id], 'flags' => self::flags($now[$u->id], $g),
            ];
        })->values()->all();

        return ['period' => ['from' => $from, 'to' => $to], 'previous' => ['from' => $pf, 'to' => $pt], 'people' => $people];
    }

    /** Headline numbers for the dashboard: this month so far against the same days last month, and who is behind. */
    public static function summary(): array
    {
        $today = Carbon::now(self::TZ);
        $from = $today->copy()->startOfMonth()->toDateString();
        $r = self::report($from, $today->toDateString());
        $sum = fn (string $k, string $set = 'm', ?string $group = null) => collect($r['people'])->when($group, fn ($c) => $c->where('group', $group))->sum(fn ($p) => $p[$set][$k]);
        $behind = collect($r['people'])->filter(fn ($p) => $p['flags'])->sortByDesc(fn ($p) => $p['m']['untouched_24h'] + $p['m']['fu_overdue'] + $p['m']['tasks_overdue'])->take(6)
            ->map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'group' => $p['group'], 'flags' => $p['flags']])->values()->all();

        return [
            'period' => $r['period'], 'previous' => $r['previous'],
            'tiles' => [
                'new_leads' => ['now' => $sum('new_leads'), 'prev' => $sum('new_leads', 'prev')],
                'booked' => ['now' => $sum('booked'), 'prev' => $sum('booked', 'prev')],
                'booked_value' => ['now' => $sum('booked_value'), 'prev' => $sum('booked_value', 'prev')],
                'contacts' => ['now' => $sum('calls') + $sum('whatsapps') + $sum('emails'), 'prev' => $sum('calls', 'prev') + $sum('whatsapps', 'prev') + $sum('emails', 'prev')],
                'untouched_24h' => ['now' => $sum('untouched_24h'), 'prev' => null],
                'fu_overdue' => ['now' => $sum('fu_overdue'), 'prev' => null],
                'tasks_overdue' => ['now' => $sum('tasks_overdue'), 'prev' => null],
            ],
            'behind' => $behind,
        ];
    }

    /** One person's lists, for talking through specifics. */
    public static function drill(User $u, string $from, string $to): array
    {
        [$s, $e] = self::window($from, $to);
        $now = Carbon::now('UTC')->format('Y-m-d H:i:s');
        $todayIst = Carbon::now(self::TZ)->toDateString();
        $iso = fn ($v) => $v ? Carbon::parse($v)->utc()->toIso8601String() : null;

        $untouched = DB::table('leads')->where('staff_id', $u->id)->whereNull('archived_at')->where('lead_status', 'New Lead')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('contact_events as c')->whereColumn('c.lead_id', 'leads.lead_id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('lead_notes as n')->whereColumn('n.lead_id', 'leads.lead_id'))
            ->orderBy('created_at')->limit(50)->get(['lead_id', 'customer_name', 'destination', 'created_at'])
            ->map(fn ($l) => ['lead_id' => $l->lead_id, 'customer_name' => $l->customer_name, 'destination' => $l->destination, 'created_at' => $iso($l->created_at)]);

        $overdue = DB::table('activities')->where('assigned_to', $u->id)->whereNull('completed_at')->where('due_at', '<', $now)->orderBy('due_at')->limit(50)
            ->get(['id', 'type', 'title', 'lead_id', 'due_at'])->map(fn ($a) => ['id' => (string) $a->id, 'type' => $a->type, 'title' => $a->title, 'lead_id' => $a->lead_id, 'due_at' => $iso($a->due_at)]);

        $lost = DB::table('leads')->where('staff_id', $u->id)->where('lead_status', 'Lost')->where('updated_at', '>=', $s)->where('updated_at', '<', $e)->orderByDesc('updated_at')->limit(50)
            ->get(['lead_id', 'customer_name', 'destination', 'lost_reason']);
        $reasons = $lost->groupBy(fn ($l) => $l->lost_reason ?: 'No reason recorded')->map->count()->sortDesc()->map(fn ($n, $r) => ['reason' => $r, 'count' => $n])->values()->all();

        $booked = DB::table('deals')->join('customers', 'customers.id', '=', 'deals.customer_id')->where('deals.owner_id', $u->id)->where('deals.stage', 'won')
            ->where('deals.closed_at', '>=', $s)->where('deals.closed_at', '<', $e)->orderByDesc('deals.closed_at')->limit(50)
            ->get(['deals.id', 'deals.deal_no', 'deals.destination', 'deals.selling_amount', 'customers.name as customer'])
            ->map(fn ($d) => ['id' => (string) $d->id, 'deal_no' => $d->deal_no, 'customer' => $d->customer, 'destination' => $d->destination, 'amount' => (float) $d->selling_amount]);

        $tasks = DB::table('trip_tasks')->join('deals', 'deals.id', '=', 'trip_tasks.deal_id')->where('trip_tasks.assigned_to', $u->id)->whereIn('trip_tasks.status', ['pending', 'in_progress'])
            ->where('trip_tasks.due_date', '<', $todayIst)->orderBy('trip_tasks.due_date')->limit(50)->get(['trip_tasks.id', 'trip_tasks.title', 'trip_tasks.due_date', 'deals.id as deal_id', 'deals.deal_no'])
            ->map(fn ($t) => ['id' => (string) $t->id, 'title' => $t->title, 'due_date' => (string) $t->due_date, 'deal_id' => (string) $t->deal_id, 'deal_no' => $t->deal_no]);

        $daily = DB::select("select (timestamp at time zone 'Asia/Kolkata')::date as d, count(*) as n from activity_log where user_id = ? and timestamp >= ? and timestamp < ? group by 1 order by 1", [$u->id, $s, $e]);

        return [
            'person' => ['id' => (string) $u->id, 'name' => $u->name, 'role_name' => \App\Support\Permissions::roleName($u->role), 'group' => self::group($u->role)],
            'untouched' => $untouched->values()->all(), 'overdue_activities' => $overdue->values()->all(),
            'lost' => $lost->map(fn ($l) => ['lead_id' => $l->lead_id, 'customer_name' => $l->customer_name, 'destination' => $l->destination, 'lost_reason' => $l->lost_reason])->values()->all(), 'lost_reasons' => $reasons,
            'booked' => $booked->values()->all(), 'overdue_tasks' => $tasks->values()->all(),
            'daily' => array_map(fn ($r) => ['date' => (string) $r->d, 'actions' => (int) $r->n], $daily),
        ];
    }
}
