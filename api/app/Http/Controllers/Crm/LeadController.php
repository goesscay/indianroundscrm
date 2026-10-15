<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Allocation;
use App\Models\ContactEvent;
use App\Models\DuplicateLog;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\RsqRequest;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Ids;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LeadController extends Controller
{
    /** Fields a sales consultant may change on their own leads. */
    private const SALES_ALLOWED = [
        'customer_name', 'phone', 'email', 'travel_date', 'duration_days', 'destination',
        'hotel_category', 'meal_plan', 'package_type', 'budget',
        'adults', 'children', 'child_ages', 'rooms', 'room_type',
        'departure_from', 'special_requests', 'lead_status',
        'remarks', 'follow_up_date', 'booking_status',
        'quote_type', 'package_selected',
        'quote_sent_to_customer', 'quote_sent_date',
        'stay_cities', 'passport_available', 'passport_reason',
    ];

    private const COMPLETED_STATUSES = [
        'Quote Sent to Customer', 'Booked', 'Payment Received', 'Travel Completed', 'Closed', 'Cancelled',
    ];

    // ------------------------------------------------------------------ helpers

    /** Scope for permission-based visibility: without leads.view_all a user only sees leads they own. */
    public static function scopeForUser($query, $user)
    {
        if (! $user->hasPermission('leads.view_all')) {
            $query->where('staff_id', $user->id);
        }
        return $query;
    }

    private function findLead(string $leadId): Lead
    {
        $lead = Lead::where('lead_id', $leadId)->first();
        if (! $lead) {
            Fail::notFound('Lead not found');
        }
        return $lead;
    }

    private function assertCanSee(Lead $lead, $user): void
    {
        if (! $user->hasPermission('leads.view_all') && (int) $lead->staff_id !== (int) $user->id) {
            Fail::forbidden('Forbidden');
        }
    }

    /** IR + 2-letter consultant code + MM + 3-digit running number (per consultant per month). */
    public static function generateLeadId(string $consultantCode = ''): string
    {
        $now = Carbon::now('UTC');
        $month = $now->format('m');
        $cc = strtoupper(substr($consultantCode ?: 'XX', 0, 2));
        $key = "lead_seq_{$cc}_{$now->year}_{$month}";
        // Atomic increment with row locking inside Postgres (INSERT .. ON CONFLICT DO UPDATE)
        $row = DB::selectOne(
            'INSERT INTO counters ("key", seq) VALUES (?, 1)
             ON CONFLICT ("key") DO UPDATE SET seq = counters.seq + 1 RETURNING seq',
            [$key]
        );
        return sprintf('IR%s%s%03d', $cc, $month, $row->seq);
    }

    /** Auto-derive trip_end_date / duration_label / stay_summary. */
    private function computeTripFields(array $data): array
    {
        $days = $data['duration_days'] ?? null;
        $travel = trim((string) ($data['travel_date'] ?? ''));
        if ($days && (int) $days > 0 && $travel !== '') {
            try {
                $d = (int) $days;
                $start = Carbon::createFromFormat('!Y-m-d', substr($travel, 0, 10), 'UTC');
                if ($start === false || $start->format('Y-m-d') !== substr($travel, 0, 10)) {
                    throw new \Exception('bad date');
                }
                $data['trip_end_date'] = $start->addDays(max(0, $d - 1))->format('Y-m-d');
                $data['duration_label'] = max(0, $d - 1).'N / '.$d.'D';
            } catch (\Throwable) {
            }
        }
        $cities = $data['stay_cities'] ?? [];
        if ($cities) {
            $parts = [];
            foreach ($cities as $c) {
                $name = trim((string) ($c['city'] ?? ''));
                $nights = $c['nights'] ?? null;
                if ($name === '') {
                    continue;
                }
                $parts[] = $nights ? "{$name} {$nights}N" : $name;
            }
            $data['stay_summary'] = implode(' + ', $parts);
        }
        return $data;
    }

    private static function normalizePhone(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        return $digits;
    }

    private function cleanCities($cities): array
    {
        $out = [];
        foreach ((array) $cities as $c) {
            if (! is_array($c)) {
                continue;
            }
            $out[] = ['city' => (string) ($c['city'] ?? ''), 'nights' => isset($c['nights']) && $c['nights'] !== '' ? (int) $c['nights'] : null];
        }
        return $out;
    }

    // ------------------------------------------------------------------ routes

    public function duplicateCheck(Request $request)
    {
        $user = $request->user();
        $phone = trim((string) $request->query('phone', ''));
        $email = trim((string) $request->query('email', ''));
        if ($phone === '' && $email === '') {
            return ['matches' => []];
        }
        $q = Lead::query()->where(function ($w) use ($phone, $email) {
            if ($phone !== '') {
                $w->orWhere('phone', $phone);
            }
            if ($email !== '') {
                $w->orWhereRaw('LOWER(email) = ?', [mb_strtolower($email)]);
            }
        });
        if (! $user->hasPermission('leads.view_all')) {
            $q->where('staff_id', $user->id);
        }
        $docs = $q->orderByDesc('created_at')->limit(10)->get();
        return ['matches' => $docs->map(fn ($d) => [
            'lead_id' => $d->lead_id,
            'customer_name' => $d->customer_name,
            'phone' => $d->phone,
            'email' => $d->email,
            'destination' => $d->destination,
            'created_at' => $d->created_at?->utc()->toIso8601String(),
            'lead_status' => $d->lead_status,
            'staff_name' => $d->staff_name,
        ])->values()];
    }

    /** Trip (travel) dates of the leads the caller can see, for the Activities calendar. `month=YYYY-MM` or `from`/`to` (YYYY-MM-DD, `to` exclusive). Cancelled leads are left out. */
    public function calendar(Request $request)
    {
        $month = (string) $request->query('month', '');
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        $leads = self::scopeForUser(Lead::query(), $request->user())->where('lead_status', '<>', 'Cancelled')->whereNotNull('travel_date')->limit(5000)->get();
        $events = [];
        foreach ($leads as $l) {
            $d = substr((string) ($l->travel_date ?? ''), 0, 10);
            if ($d === '' || ($month !== '' && ! str_starts_with($d, $month)) || ($from !== '' && $d < $from) || ($to !== '' && $d >= $to)) {
                continue;
            }
            $events[] = ['date' => $d, 'kind' => 'travel', 'lead_id' => $l->lead_id, 'customer_name' => $l->customer_name,
                'destination' => $l->destination, 'lead_status' => $l->lead_status];
        }
        usort($events, fn ($a, $b) => strcmp($a['date'], $b['date']));
        return $events;
    }

    /** Every list filter except the status / stage (so the stage tabs can count against the same filters). */
    private function filtered(Request $request, $user)
    {
        $q = self::scopeForUser(Lead::query(), $user);
        $staffId = (string) $request->query('staff_id', '');
        if ($staffId !== '' && $user->hasPermission('leads.view_all')) {
            $q->where('staff_id', ctype_digit($staffId) ? (int) $staffId : -1);
        }
        if (($v = (string) $request->query('destination', '')) !== '') {
            $q->whereIn('destination', array_values(array_filter(explode('|', $v), fn ($x) => $x !== '')));   // one, or several joined with |
        }
        if (($v = (string) $request->query('booking_status', '')) !== '') {
            $q->where('booking_status', $v);
        }
        if (($v = (string) $request->query('lead_source', '')) !== '') {
            $q->where('lead_source', $v);
        }
        $this->applyDateRange($q, $request);
        if (($s = (string) $request->query('q', '')) !== '') {
            $like = '%'.addcslashes($s, '\\%_').'%';
            $q->where(function ($w) use ($like) {
                foreach (['lead_id', 'customer_name', 'phone', 'destination', 'email'] as $col) {
                    $w->orWhere($col, \App\Crm\Fail::ilike(), $like);
                }
            });
        }

        return $q;
    }

    public function index(Request $request)
    {
        $q = $this->filtered($request, $request->user())->with('stayCities');
        if (($v = (string) $request->query('status', '')) !== '') {
            $q->where('lead_status', $v);
        }
        if (($stage = (string) $request->query('stage', '')) !== '' && $stage !== 'all') {
            $q->whereIn('lead_status', \App\Support\LeadStages::statuses($stage) ?? ['__none__']);
        }
        $q->orderByDesc('created_at')->orderByDesc('id');
        if (\App\Support\Paging::requested($request)) {
            return \App\Support\Paging::paginate($request, $q, fn (Lead $l) => $l->toApi());
        }
        $limit = max(1, (int) $request->query('limit', 500));
        return $q->limit($limit)->get()
            ->map(fn (Lead $l) => $l->toApi())->values();
    }

    /** GET /leads/stage-counts: how many leads sit in each stage (and in each status) under the current search / filters. */
    public function stageCounts(Request $request)
    {
        $by = $this->filtered($request, $request->user())->select('lead_status', DB::raw('count(*) as n'))->groupBy('lead_status')->pluck('n', 'lead_status');
        $stages = ['all' => (int) $by->sum()];
        foreach (\App\Support\LeadStages::MAP as $stage => $statuses) {
            $stages[$stage] = (int) collect($statuses)->sum(fn ($s) => $by[$s] ?? 0);
        }

        return ['stages' => $stages, 'statuses' => $by->map(fn ($n) => (int) $n)->all()];
    }

    /**
     * date_field=created|travel with date_from / date_to (YYYY-MM-DD, both inclusive, either may be left out).
     * "created" counts calendar days in India (IST); "travel" compares the travel date as written on the lead.
     */
    private function applyDateRange($q, Request $request): void
    {
        $ok = fn (string $d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
        $from = (string) $request->query('date_from', '');
        $to = (string) $request->query('date_to', '');
        $from = $ok($from) ? $from : '';
        $to = $ok($to) ? $to : '';
        if ($from === '' && $to === '') {
            return;
        }
        if ((string) $request->query('date_field', 'created') === 'travel') {
            if ($from !== '') {
                $q->whereRaw('substr(travel_date, 1, 10) >= ?', [$from]);
            }
            if ($to !== '') {
                $q->whereRaw("substr(travel_date, 1, 10) <= ? and travel_date <> ''", [$to]);
            }

            return;
        }
        if ($from !== '') {
            $q->where('created_at', '>=', \Illuminate\Support\Carbon::parse($from, 'Asia/Kolkata')->startOfDay()->utc());
        }
        if ($to !== '') {
            $q->where('created_at', '<', \Illuminate\Support\Carbon::parse($to, 'Asia/Kolkata')->addDay()->startOfDay()->utc());
        }
    }

    /** Distinct values for the leads list filters, scoped to the leads this user can see. */
    public function filterOptions(Request $request)
    {
        $user = $request->user();
        $base = fn () => self::scopeForUser(Lead::query(), $user);
        $distinct = fn (string $col) => $base()->whereNotNull($col)->where($col, '!=', '')
            ->distinct()->orderBy($col)->pluck($col)->values();
        $owners = $user->hasPermission('leads.view_all')
            ? $base()->whereNotNull('staff_id')->select('staff_id', 'staff_name')->distinct()
                ->orderBy('staff_name')->get()
                ->map(fn ($r) => ['id' => (string) $r->staff_id, 'name' => $r->staff_name])->values()
            : collect();

        return [
            'sources' => $distinct('lead_source'),
            'destinations' => $distinct('destination'),
            'owners' => $owners,
        ];
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $b = Fail::body($request);
        $errors = [];

        $d = Fail::validate($b, [
            'customer_name' => 'required|string',
            'phone' => 'required|string',
            'destination' => 'required|string',
            'email' => 'nullable|string',
            'lead_date' => 'nullable|string',
            'travel_date' => 'nullable|string',
            'duration_days' => 'nullable|integer',
            'hotel_category' => 'nullable|string',
            'meal_plan' => 'nullable|string',
            'package_type' => 'nullable|string',
            'budget' => 'nullable|string',
            'adults' => 'nullable|integer',
            'children' => 'nullable|integer',
            'child_ages' => 'nullable|string',
            'rooms' => 'nullable|integer',
            'room_type' => 'nullable|string',
            'departure_from' => 'nullable|string',
            'special_requests' => 'nullable|string',
            'lead_source' => 'nullable|string',
            'stay_cities' => 'nullable|array',
            'stay_cities.*.city' => 'required|string',
            'stay_cities.*.nights' => 'nullable|integer',
            'passport_available' => 'nullable|boolean',
            'passport_reason' => 'nullable|string',
            'owner_id' => 'nullable',
        ]);

        // phone validator (Indian mobile)
        $digits = self::normalizePhone($d['phone']);
        if (strlen($digits) !== 10) {
            $errors['phone'] = 'Value error, Please enter a valid 10-digit mobile number.';
        } elseif (! str_contains('6789', $digits[0])) {
            $errors['phone'] = 'Value error, Invalid Indian mobile number (must start with 6/7/8/9).';
        }
        $children = (int) ($d['children'] ?? 0);
        if ($children < 0 || $children > 10) {
            $errors['children'] = 'Value error, Number of children must be between 0 and 10';
        }
        $childAges = '';
        if ($children > 0 && ! isset($errors['children'])) {
            $raw = trim((string) ($d['child_ages'] ?? ''));
            if ($raw === '') {
                $errors['child_ages'] = "Value error, Child ages required — expected {$children} age(s)";
            } else {
                $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($p) => $p !== ''));
                if (count($parts) !== $children) {
                    $errors['child_ages'] = "Value error, Expected {$children} child age(s), got ".count($parts);
                } else {
                    foreach ($parts as $i => $p) {
                        if (! preg_match('/^-?\d+$/', $p)) {
                            $errors['child_ages'] = 'Value error, Child '.($i + 1).' age must be a whole number';
                            break;
                        }
                        if ((int) $p < 0 || (int) $p > 12) {
                            $errors['child_ages'] = 'Value error, Child '.($i + 1).' age must be between 0 and 12';
                            break;
                        }
                    }
                    $childAges = $raw;
                }
            }
        }
        if ($errors) {
            Fail::unprocessable($errors);
        }
        $phone = $digits;

        // Duplicate block - only admin can override with ?force=true
        $force = filter_var($request->query('force', false), FILTER_VALIDATE_BOOLEAN);
        $existing = Lead::where('phone', $phone)->orderBy('id')->first();
        if ($existing && ! ($force && $user->hasPermission('leads.override_duplicate'))) {
            Fail::http(409, [
                'message' => 'Lead already exists for this mobile number.',
                'existing' => [
                    'lead_id' => $existing->lead_id,
                    'customer_name' => $existing->customer_name,
                    'staff_name' => $existing->staff_name,
                    'destination' => $existing->destination,
                    'lead_status' => $existing->lead_status,
                    'follow_up_date' => $existing->follow_up_date,
                ],
            ]);
        }
        if ($existing && $force && $user->hasPermission('leads.override_duplicate')) {
            DuplicateLog::create(['phone' => $phone, 'original_lead_id' => $existing->lead_id,
                'created_by' => $user->email, 'timestamp' => now()]);
        }

        $owner = $this->resolveOwner($d['owner_id'] ?? null, $d['destination'], $user);
        $code = strtoupper(substr($owner->consultant_code ?: ($owner->staff_code ?: 'XX'), 0, 2));

        $cities = $this->cleanCities($d['stay_cities'] ?? []);
        $data = $this->computeTripFields([
            'duration_days' => $d['duration_days'] ?? null,
            'travel_date' => $d['travel_date'] ?? '',
            'stay_cities' => $cities,
        ]);

        $lead = DB::transaction(function () use ($d, $phone, $children, $childAges, $owner, $user, $code, $data, $cities) {
            $leadId = self::generateLeadId($code);
            $lead = Lead::create([
                'lead_id' => $leadId,
                'customer_name' => $d['customer_name'],
                'phone' => $phone,
                'email' => $d['email'] ?? '',
                'lead_date' => ($d['lead_date'] ?? '') ?: Fail::nowIso(),
                'travel_date' => $d['travel_date'] ?? '',
                'duration_days' => $d['duration_days'] ?? null,
                'trip_end_date' => $data['trip_end_date'] ?? null,
                'duration_label' => $data['duration_label'] ?? null,
                'stay_summary' => $data['stay_summary'] ?? null,
                'destination' => $d['destination'],
                'hotel_category' => $d['hotel_category'] ?? '',
                'meal_plan' => $d['meal_plan'] ?? '',
                'package_type' => $d['package_type'] ?? '',
                'budget' => $d['budget'] ?? '',
                'adults' => $d['adults'] ?? 1,
                'children' => $children,
                'child_ages' => $childAges,
                'rooms' => $d['rooms'] ?? 1,
                'room_type' => $d['room_type'] ?? '',
                'departure_from' => $d['departure_from'] ?? '',
                'special_requests' => $d['special_requests'] ?? '',
                'lead_source' => $d['lead_source'] ?? '',
                'passport_available' => $d['passport_available'] ?? null,
                'passport_reason' => $d['passport_reason'] ?? '',
                'staff_id' => $owner->id,
                'staff_name' => $owner->name ?? '',
                'staff_code' => $owner->consultant_code ?: ($owner->staff_code ?: ''),
                'lead_status' => 'New Lead',
                'mail_status' => 'Not Sent',
                'updated_by' => $user->email,
            ]);
            $lead->replaceStayCities($cities);
            return $lead;
        });

        ActivityLogger::log($user, 'create_lead', 'lead', $lead->lead_id, null,
            ['customer' => $lead->customer_name, 'destination' => $lead->destination]);
        return $lead->fresh('stayCities')->toApi();
    }

    /**
     * Who owns a new lead. An explicit owner must be an active consultant; only users who may reassign leads (Operations, Admin)
     * can pick someone else. Without one: a sales consultant owns what they create (so it appears in their list). "auto", or Operations / Admin,
     * get the destination's allocated consultant, else themselves.
     */
    private function resolveOwner(mixed $ownerId, string $destination, User $user): User
    {
        if ($ownerId !== null && $ownerId !== '' && $ownerId !== 'auto') {
            $owner = ctype_digit((string) $ownerId) ? User::find((int) $ownerId) : null;
            if (! $owner || ! $owner->active || $owner->account_status !== 'active' || ! $owner->hasPermission('leads.assignable')) {
                Fail::unprocessable(['owner_id' => 'Choose an active sales consultant as the lead owner']);
            }
            if ((int) $owner->id !== (int) $user->id && ! $user->hasPermission('leads.reassign')) {
                Fail::forbidden('You can only create leads in your own name');
            }

            return $owner;
        }
        if ($ownerId !== 'auto' && ! $user->hasPermission('leads.reassign') && $user->hasPermission('leads.assignable')) {
            return $user;
        }
        $alloc = Allocation::where('destination', $destination)->first();
        $consultant = $alloc ? User::find($alloc->consultant_id) : null;

        return $consultant ?: $user;
    }

    public function show(Request $request, string $leadId)
    {
        $lead = $this->findLead($leadId);
        $this->assertCanSee($lead, $request->user());
        return $lead->toApi();
    }

    public function update(Request $request, string $leadId)
    {
        $user = $request->user();
        $lead = $this->findLead($leadId);
        $this->assertCanSee($lead, $user);

        $b = Fail::body($request);
        $d = Fail::validate($b, [
            'customer_name' => 'nullable|string', 'phone' => 'nullable|string', 'email' => 'nullable|string',
            'travel_date' => 'nullable|string', 'duration_days' => 'nullable|integer',
            'destination' => 'nullable|string', 'hotel_category' => 'nullable|string',
            'meal_plan' => 'nullable|string', 'package_type' => 'nullable|string', 'budget' => 'nullable|string',
            'adults' => 'nullable|integer', 'children' => 'nullable|integer', 'child_ages' => 'nullable|string',
            'rooms' => 'nullable|integer', 'room_type' => 'nullable|string', 'departure_from' => 'nullable|string',
            'special_requests' => 'nullable|string', 'stay_cities' => 'nullable|array',
            'stay_cities.*.city' => 'required|string', 'stay_cities.*.nights' => 'nullable|integer',
            'passport_available' => 'nullable|boolean', 'passport_reason' => 'nullable|string',
            'lead_status' => 'nullable|string', 'remarks' => 'nullable|string',
            'supplier_quote_amount' => 'nullable|numeric', 'selling_amount' => 'nullable|numeric',
            'quote_received' => 'nullable|boolean', 'quote_received_date' => 'nullable|string',
            'quote_sent_to_customer' => 'nullable|boolean', 'quote_sent_date' => 'nullable|string',
            'booking_status' => 'nullable|string', 'booking_amount_received' => 'nullable|numeric',
            'follow_up_date' => 'nullable|string', 'payment_status' => 'nullable|string',
            'quote_type' => 'nullable|string', 'package_selected' => 'nullable|string',
            'rsq_status' => 'nullable|string', 'lead_source' => 'nullable|string',
        ]);

        // exclude_unset + drop None
        $updates = array_filter($d, fn ($v) => $v !== null);
        if (isset($updates['phone'])) {
            $updates['phone'] = self::normalizePhone((string) $updates['phone']);
            if ($updates['phone'] !== self::normalizePhone((string) $lead->phone)) {
                $other = Lead::where('phone', $updates['phone'])->where('lead_id', '!=', $lead->lead_id)->first();
                if ($other) {
                    Fail::http(409, "Another lead ({$other->lead_id} — {$other->customer_name}) already uses this mobile number.");
                }
            }
        }
        if (! $user->hasPermission('leads.edit')) {
            $updates = array_intersect_key($updates, array_flip(self::SALES_ALLOWED));
        }
        if (array_key_exists('stay_cities', $updates)) {
            $updates['stay_cities'] = $this->cleanCities($updates['stay_cities']);
        }
        if (array_intersect(['travel_date', 'duration_days', 'stay_cities'], array_keys($updates))) {
            $merged = [
                'travel_date' => $updates['travel_date'] ?? $lead->travel_date,
                'duration_days' => $updates['duration_days'] ?? $lead->duration_days,
                'stay_cities' => $updates['stay_cities'] ?? $lead->stayCitiesArray(),
            ];
            $derived = $this->computeTripFields($merged);
            foreach (['trip_end_date', 'duration_label', 'stay_summary'] as $k) {
                if (array_key_exists($k, $derived)) {
                    $updates[$k] = $derived[$k];
                }
            }
        }

        $logUpdates = $updates;
        $cities = null;
        if (array_key_exists('stay_cities', $updates)) {
            $cities = $updates['stay_cities'];
            unset($updates['stay_cities']);
        }
        $updates['updated_by'] = $user->email;
        $lead->fill($updates);
        $lead->save();
        if ($cities !== null) {
            $lead->replaceStayCities($cities);
        }

        // RSQ lane -> completed when lead reaches a terminal customer-facing status
        if (in_array($updates['lead_status'] ?? null, self::COMPLETED_STATUSES, true)) {
            $rsq = RsqRequest::where('lead_id', $leadId)->first();
            if ($rsq && $rsq->status !== 'completed') {
                RsqRequest::upsertFor($leadId, ['status' => 'completed', 'completed_at' => now()]);
                $lead->rsq_status = 'completed';
                $lead->save();
            }
        }
        $logUpdates['updated_at'] = Fail::nowIso();
        $logUpdates['updated_by'] = $user->email;
        ActivityLogger::log($user, 'update_lead', 'lead', $leadId, null, $logUpdates);
        return $lead->fresh('stayCities')->toApi();
    }

    public function destroy(Request $request, string $leadId)
    {
        $lead = Lead::where('lead_id', $leadId)->first();
        if (! $lead) {
            Fail::notFound('Not found');
        }
        $lead->delete();
        ActivityLogger::log($request->user(), 'delete_lead', 'lead', $leadId);
        return ['success' => true];
    }

    // ---------------------------------------------------------------- reassign

    public function reassign(Request $request, string $leadId)
    {
        $user = $request->user();
        $lead = $this->findLead($leadId);
        $b = Fail::validate(Fail::body($request), ['consultant_id' => 'required', 'reason' => 'nullable|string']);
        $cid = Ids::parse((string) $b['consultant_id']);
        $consultant = User::find($cid);
        if (! $consultant) {
            Fail::notFound('Consultant not found');
        }
        if (! $consultant->hasPermission('leads.assignable')) {
            Fail::bad('Assigned user must be a sales consultant or admin');
        }
        $prev = ['staff_id' => $lead->staff_id !== null ? (string) $lead->staff_id : null,
            'staff_name' => $lead->staff_name, 'staff_code' => $lead->staff_code];
        $lead->fill([
            'staff_id' => $consultant->id,
            'staff_name' => $consultant->name ?? '',
            'staff_code' => $consultant->consultant_code ?: ($consultant->staff_code ?: ''),
            'updated_by' => $user->email,
        ])->save();
        ActivityLogger::log($user, 'reassign_lead', 'lead', $leadId, $prev, [
            'staff_id' => (string) $lead->staff_id, 'staff_name' => $lead->staff_name,
            'staff_code' => $lead->staff_code, 'updated_at' => Fail::nowIso(),
            'updated_by' => $user->email, 'reason' => $b['reason'] ?? '',
        ]);
        return $lead->fresh('stayCities')->toApi();
    }

    // ------------------------------------------------------------------- notes

    public function notes(Request $request, string $leadId)
    {
        $lead = $this->findLead($leadId);
        $this->assertCanSee($lead, $request->user());
        return LeadNote::where('lead_id', $leadId)->orderByDesc('created_at')->orderByDesc('id')->limit(500)->get()->values();
    }

    public function addNote(Request $request, string $leadId)
    {
        $user = $request->user();
        $lead = $this->findLead($leadId);
        $this->assertCanSee($lead, $user);
        $b = Fail::validate(Fail::body($request), ['note' => 'present|string']);
        $note = trim($b['note']);
        if ($note === '') {
            Fail::bad('Note cannot be empty');
        }
        return LeadNote::create([
            'lead_id' => $leadId, 'note' => $note, 'created_by_id' => $user->id,
            'created_by' => $user->name ?: $user->email, 'created_at' => now(),
        ])->fresh();
    }

    public function deleteNote(Request $request, string $nid)
    {
        $user = $request->user();
        $note = LeadNote::find(Ids::parse($nid));
        if (! $note) {
            Fail::notFound('Not found');
        }
        if (! $user->hasPermission('leads.notes_delete_any') && (int) $note->created_by_id !== (int) $user->id) {
            Fail::forbidden('Only the author or admin can delete');
        }
        $note->delete();
        return ['success' => true];
    }

    // ----------------------------------------------------------- contact events

    public function logContact(Request $request, string $leadId)
    {
        $user = $request->user();
        $lead = $this->findLead($leadId);
        $this->assertCanSee($lead, $user);
        $b = Fail::validate(Fail::body($request), [
            'kind' => 'required|string', 'channel' => 'nullable|string',
            'target' => 'nullable|string', 'note' => 'nullable|string',
        ]);
        return ContactEvent::create([
            'lead_id' => $leadId, 'kind' => $b['kind'], 'channel' => ($b['channel'] ?? '') ?: 'customer',
            'target' => $b['target'] ?? '', 'note' => $b['note'] ?? '',
            'user_id' => $user->id, 'user_name' => $user->name ?: $user->email, 'timestamp' => now(),
        ])->fresh();
    }

    public function contacts(Request $request, string $leadId)
    {
        $lead = $this->findLead($leadId);
        $this->assertCanSee($lead, $request->user());
        return ContactEvent::where('lead_id', $leadId)->orderByDesc('timestamp')->orderByDesc('id')->limit(500)->get()->values();
    }
}
