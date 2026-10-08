<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\TripTask;
use App\Models\TripTemplate;
use App\Models\TripTemplateItem;
use App\Models\User;
use App\Services\TripPlan;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Trip files: payment progress and the operations checklist of a confirmed deal. */
class TripController extends Controller
{
    // ------------------------------------------------------------------ helpers

    private function summary(Deal $deal): array
    {
        $deal->loadMissing(['customer:id,customer_no,name', 'owner:id,name']);

        return [
            'deal' => ['id' => (string) $deal->id, 'deal_no' => $deal->deal_no, 'name' => $deal->name, 'destination' => $deal->destination, 'travel_date' => $deal->travel_date?->format('Y-m-d'),
                'duration_days' => $deal->duration_days, 'adults' => $deal->adults, 'children' => $deal->children, 'stage' => $deal->stage, 'trip_status' => $deal->trip_status],
            'customer' => $deal->customer ? ['id' => (string) $deal->customer->id, 'name' => $deal->customer->name] : null,
            'owner' => $deal->owner ? ['id' => (string) $deal->owner->id, 'name' => $deal->owner->name] : null,
            'confirmed' => TripPlan::confirmed($deal), 'trip_type' => $deal->trip_type, 'confirmed_at' => $deal->trip_confirmed_at?->utc()->toIso8601String(),
            'days_left' => $deal->travel_date ? (int) now('Asia/Kolkata')->startOfDay()->diffInDays($deal->travel_date->copy()->startOfDay(), false) : null,
            'payment' => TripPlan::payment($deal), 'progress' => TripPlan::progress($deal),
        ];
    }

    private function task(string $id, User $user): TripTask
    {
        $t = ctype_digit($id) ? TripTask::with('assignee:id,name')->find((int) $id) : null;
        if (! $t) {
            Fail::notFound('Task not found');
        }
        DealController::find((string) $t->deal_id, $user); // 404 unless the user can see the deal

        return $t;
    }

    private function assignee(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $u = ctype_digit((string) $v) ? User::find((int) $v) : null;
        if (! $u || ! $u->active || $u->account_status !== 'active') {
            Fail::unprocessable(['assigned_to' => 'Choose an active user']);
        }

        return (int) $u->id;
    }

    private function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || ! checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
            Fail::unprocessable(['due_date' => 'Use a valid date']);
        }

        return $v;
    }

    // ------------------------------------------------------------------ a deal's trip

    public function show(Request $request, string $id)
    {
        $deal = DealController::find($id, $request->user());
        $users = User::where('active', true)->where('account_status', 'active')->orderBy('name')->get(['id', 'name'])
            ->filter(fn (User $u) => $u->hasAnyPermission('trips.manage', 'trips.confirm'))->map(fn (User $u) => ['id' => (string) $u->id, 'name' => $u->name])->values();

        return $this->summary($deal) + [
            'tasks' => TripTask::with('assignee:id,name')->where('deal_id', $deal->id)->orderByRaw('due_date NULLS LAST')->orderBy('position')->orderBy('id')->get()->map(fn (TripTask $t) => $t->toApi())->values()->all(),
            'assignees' => $users, 'advance_percent' => TripPlan::advancePercent(),
        ];
    }

    public function confirm(Request $request, string $id)
    {
        $user = $request->user();
        $deal = DealController::find($id, $user);
        $d = Fail::validate(Fail::body($request), ['trip_type' => 'required|in:'.implode(',', TripTemplate::TYPES)]);
        if (TripPlan::confirmed($deal)) {
            Fail::http(409, 'This trip is already confirmed.');
        }
        if ($deal->stage === 'lost') {
            Fail::http(409, 'A lost deal cannot be confirmed as a trip.');
        }
        if ($deal->stage !== 'won') { // confirming a trip books the deal
            $deal->forceFill(['stage' => 'won', 'probability' => 100, 'closed_at' => $deal->closed_at ?: now(), 'lost_reason' => null])->save();
        }
        TripPlan::confirm($deal, $user, $d['trip_type'], 'manual');

        return $this->show($request, $id);
    }

    public function addTask(Request $request, string $id)
    {
        $user = $request->user();
        $deal = DealController::find($id, $user);
        if (! TripPlan::confirmed($deal)) {
            Fail::http(409, 'Confirm the trip first.');
        }
        $d = Fail::validate(Fail::body($request), [
            'title' => 'required|string|max:200', 'category' => 'nullable|in:'.implode(',', TripTask::CATEGORIES), 'due_date' => 'nullable|string',
            'notes' => 'nullable|string|max:5000', 'critical' => 'sometimes|boolean', 'assigned_to' => 'nullable',
        ]);
        $t = TripTask::create([
            'deal_id' => $deal->id, 'status' => 'pending', 'category' => $d['category'] ?? 'other', 'title' => trim($d['title']), 'notes' => $d['notes'] ?? null, 'due_date' => $this->date($d['due_date'] ?? null),
            'critical' => (bool) ($d['critical'] ?? false), 'assigned_to' => $this->assignee($d['assigned_to'] ?? null), 'created_by' => $user->id,
            'position' => (int) TripTask::where('deal_id', $deal->id)->max('position') + 1,
        ]);
        ActivityLogger::log($user, 'add_trip_task', 'deal', $deal->id, null, ['title' => $t->title]);

        return $t->load('assignee:id,name')->toApi();
    }

    public function updateTask(Request $request, string $id)
    {
        $user = $request->user();
        $t = $this->task($id, $user);
        $d = Fail::validate(Fail::body($request), [
            'title' => 'sometimes|required|string|max:200', 'category' => 'sometimes|in:'.implode(',', TripTask::CATEGORIES), 'due_date' => 'nullable|string',
            'notes' => 'nullable|string|max:5000', 'critical' => 'sometimes|boolean', 'assigned_to' => 'nullable', 'status' => 'sometimes|in:'.implode(',', TripTask::STATUSES),
        ]);
        $before = ['status' => $t->status, 'title' => $t->title];
        foreach (['title', 'category', 'notes', 'critical'] as $k) {
            if (array_key_exists($k, $d)) {
                $t->{$k} = $k === 'title' ? trim($d[$k]) : $d[$k];
            }
        }
        if (array_key_exists('due_date', $d)) {
            $t->due_date = $this->date($d['due_date']);
        }
        if (array_key_exists('assigned_to', $d)) {
            $t->assigned_to = $this->assignee($d['assigned_to']);
        }
        if (array_key_exists('status', $d) && $d['status'] !== $t->status) {
            $t->status = $d['status'];
            $done = $d['status'] === 'done';
            $t->completed_at = $done ? now() : null;
            $t->completed_by = $done ? $user->id : null;
        }
        $t->save();
        ActivityLogger::log($user, 'update_trip_task', 'deal', $t->deal_id, $before, ['status' => $t->status, 'title' => $t->title]);

        return $t->load('assignee:id,name')->toApi();
    }

    public function deleteTask(Request $request, string $id)
    {
        $user = $request->user();
        $t = $this->task($id, $user);
        $t->delete();
        ActivityLogger::log($user, 'delete_trip_task', 'deal', $t->deal_id, ['title' => $t->title], null);

        return ['success' => true];
    }

    // ------------------------------------------------------------------ board and customer view

    /** Operations board: confirmed trips, soonest departure first. `scope` = upcoming (default) | completed | all. */
    public function board(Request $request)
    {
        $user = $request->user();
        $scope = (string) $request->query('scope', 'upcoming');
        $q = DealController::scopeForUser(Deal::query()->with(['customer:id,customer_no,name', 'owner:id,name']), $user)->whereNotNull('deals.trip_confirmed_at');
        $today = now('Asia/Kolkata')->toDateString();
        if ($scope === 'upcoming') {
            $q->where(fn ($w) => $w->whereNull('deals.trip_status')->orWhere('deals.trip_status', '<>', 'completed'))
                ->where(fn ($w) => $w->whereNull('deals.travel_date')->orWhere('deals.travel_date', '>=', now('Asia/Kolkata')->subDays(30)->toDateString()));
        } elseif ($scope === 'completed') {
            $q->where('deals.trip_status', 'completed');
        }
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($s, '\\%_').'%';
            $op = Fail::ilike();
            $q->where(fn ($w) => $w->where('deals.deal_no', $op, $like)->orWhere('deals.name', $op, $like)->orWhere('deals.destination', $op, $like)
                ->orWhereIn('deals.customer_id', \App\Models\Customer::query()->select('id')->where('name', $op, $like)));
        }
        $rows = $q->orderByRaw('deals.travel_date NULLS LAST')->orderBy('deals.id')->limit(300)->get()->map(fn (Deal $d) => $this->summary($d))->values();
        $attention = $rows->filter(fn ($r) => $r['progress']['overdue'] > 0 || $r['payment']['overdue'])->count();

        return ['items' => $rows, 'total' => $rows->count(), 'attention' => $attention, 'today' => $today];
    }

    /** All deals of a customer with their payment and trip progress. */
    public function customerTrips(Request $request, string $id)
    {
        $c = CustomerController::find($id, $request->user());

        return DealController::scopeForUser(Deal::query()->with(['customer:id,customer_no,name', 'owner:id,name']), $request->user())
            ->where('deals.customer_id', $c->id)->where('deals.stage', '<>', 'lost')->orderByRaw('deals.travel_date NULLS LAST')->get()
            ->map(fn (Deal $d) => $this->summary($d))->values();
    }

    // ------------------------------------------------------------------ templates (admin)

    public function templates()
    {
        return TripTemplate::with('items')->orderBy('id')->get()->map(fn (TripTemplate $t) => $t->toApi())->values();
    }

    public function updateTemplate(Request $request, string $id)
    {
        $user = $request->user();
        $tpl = ctype_digit($id) ? TripTemplate::find((int) $id) : null;
        if (! $tpl) {
            Fail::notFound('Template not found');
        }
        $d = Fail::validate(Fail::body($request), [
            'name' => 'sometimes|required|string|max:120', 'items' => 'required|array|max:100',
            'items.*.title' => 'required|string|max:200', 'items.*.category' => 'required|in:'.implode(',', TripTask::CATEGORIES),
            'items.*.due_days_before' => 'nullable|integer|min:-365|max:365', 'items.*.critical' => 'sometimes|boolean',
        ]);
        DB::transaction(function () use ($tpl, $d) {
            if (isset($d['name'])) {
                $tpl->name = $d['name'];
                $tpl->save();
            }
            TripTemplateItem::where('template_id', $tpl->id)->delete();
            foreach (array_values($d['items']) as $i => $it) {
                TripTemplateItem::create(['template_id' => $tpl->id, 'position' => $i, 'category' => $it['category'], 'title' => trim($it['title']),
                    'due_days_before' => $it['due_days_before'] ?? null, 'critical' => (bool) ($it['critical'] ?? false)]);
            }
        });
        ActivityLogger::log($user, 'update_trip_template', 'trip_template', $tpl->id, null, ['items' => count($d['items'])]);

        return $tpl->fresh('items')->toApi();
    }
}
