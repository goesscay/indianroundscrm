<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\CrmNote;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Numbers;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DealController extends Controller
{
    private const WITH = ['owner:id,name', 'customer:id,customer_no,name'];

    // ------------------------------------------------------------------ helpers

    public static function scopeForUser($query, User $user)
    {
        if (! $user->hasPermission('deals.view_all')) {
            $query->where(function ($w) use ($user) {
                $w->where('deals.owner_id', $user->id)->orWhere('deals.created_by', $user->id);
            });
        }

        return $query;
    }

    public static function find(string $id, User $user): Deal
    {
        $d = ctype_digit($id) ? self::scopeForUser(Deal::query()->with(self::WITH), $user)->where('deals.id', (int) $id)->first() : null;
        if (! $d) {
            Fail::notFound('Deal not found');
        }

        return $d;
    }

    private function validated(array $b, bool $partial): array
    {
        $req = $partial ? 'sometimes|' : '';

        return Fail::validate($b, [
            'customer_id' => ($partial ? 'sometimes|' : 'required|').'nullable',
            'name' => 'nullable|string|max:200',
            'stage' => $req.'nullable|in:'.implode(',', Deal::STAGES),
            'trip_status' => 'nullable|in:'.implode(',', Deal::TRIP_STATUSES),
            'probability' => 'nullable|integer|min:0|max:100',
            'destination' => 'nullable|string|max:200',
            'travel_date' => 'nullable|string',
            'duration_days' => 'nullable|integer|min:0|max:365',
            'adults' => 'nullable|integer|min:0|max:500',
            'children' => 'nullable|integer|min:0|max:500',
            'selling_amount' => 'nullable|numeric|min:0',
            'supplier_cost' => 'nullable|numeric|min:0',
            'expected_close' => 'nullable|string',
            'lost_reason' => 'nullable|string|max:300',
            'notes' => 'nullable|string',
            'owner_id' => 'nullable',
        ]);
    }

    private function date(mixed $v, string $field): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = substr((string) $v, 0, 10);
        try {
            $c = Carbon::createFromFormat('!Y-m-d', $v);
            if ($c === false || $c->format('Y-m-d') !== $v) {
                throw new \Exception('bad');
            }
        } catch (\Throwable) {
            Fail::unprocessable([$field => 'Invalid date (use YYYY-MM-DD)']);
        }

        return $v;
    }

    private function resolveOwner(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_scalar($v) || ! ctype_digit((string) $v) || ! User::where('id', (int) $v)->where('active', true)->exists()) {
            Fail::unprocessable(['owner_id' => 'User not found or inactive']);
        }

        return (int) $v;
    }

    private function resolveCustomer(mixed $v, User $user): int
    {
        if (! is_scalar($v) || ! ctype_digit((string) $v)) {
            Fail::unprocessable(['customer_id' => 'Customer not found']);
        }
        $c = CustomerController::scopeForUser(Customer::query(), $user)->where('customers.id', (int) $v)->first();
        if (! $c) {
            Fail::unprocessable(['customer_id' => 'Customer not found']);
        }

        return (int) $c->id;
    }

    private function snapshot(Deal $d): array
    {
        return ['deal_no' => $d->deal_no, 'stage' => $d->stage, 'selling_amount' => $d->selling_amount, 'supplier_cost' => $d->supplier_cost,
            'owner_id' => $d->owner_id, 'customer_id' => $d->customer_id];
    }

    /** Apply the validated fields onto the deal, with stage rules (probability default, won/lost bookkeeping). */
    private function apply(Deal $deal, array $d, bool $creating): void
    {
        foreach (['name', 'destination', 'notes', 'lost_reason'] as $f) {
            if (array_key_exists($f, $d)) {
                $v = is_string($d[$f]) ? trim($d[$f]) : $d[$f];
                $deal->{$f} = $v === '' ? null : $v;
            }
        }
        foreach (['duration_days', 'adults', 'children'] as $f) {
            if (array_key_exists($f, $d)) {
                $deal->{$f} = $d[$f] === null ? ($f === 'adults' ? 1 : ($f === 'children' ? 0 : null)) : (int) $d[$f];
            }
        }
        foreach (['selling_amount', 'supplier_cost'] as $f) {
            if (array_key_exists($f, $d)) {
                $deal->{$f} = (float) ($d[$f] ?? 0);
            }
        }
        if (array_key_exists('travel_date', $d)) {
            $deal->travel_date = $this->date($d['travel_date'], 'travel_date');
        }
        if (array_key_exists('expected_close', $d)) {
            $deal->expected_close = $this->date($d['expected_close'], 'expected_close');
        }
        if (array_key_exists('trip_status', $d)) {
            $deal->trip_status = $d['trip_status'];
        }

        $oldStage = $creating ? null : $deal->getOriginal('stage');
        if (array_key_exists('stage', $d) && $d['stage'] !== null) {
            $deal->stage = $d['stage'];
        }
        $deal->stage = $deal->stage ?: 'enquiry';
        $changed = $deal->stage !== $oldStage;

        if (array_key_exists('probability', $d) && $d['probability'] !== null) {
            $deal->probability = (int) $d['probability'];
        } elseif ($changed) {
            $deal->probability = Deal::PROBABILITY[$deal->stage];
        }
        if ($deal->stage === 'won') {
            $deal->probability = 100;
            $deal->closed_at = $deal->closed_at ?: now();
            $deal->trip_status = $deal->trip_status ?: 'confirmed';
            $deal->lost_reason = null;
        } elseif ($deal->stage === 'lost') {
            $deal->probability = 0;
            if (! trim((string) $deal->lost_reason)) {
                Fail::unprocessable(['lost_reason' => 'Tell us why the deal was lost']);
            }
            $deal->closed_at = $deal->closed_at ?: now();
            $deal->trip_status = null;
        } else {
            $deal->closed_at = null;
            $deal->lost_reason = null;
            $deal->trip_status = null;
        }
        if (! $deal->name) {
            $deal->name = trim(($deal->destination ?: 'Trip').' — '.($deal->customer?->name ?? ''), ' —');
        }
    }

    /** Create a deal (also used by lead conversion). */
    public static function createFor(Customer $customer, array $d, User $user, ?string $leadId = null): Deal
    {
        $self = new self();
        $deal = new Deal(['deal_no' => Numbers::next('deal_seq', 'IRD-'), 'customer_id' => $customer->id, 'lead_id' => $leadId,
            'created_by' => $user->id, 'owner_id' => array_key_exists('owner_id', $d) ? $self->resolveOwner($d['owner_id']) : $user->id]);
        $deal->setRelation('customer', $customer);
        $self->apply($deal, $d, true);
        $deal->save();
        ActivityLogger::log($user, 'create_deal', 'deal', $deal->id, null, $self->snapshot($deal));

        return $deal->load(self::WITH);
    }

    public static function validateInput(array $b): array
    {
        return (new self())->validated($b + ['customer_id' => 0], true);
    }

    // ------------------------------------------------------------------ routes

    public function index(Request $request)
    {
        $user = $request->user();
        $q = self::scopeForUser(Deal::query()->with(self::WITH), $user);

        if (($v = (string) $request->query('stage', '')) !== '') {
            $q->where('deals.stage', $v);
        }
        if (($v = (string) $request->query('customer_id', '')) !== '') {
            $q->where('deals.customer_id', ctype_digit($v) ? (int) $v : -1);
        }
        $owner = (string) $request->query('owner', 'all');
        if ($owner === 'me') {
            $q->where('deals.owner_id', $user->id);
        } elseif ($owner === 'unassigned') {
            $q->whereNull('deals.owner_id');
        } elseif ($owner !== '' && $owner !== 'all') {
            $q->where('deals.owner_id', ctype_digit($owner) ? (int) $owner : -1);
        }
        match ((string) $request->query('close', '')) {
            'overdue' => $q->whereNotIn('deals.stage', ['won', 'lost'])->where('deals.expected_close', '<', now()->toDateString()),
            'this_month' => $q->whereBetween('deals.expected_close', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]),
            'next_month' => $q->whereBetween('deals.expected_close', [now()->addMonthNoOverflow()->startOfMonth()->toDateString(), now()->addMonthNoOverflow()->endOfMonth()->toDateString()]),
            default => null,
        };
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($s, '\\%_').'%';
            $op = Fail::ilike();
            $q->where(function ($w) use ($like, $op) {
                $w->where('deals.name', $op, $like)->orWhere('deals.deal_no', $op, $like)->orWhere('deals.destination', $op, $like)
                    ->orWhereIn('deals.customer_id', Customer::query()->select('id')
                        ->where(fn ($c) => $c->where('name', $op, $like)->orWhere('phone', $op, $like)->orWhere('customer_no', $op, $like)));
            });
        }
        $q->orderByDesc('deals.id');

        if (Paging::requested($request)) {
            return Paging::paginate($request, $q, fn (Deal $d) => $d->toApi());
        }
        $limit = max(1, (int) $request->query('limit', 500));

        return $q->limit($limit)->get()->map(fn (Deal $d) => $d->toApi())->values();
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $d = $this->validated(Fail::body($request), false);
        $customer = Customer::findOrFail($this->resolveCustomer($d['customer_id'], $user));

        return self::createFor($customer, $d, $user)->toApi();
    }

    public function show(Request $request, string $id)
    {
        return self::find($id, $request->user())->toApi();
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        $deal = self::find($id, $user);
        $d = $this->validated(Fail::body($request), true);
        $before = $this->snapshot($deal);

        if (array_key_exists('customer_id', $d) && (string) $d['customer_id'] !== (string) $deal->customer_id) {
            $deal->customer_id = $this->resolveCustomer($d['customer_id'], $user);
            $deal->unsetRelation('customer');
            $deal->load('customer:id,customer_no,name');
        }
        if (array_key_exists('owner_id', $d)) {
            $deal->owner_id = $this->resolveOwner($d['owner_id']);
        }
        $this->apply($deal, $d, false);
        $deal->save();
        ActivityLogger::log($user, 'update_deal', 'deal', $deal->id, $before, $this->snapshot($deal));

        return $deal->fresh(self::WITH)->toApi();
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $deal = self::find($id, $user);
        Lead::where('deal_id', $deal->id)->update(['deal_id' => null]);
        Activity::where('deal_id', $deal->id)->update(['deal_id' => null]);
        CrmNote::where('entity_type', 'deal')->where('entity_id', $deal->id)->delete();
        $deal->delete();
        ActivityLogger::log($user, 'delete_deal', 'deal', $id, $this->snapshot($deal), null);

        return ['ok' => true];
    }

    public function options(Request $request)
    {
        return ['stages' => Deal::STAGES, 'trip_statuses' => Deal::TRIP_STATUSES, 'probability' => Deal::PROBABILITY];
    }

    // ------------------------------------------------------------------ notes + activities

    public function notes(Request $request, string $id)
    {
        $deal = self::find($id, $request->user());

        return CrmNote::where('entity_type', 'deal')->where('entity_id', $deal->id)->orderByDesc('id')->get()->map->toArray()->values();
    }

    public function addNote(Request $request, string $id)
    {
        $user = $request->user();
        $deal = self::find($id, $user);
        $d = Fail::validate(Fail::body($request), ['note' => 'required|string|max:5000']);

        return CrmNote::create(['entity_type' => 'deal', 'entity_id' => $deal->id, 'note' => trim($d['note']),
            'created_by_id' => $user->id, 'created_by_name' => $user->name, 'created_at' => now()])->toArray();
    }

    public function activities(Request $request, string $id)
    {
        $deal = self::find($id, $request->user());

        return Activity::query()->with(ActivityController::WITH)
            ->where(fn ($w) => $w->where('deal_id', $deal->id)->when($deal->lead_id, fn ($x) => $x->orWhere('lead_id', $deal->lead_id)))
            ->orderByRaw('completed_at IS NOT NULL')->orderByRaw('due_at NULLS LAST')->limit(200)->get()
            ->map(fn (Activity $a) => $a->toApi())->values();
    }

    /** DELETE /crm-notes/{id}: the author, or anyone who may edit the customer/deal the note belongs to. */
    public function deleteNote(Request $request, string $id)
    {
        $user = $request->user();
        $n = ctype_digit($id) ? CrmNote::find((int) $id) : null;
        if (! $n) {
            Fail::notFound('Note not found');
        }
        // the caller must be able to see the record the note is attached to
        $n->entity_type === 'deal' ? self::find((string) $n->entity_id, $user) : CustomerController::find((string) $n->entity_id, $user);
        $perm = $n->entity_type === 'deal' ? 'deals.edit' : 'customers.edit';
        if ((int) $n->created_by_id !== (int) $user->id && ! $user->hasPermission($perm)) {
            Fail::forbidden('Forbidden');
        }
        $n->delete();

        return ['ok' => true];
    }
}
