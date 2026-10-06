<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\CrmNote;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerTraveller;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Numbers;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    // ------------------------------------------------------------------ helpers

    /** Without customers.view_all a user only sees customers they own or created. */
    public static function scopeForUser($query, User $user)
    {
        if (! $user->hasPermission('customers.view_all')) {
            $query->where(function ($w) use ($user) {
                $w->where('customers.owner_id', $user->id)->orWhere('customers.created_by', $user->id);
            });
        }

        return $query;
    }

    public static function find(string $id, User $user): Customer
    {
        $c = ctype_digit($id) ? self::scopeForUser(Customer::query(), $user)->where('customers.id', (int) $id)->first() : null;
        if (! $c) {
            Fail::notFound('Customer not found');
        }

        return $c;
    }

    private function withAggregates($q)
    {
        return $q->with('owner:id,name')
            ->withCount('deals')
            ->withSum(['deals as open_pipeline' => fn ($d) => $d->whereNotIn('stage', ['won', 'lost'])], 'selling_amount')
            ->withSum(['deals as won_value' => fn ($d) => $d->where('stage', 'won')], 'selling_amount');
    }

    private function validated(array $b, bool $partial): array
    {
        $req = $partial ? 'sometimes|' : '';

        return Fail::validate($b, [
            'name' => ($partial ? 'sometimes|required' : 'required').'|string|max:200',
            'type' => $req.'nullable|in:'.implode(',', Customer::TYPES),
            'status' => $req.'nullable|in:'.implode(',', Customer::STATUSES),
            'company' => 'nullable|string|max:200',
            'phone' => 'nullable|string|max:40',
            'whatsapp' => 'nullable|string|max:40',
            'email' => 'nullable|string|max:200',
            'gstin' => 'nullable|string|max:20',
            'address_line' => 'nullable|string|max:300',
            'city' => 'nullable|string|max:120',
            'state' => 'nullable|string|max:120',
            'pincode' => 'nullable|string|max:12',
            'country' => 'nullable|string|max:120',
            'source' => 'nullable|string|max:120',
            'notes' => 'nullable|string',
            'owner_id' => 'nullable',
            'contacts' => 'sometimes|nullable|array|max:30',
            'travellers' => 'sometimes|nullable|array|max:60',
            'allow_duplicate' => 'sometimes|boolean',
        ]);
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

    /** Existing customers matching a phone (normalised) or e-mail; scoped to what the caller can see. */
    public static function matches(?string $phone, ?string $email, User $user, ?int $exceptId = null): array
    {
        $pn = Numbers::phone($phone);
        $en = $email ? mb_strtolower(trim($email)) : null;
        if (! $pn && ! $en) {
            return [];
        }
        $q = Customer::query()->where(function ($w) use ($pn, $en) {
            if ($pn) {
                $w->orWhere('phone_norm', $pn);
            }
            if ($en) {
                $w->orWhere('email_norm', $en);
            }
        });
        if ($exceptId) {
            $q->where('id', '!=', $exceptId);
        }

        return $q->limit(5)->get()->map(function (Customer $c) use ($user) {
            $b = $c->brief();
            $b['phone'] = $c->phone;
            $b['email'] = $c->email;
            $b['visible'] = $user->hasPermission('customers.view_all') || (int) $c->owner_id === (int) $user->id || (int) $c->created_by === (int) $user->id;

            return $b;
        })->all();
    }

    private function syncContacts(Customer $c, array $rows): void
    {
        $c->contacts()->delete();
        $out = [];
        foreach (array_values($rows) as $i => $r) {
            $name = trim((string) ($r['name'] ?? ''));
            if (! is_array($r) || $name === '') {
                continue;
            }
            $out[] = [
                'customer_id' => $c->id, 'name' => mb_substr($name, 0, 200),
                'designation' => $this->s($r['designation'] ?? null, 120), 'phone' => $this->s($r['phone'] ?? null, 40),
                'email' => $this->s($r['email'] ?? null, 200), 'is_primary' => (bool) ($r['is_primary'] ?? false), 'position' => $i,
            ];
        }
        if ($out) {
            CustomerContact::insert($out);
        }
    }

    private function syncTravellers(Customer $c, array $rows): void
    {
        // A masked passport number (unchanged by a viewer who cannot see it) keeps the stored value.
        $existing = $c->travellers()->get()->keyBy(fn ($t) => (string) $t->id);
        $c->travellers()->delete();
        foreach (array_values($rows) as $i => $r) {
            $name = is_array($r) ? trim((string) ($r['name'] ?? '')) : '';
            if ($name === '') {
                continue;
            }
            $passport = $this->s($r['passport_no'] ?? null, 40);
            $prev = isset($r['id']) ? $existing->get((string) $r['id']) : null;
            if ($prev && (! empty($r['passport_masked']) || $passport === null && ! array_key_exists('passport_no', $r))) {
                $passport = $prev->passport_no;
            }
            $t = new CustomerTraveller([
                'customer_id' => $c->id, 'name' => mb_substr($name, 0, 200),
                'relation' => $this->s($r['relation'] ?? null, 60), 'gender' => $this->s($r['gender'] ?? null, 20),
                'dob' => $this->d($r['dob'] ?? null), 'nationality' => $this->s($r['nationality'] ?? null, 80),
                'passport_no' => $passport, 'passport_expiry' => $this->d($r['passport_expiry'] ?? null),
                'preferences' => $this->s($r['preferences'] ?? null, 1000), 'position' => $i,
            ]);
            $t->save();
        }
    }

    private function s(mixed $v, int $max): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function d(mixed $v): ?string
    {
        $v = is_scalar($v) ? substr(trim((string) $v), 0, 10) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    private function fill(Customer $c, array $d): void
    {
        foreach (['name', 'type', 'status', 'company', 'phone', 'whatsapp', 'email', 'gstin', 'address_line', 'city', 'state', 'pincode', 'country', 'source', 'notes'] as $f) {
            if (array_key_exists($f, $d)) {
                $v = is_string($d[$f]) ? trim($d[$f]) : $d[$f];
                $c->{$f} = ($v === '' || $v === null) && ! in_array($f, ['name', 'type', 'status'], true) ? null : $v;
            }
        }
        if (isset($d['gstin']) && $d['gstin'] !== null) {
            $c->gstin = strtoupper(trim((string) $d['gstin'])) ?: null;
        }
        $c->type = $c->type ?: 'individual';
        $c->status = $c->status ?: 'active';
    }

    private function detail(Customer $c, User $user): array
    {
        $c = $this->withAggregates(Customer::query())->where('customers.id', $c->id)->first();
        $show = $user->hasPermission('customers.view_passports');
        $a = $c->toApi();
        $a['contacts'] = $c->contacts()->get()->map(fn ($x) => collect($x->toArray())->except('customer_id')->all())->all();
        $a['travellers'] = $c->travellers()->get()->map(fn (CustomerTraveller $t) => $t->toApi($show))->all();
        $a['deals'] = Deal::query()->where('customer_id', $c->id)->with(['owner:id,name', 'customer:id,customer_no,name'])
            ->orderByDesc('id')->get()->map(fn (Deal $d) => $d->toApi())->all();
        $inv = \App\Models\Invoice::query()->where('customer_id', $c->id)->where('status', 'issued')->get(['total', 'credited_total', 'paid_total']);
        $a['invoiced'] = round((float) $inv->sum(fn ($i) => $i->total - $i->credited_total), 2);
        $a['outstanding'] = round((float) $inv->sum(fn ($i) => max(0, $i->total - $i->credited_total - $i->paid_total)), 2);
        $a['advances'] = round((float) \App\Models\Receipt::where('customer_id', $c->id)->where('kind', 'receipt')->where('status', 'valid')->whereNull('invoice_id')->sum('amount'), 2);
        $a['leads'] = Lead::query()->where('customer_id', $c->id)->orderByDesc('id')
            ->get(['lead_id', 'destination', 'travel_date', 'lead_status', 'converted_at'])->map(fn ($l) => $l->toArray())->all();

        return $a;
    }

    // ------------------------------------------------------------------ routes

    public function index(Request $request)
    {
        $user = $request->user();
        $q = $this->withAggregates(self::scopeForUser(Customer::query(), $user));

        if (($v = (string) $request->query('type', '')) !== '') {
            $q->where('customers.type', $v);
        }
        if (($v = (string) $request->query('status', '')) !== '') {
            $q->where('customers.status', $v);
        }
        $owner = (string) $request->query('owner', 'all');
        if ($owner === 'me') {
            $q->where('customers.owner_id', $user->id);
        } elseif ($owner === 'unassigned') {
            $q->whereNull('customers.owner_id');
        } elseif ($owner !== '' && $owner !== 'all') {
            $q->where('customers.owner_id', ctype_digit($owner) ? (int) $owner : -1);
        }
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($s, '\\%_').'%';
            $op = Fail::ilike();
            $digits = preg_replace('/\D/', '', $s);
            $q->where(function ($w) use ($like, $op, $digits) {
                $w->where('customers.name', $op, $like)->orWhere('customers.customer_no', $op, $like)
                    ->orWhere('customers.company', $op, $like)->orWhere('customers.email', $op, $like)
                    ->orWhere('customers.phone', $op, $like)->orWhere('customers.city', $op, $like);
                if (strlen($digits) >= 4) {
                    $w->orWhere('customers.phone_norm', 'like', '%'.$digits.'%');
                }
            });
        }
        $q->orderByDesc('customers.id');

        if (Paging::requested($request)) {
            return Paging::paginate($request, $q, fn (Customer $c) => $c->toApi());
        }
        $limit = max(1, (int) $request->query('limit', 500));

        return $q->limit($limit)->get()->map(fn (Customer $c) => $c->toApi())->values();
    }

    public function options(Request $request)
    {
        $user = $request->user();
        $owners = User::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'role'])
            ->filter(fn (User $u) => $u->hasPermission('leads.assignable') || $u->role === 'admin' || (int) $u->id === (int) $user->id)
            ->map(fn ($u) => ['id' => (string) $u->id, 'name' => $u->name])->values();

        return [
            'owners' => $owners,
            'types' => Customer::TYPES,
            'sources' => Lead::query()->whereNotNull('lead_source')->where('lead_source', '!=', '')->distinct()->orderBy('lead_source')->pluck('lead_source'),
        ];
    }

    public function duplicateCheck(Request $request)
    {
        return ['matches' => self::matches((string) $request->query('phone', ''), (string) $request->query('email', ''), $request->user(),
            ctype_digit((string) $request->query('except', '')) ? (int) $request->query('except') : null)];
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $d = $this->validated(Fail::body($request), false);

        if (empty($d['allow_duplicate'])) {
            $dups = self::matches($d['phone'] ?? null, $d['email'] ?? null, $user);
            if ($dups) {
                Fail::http(409, "A customer with this phone or e-mail already exists: {$dups[0]['customer_no']} {$dups[0]['name']}.");
            }
        }

        $c = DB::transaction(function () use ($d, $user) {
            $c = new Customer(['customer_no' => Numbers::next('customer_seq', 'IRC-'), 'created_by' => $user->id,
                'owner_id' => array_key_exists('owner_id', $d) ? $this->resolveOwner($d['owner_id']) : $user->id]);
            $this->fill($c, $d);
            $c->save();
            $this->syncContacts($c, (array) ($d['contacts'] ?? []));
            $this->syncTravellers($c, (array) ($d['travellers'] ?? []));

            return $c;
        });
        ActivityLogger::log($user, 'create_customer', 'customer', $c->id, null, ['customer_no' => $c->customer_no, 'name' => $c->name]);

        return $this->detail($c, $user);
    }

    public function show(Request $request, string $id)
    {
        return $this->detail(self::find($id, $request->user()), $request->user());
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        $c = self::find($id, $user);
        $d = $this->validated(Fail::body($request), true);
        $before = ['name' => $c->name, 'type' => $c->type, 'status' => $c->status, 'owner_id' => $c->owner_id];

        DB::transaction(function () use ($c, $d) {
            $this->fill($c, $d);
            if (array_key_exists('owner_id', $d)) {
                $c->owner_id = $this->resolveOwner($d['owner_id']);
            }
            $c->save();
            if (array_key_exists('contacts', $d)) {
                $this->syncContacts($c, (array) $d['contacts']);
            }
            if (array_key_exists('travellers', $d)) {
                $this->syncTravellers($c, (array) $d['travellers']);
            }
        });
        ActivityLogger::log($user, 'update_customer', 'customer', $c->id, $before,
            ['name' => $c->name, 'type' => $c->type, 'status' => $c->status, 'owner_id' => $c->owner_id]);

        return $this->detail($c->fresh(), $user);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $c = self::find($id, $user);
        if (Deal::where('customer_id', $c->id)->exists()) {
            Fail::http(409, 'This customer has deals. Delete or move the deals first.');
        }
        DB::transaction(function () use ($c) {
            Lead::where('customer_id', $c->id)->update(['customer_id' => null, 'deal_id' => null, 'converted_at' => null]);
            Activity::where('customer_id', $c->id)->update(['customer_id' => null]);
            CrmNote::where('entity_type', 'customer')->where('entity_id', $c->id)->delete();
            $c->delete();
        });
        ActivityLogger::log($user, 'delete_customer', 'customer', $id, ['customer_no' => $c->customer_no, 'name' => $c->name], null);

        return ['ok' => true];
    }

    // ------------------------------------------------------------------ notes + activities

    public function notes(Request $request, string $id)
    {
        $c = self::find($id, $request->user());

        return CrmNote::where('entity_type', 'customer')->where('entity_id', $c->id)->orderByDesc('id')->get()->map->toArray()->values();
    }

    public function addNote(Request $request, string $id)
    {
        $user = $request->user();
        $c = self::find($id, $user);
        $d = Fail::validate(Fail::body($request), ['note' => 'required|string|max:5000']);
        $n = CrmNote::create(['entity_type' => 'customer', 'entity_id' => $c->id, 'note' => trim($d['note']),
            'created_by_id' => $user->id, 'created_by_name' => $user->name, 'created_at' => now()]);

        return $n->toArray();
    }

    public function activities(Request $request, string $id)
    {
        $user = $request->user();
        $c = self::find($id, $user);
        $leadIds = Lead::where('customer_id', $c->id)->pluck('lead_id');

        return Activity::query()->with(ActivityController::WITH)
            ->where(fn ($w) => $w->where('customer_id', $c->id)->orWhereIn('lead_id', $leadIds))
            ->orderByRaw('completed_at IS NOT NULL')->orderByRaw('due_at NULLS LAST')->limit(200)->get()
            ->map(fn (Activity $a) => $a->toApi())->values();
    }
}
