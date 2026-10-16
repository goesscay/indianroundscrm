<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ActivityController extends Controller
{
    public const WITH = ['assignee:id,name', 'creator:id,name', 'completer:id,name', 'lead:id,lead_id,customer_name', 'customer:id,name', 'deal:id,name'];

    // ------------------------------------------------------------------ helpers

    /** Visibility: view_all sees everything; others see assigned-to-me OR created-by-me OR linked to a lead they own. */
    private function scoped($query, User $user)
    {
        if (! $user->hasPermission('activities.view_all')) {
            $query->where(function ($w) use ($user) {
                $w->where('activities.assigned_to', $user->id)
                    ->orWhere('activities.created_by', $user->id)
                    ->orWhereIn('activities.lead_id', Lead::query()->where('staff_id', $user->id)->select('lead_id'));
            });
        }

        return $query;
    }

    private function canModify(Activity $a, User $user): bool
    {
        return $user->hasPermission('activities.manage')
            || (int) $a->created_by === (int) $user->id
            || (int) $a->assigned_to === (int) $user->id
            // activities the system opens by itself (a supplier or customer reply to review) belong to nobody until someone takes them:
            // whoever can see one and may work on activities can mark it done
            || ($a->assigned_to === null && $a->created_by === null && $user->hasPermission('activities.create'));
    }

    private function find(string $id, User $user): Activity
    {
        $a = ctype_digit($id)
            ? $this->scoped(Activity::query()->with(self::WITH), $user)->where('activities.id', (int) $id)->first()
            : null;
        if (! $a) {
            Fail::notFound('Activity not found');
        }

        return $a;
    }

    private function parseInstant(mixed $v, string $field): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $v)->utc();
        } catch (\Throwable) {
            Fail::unprocessable([$field => 'Invalid date-time']);
        }
    }

    private function activeUsers()
    {
        return User::query()->where('active', true)->whereNotIn('account_status', ['inactive', 'suspended']);
    }

    /** Resolve + authorize an assignee id (null = unassigned). */
    private function resolveAssignee(mixed $v, User $user): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_scalar($v) || ! ctype_digit((string) $v)) {
            Fail::unprocessable(['assigned_to' => 'Invalid user']);
        }
        $id = (int) $v;
        if (! $user->hasPermission('activities.manage') && $id !== (int) $user->id) {
            Fail::forbidden('Forbidden: you can only assign activities to yourself');
        }
        if (! $this->activeUsers()->where('id', $id)->exists()) {
            Fail::unprocessable(['assigned_to' => 'User not found or inactive']);
        }

        return $id;
    }

    private function resolveLead(mixed $v, User $user): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $exists = is_scalar($v) && LeadController::scopeForUser(Lead::query(), $user)->where('lead_id', (string) $v)->exists();
        if (! $exists) {
            Fail::unprocessable(['lead_id' => 'Lead not found']);
        }

        return (string) $v;
    }

    /** Optional link to a customer / deal the caller can see (null clears). */
    private function resolveLinked(mixed $v, string $field, User $user): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $ok = false;
        if (is_scalar($v) && ctype_digit((string) $v)) {
            $q = $field === 'customer_id'
                ? CustomerController::scopeForUser(\App\Models\Customer::query(), $user)->where('customers.id', (int) $v)
                : DealController::scopeForUser(\App\Models\Deal::query(), $user)->where('deals.id', (int) $v);
            $ok = $q->exists();
        }
        if (! $ok) {
            Fail::unprocessable([$field => $field === 'customer_id' ? 'Customer not found' : 'Deal not found']);
        }

        return (int) $v;
    }

    private function validated(array $b, bool $partial): array
    {
        $req = $partial ? 'sometimes|' : '';

        return Fail::validate($b, [
            'type' => $req.'nullable|in:'.implode(',', Activity::TYPES),
            'title' => ($partial ? 'sometimes|required' : 'required').'|string|max:200',
            'details' => 'nullable|string',
            'priority' => $req.'nullable|in:'.implode(',', Activity::PRIORITIES),
            'due_at' => 'nullable|string',
            'assigned_to' => 'nullable',
            'lead_id' => 'nullable',
            'customer_id' => 'nullable',
            'deal_id' => 'nullable',
            'done' => 'sometimes|boolean',
        ]);
    }

    /** Audit snapshot. Never includes the details text. */
    private function logState(Activity $a): array
    {
        return [
            'type' => $a->type, 'title' => $a->title, 'priority' => $a->priority,
            'due_at' => $a->due_at?->utc()->toIso8601String(),
            'assigned_to' => $a->assigned_to, 'lead_id' => $a->lead_id,
            'done' => $a->completed_at !== null,
        ];
    }

    /** Shared filters for list/counts/calendar: q, type, owner, lead_id, on top of the visibility scope. */
    private function filtered(Request $request, User $user)
    {
        $q = $this->scoped(Activity::query(), $user);
        if (($v = (string) $request->query('type', '')) !== '') {
            $q->where('activities.type', $v);
        }
        if (($v = (string) $request->query('lead_id', '')) !== '') {
            $q->where('activities.lead_id', $v);
        }
        $owner = (string) $request->query('owner', 'all');
        if ($owner === 'me') {
            $q->where('activities.assigned_to', $user->id);
        } elseif ($owner === 'unassigned') {
            $q->whereNull('activities.assigned_to');
        } elseif ($owner !== '' && $owner !== 'all') {
            $q->where('activities.assigned_to', ctype_digit($owner) ? (int) $owner : -1);
        }
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($s, '\\%_').'%';
            $op = Fail::ilike();
            $q->where(function ($w) use ($like, $op) {
                $w->where('activities.title', $op, $like)
                    ->orWhere('activities.details', $op, $like)
                    ->orWhereIn('activities.lead_id', Lead::query()->select('lead_id')
                        ->where(fn ($l) => $l->where('customer_name', $op, $like)->orWhere('lead_id', $op, $like)));
            });
        }

        return $q;
    }

    /** @return array{0:Carbon,1:Carbon} [day_start, day_end] of the caller's local day (UTC day when absent) */
    private function dayRange(Request $request): array
    {
        $start = $this->parseInstant($request->query('day_start'), 'day_start') ?? now()->utc()->startOfDay();
        $end = $this->parseInstant($request->query('day_end'), 'day_end') ?? $start->copy()->addDay()->subSecond();

        return [$start, $end];
    }

    // ------------------------------------------------------------------ routes

    public function index(Request $request)
    {
        $user = $request->user();
        $now = now();
        [, $dayEnd] = $this->dayRange($request);
        $q = $this->filtered($request, $user);

        $c = (clone $q)->toBase()->selectRaw(
            'COUNT(*) FILTER (WHERE activities.completed_at IS NULL) AS open,
             COUNT(*) FILTER (WHERE activities.completed_at IS NULL AND activities.due_at < ?) AS overdue,
             COUNT(*) FILTER (WHERE activities.completed_at IS NULL AND activities.due_at >= ? AND activities.due_at <= ?) AS today,
             COUNT(*) FILTER (WHERE activities.completed_at IS NULL AND activities.due_at >= ? AND activities.due_at > ?) AS upcoming,
             COUNT(*) FILTER (WHERE activities.completed_at IS NULL AND activities.due_at IS NULL) AS no_date,
             COUNT(*) FILTER (WHERE activities.completed_at IS NOT NULL) AS done',
            [$now, $now, $dayEnd, $now, $dayEnd]
        )->first();
        $counts = array_map('intval', (array) $c);

        $tab = (string) $request->query('tab', 'open');
        match ($tab) {
            'all' => null,
            'overdue' => $q->whereNull('activities.completed_at')->where('activities.due_at', '<', $now),
            'today' => $q->whereNull('activities.completed_at')->where('activities.due_at', '>=', $now)->where('activities.due_at', '<=', $dayEnd),
            'upcoming' => $q->whereNull('activities.completed_at')->where('activities.due_at', '>=', $now)->where('activities.due_at', '>', $dayEnd),
            'no_date' => $q->whereNull('activities.completed_at')->whereNull('activities.due_at'),
            'done' => $q->whereNotNull('activities.completed_at'),
            default => $q->whereNull('activities.completed_at'),
        };

        $sort = (string) $request->query('sort', 'due_at');
        $dir = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        if (! in_array($sort, ['due_at', 'created_at', 'title'], true)) {
            $sort = 'due_at';
        }
        if ($sort === 'due_at') {
            $q->orderByRaw("activities.due_at $dir NULLS LAST");
        } else {
            $q->orderBy("activities.$sort", $dir);
        }
        $q->orderBy('activities.id');
        $q->with(self::WITH)->select('activities.*');

        if (Paging::requested($request)) {
            $res = Paging::paginate($request, $q, fn (Activity $a) => $a->toApi());
            $res['counts'] = $counts;

            return $res;
        }
        $limit = max(1, (int) $request->query('limit', 500));

        return $q->limit($limit)->get()->map(fn (Activity $a) => $a->toApi())->values();
    }

    public function calendar(Request $request)
    {
        $user = $request->user();
        $from = $this->parseInstant($request->query('from'), 'from') ?? now()->utc()->startOfMonth();
        $to = $this->parseInstant($request->query('to'), 'to') ?? $from->copy()->addMonth();

        return $this->filtered($request, $user)
            ->where('activities.due_at', '>=', $from)->where('activities.due_at', '<', $to)
            ->orderBy('activities.due_at')->orderBy('activities.id')
            ->with(self::WITH)->select('activities.*')->limit(1000)->get()
            ->map(fn (Activity $a) => $a->toApi())->values();
    }

    public function options(Request $request)
    {
        $user = $request->user();
        $users = $this->activeUsers()->orderBy('name');
        if (! $user->hasPermission('activities.manage')) {
            $users->where('id', $user->id);
        }

        return [
            'users' => $users->get(['id', 'name', 'role'])
                ->map(fn ($u) => ['id' => (string) $u->id, 'name' => $u->name, 'role' => $u->role])->values(),
            'types' => Activity::TYPES,
            'priorities' => Activity::PRIORITIES,
        ];
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $d = $this->validated(Fail::body($request), false);

        $a = new Activity([
            'type' => $d['type'] ?? 'task',
            'title' => trim($d['title']),
            'details' => $d['details'] ?? null,
            'priority' => $d['priority'] ?? 'medium',
            'due_at' => $this->parseInstant($d['due_at'] ?? null, 'due_at'),
            'assigned_to' => $this->resolveAssignee($d['assigned_to'] ?? null, $user),
            'lead_id' => $this->resolveLead($d['lead_id'] ?? null, $user),
            'customer_id' => $this->resolveLinked($d['customer_id'] ?? null, 'customer_id', $user),
            'deal_id' => $this->resolveLinked($d['deal_id'] ?? null, 'deal_id', $user),
            'created_by' => $user->id,
        ]);
        if (! empty($d['done'])) {
            $a->completed_at = now();
            $a->completed_by = $user->id;
        }
        $a->save();
        ActivityLogger::log($user, 'create_activity', 'activity', $a->id, null, $this->logState($a));

        return $a->load(self::WITH)->toApi();
    }

    public function show(Request $request, string $id)
    {
        return $this->find($id, $request->user())->toApi();
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        $a = $this->find($id, $user);
        if (! $this->canModify($a, $user)) {
            Fail::forbidden('Forbidden');
        }
        $d = $this->validated(Fail::body($request), true);
        $before = $this->logState($a);
        $wasDone = $a->completed_at !== null;

        foreach (['type', 'priority'] as $f) {
            if (array_key_exists($f, $d) && $d[$f] !== null) {
                $a->{$f} = $d[$f];
            }
        }
        if (array_key_exists('title', $d)) {
            $a->title = trim($d['title']);
        }
        if (array_key_exists('details', $d)) {
            $a->details = $d['details'];
        }
        if (array_key_exists('due_at', $d)) {
            $a->due_at = $this->parseInstant($d['due_at'], 'due_at');
        }
        if (array_key_exists('assigned_to', $d)) {
            $a->assigned_to = $this->resolveAssignee($d['assigned_to'], $user);
        }
        if (array_key_exists('lead_id', $d)) {
            // an unchanged link stays valid even if the caller no longer owns the lead
            $a->lead_id = ($d['lead_id'] !== null && $d['lead_id'] === $a->lead_id)
                ? $a->lead_id : $this->resolveLead($d['lead_id'], $user);
        }
        foreach (['customer_id', 'deal_id'] as $f) {
            if (array_key_exists($f, $d)) {
                $a->{$f} = ((string) $d[$f] !== '' && (string) $d[$f] === (string) $a->{$f}) ? $a->{$f} : $this->resolveLinked($d[$f], $f, $user);
            }
        }
        if (array_key_exists('done', $d)) {
            if ($d['done'] && ! $wasDone) {
                $a->completed_at = now();
                $a->completed_by = $user->id;
            } elseif (! $d['done']) {
                $a->completed_at = null;
                $a->completed_by = null;
            }
        }
        $a->save();
        $a->unsetRelations();
        $a->load(self::WITH);
        $after = $this->logState($a);
        $nowDone = $a->completed_at !== null;

        if ($nowDone && ! $wasDone) {
            ActivityLogger::log($user, 'complete_activity', 'activity', $a->id, $before, $after);
        }
        $changed = array_diff_key(array_diff_assoc($after, $before), ['done' => 1]);
        $reopened = $wasDone && ! $nowDone;
        $detailsEdited = $a->wasChanged('details');
        if ($changed || $reopened || $detailsEdited) {
            ActivityLogger::log($user, 'update_activity', 'activity', $a->id, $before, $after);
        }

        return $a->toApi();
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $a = $this->find($id, $user);
        if (! $this->canModify($a, $user)) {
            Fail::forbidden('Forbidden');
        }
        $state = $this->logState($a);
        $a->delete();
        ActivityLogger::log($user, 'delete_activity', 'activity', $id, $state, null);

        return ['ok' => true];
    }

    public function forLead(Request $request, string $leadId)
    {
        $user = $request->user();
        $lead = Lead::where('lead_id', $leadId)->first();
        if (! $lead) {
            Fail::notFound('Lead not found');
        }
        if (! $user->hasPermission('leads.view_all') && (int) $lead->staff_id !== (int) $user->id) {
            Fail::forbidden('Forbidden');
        }

        return Activity::query()->where('activities.lead_id', $leadId)
            ->orderByRaw('(activities.completed_at IS NOT NULL) ASC')
            ->orderByRaw('activities.due_at DESC NULLS LAST')
            ->orderByDesc('activities.id')
            ->with(self::WITH)->select('activities.*')->get()
            ->map(fn (Activity $a) => $a->toApi())->values();
    }
}
