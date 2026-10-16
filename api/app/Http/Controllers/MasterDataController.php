<?php

namespace App\Http\Controllers;

use App\Models\Allocation;
use App\Models\DestinationCity;
use App\Models\Master;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Ids;
use Illuminate\Http\Request;

class MasterDataController extends Controller
{
    // ---------- /lead-options ----------

    /** Dropdown values and destination cities for the lead form. Open to anyone who can create or edit leads, so the Master Data page itself can stay admin-only. */
    public function leadOptions()
    {
        $master = [];
        foreach (Master::query()->orderBy('id')->get(['category', 'value']) as $m) {
            $master[$m->category][] = $m->value;
        }

        $owners = User::query()->where('active', true)->where('account_status', 'active')->orderBy('name')->get()
            ->filter(fn (User $u) => $u->hasPermission('leads.assignable'))
            ->map(fn (User $u) => ['id' => (string) $u->id, 'name' => $u->name, 'code' => $u->consultant_code ?: ($u->staff_code ?: '')])->values();

        // Places the team has typed on leads that are not in Master Data yet, so the next person can pick them instead of retyping
        $used = [];
        foreach (['destination' => 'destination', 'departure_from' => 'departure_city'] as $col => $category) {
            $known = collect($master[$category] ?? [])->map(fn ($v) => mb_strtolower(trim((string) $v)))->all();
            $used[$category] = \App\Models\Lead::query()->whereNotNull($col)->where($col, '!=', '')->distinct()->orderBy($col)->limit(2000)->pluck($col)
                ->map(fn ($v) => trim((string) $v))->filter(fn ($v) => $v !== '' && ! in_array(mb_strtolower($v), $known, true))
                // one entry per place, preferring a spelling that is not all lower case
                ->groupBy(fn ($v) => mb_strtolower($v))->map(fn ($g) => $g->first(fn ($v) => $v !== mb_strtolower($v)) ?? $g->first())
                ->sort()->values()->all();
        }

        return ['master' => (object) $master, 'used' => (object) $used, 'cities' => $this->citiesIndex()->getData(), 'owners' => $owners];
    }

    // ---------- /master ----------
    public function masterIndex(Request $request)
    {
        $q = Master::query();
        if ($c = $request->query('category')) {
            $q->where('category', $c);
        }

        return $q->orderBy('id')->get();
    }

    public function masterStore(Request $request)
    {
        $data = $request->validate(['category' => ['required', 'string', 'max:60'], 'value' => ['required', 'string', 'max:255']]);
        $existing = Master::where($data)->first();
        if ($existing) {
            return $existing;
        }
        $m = Master::create($data);
        ActivityLogger::log($request->user(), 'add_master', 'master', $m->id, null, $data);

        return $m->fresh();
    }

    public function masterDestroy(string $mid)
    {
        Master::where('id', Ids::parse($mid))->delete();

        return ['success' => true];
    }

    // ---------- /destination-cities ----------
    public function citiesIndex()
    {
        $out = [];
        foreach (DestinationCity::with('items')->orderBy('destination')->get() as $d) {
            $out[$d->destination] = $d->cityNames();
        }

        return response()->json((object) $out);
    }

    public function citiesShow(string $destination)
    {
        $d = DestinationCity::with('items')->where('destination', $destination)->first();

        return ['destination' => $destination, 'cities' => $d ? $d->cityNames() : []];
    }

    public function citiesUpdate(Request $request, string $destination)
    {
        $request->validate(['cities' => ['present', 'array'], 'cities.*' => ['nullable', 'string']]);
        $cities = collect($request->input('cities'))->map(fn ($c) => trim((string) $c))->filter()->values()->all();
        $d = DestinationCity::firstOrCreate(['destination' => $destination]);
        $d->replaceCities($cities);
        ActivityLogger::log($request->user(), 'set_destination_cities', 'destination_cities', $destination, null, ['cities' => $cities]);

        return ['destination' => $destination, 'cities' => $cities];
    }

    // ---------- /allocations ----------
    public function allocationsIndex()
    {
        return Allocation::with('consultant')->orderBy('destination')->get()->map(fn ($a) => [
            'id' => (string) $a->id,
            'destination' => $a->destination,
            'consultant_id' => (string) $a->consultant_id,
            'consultant_name' => $a->consultant?->name ?? '—',
            'consultant_code' => ($a->consultant?->consultant_code ?: $a->consultant?->staff_code) ?? '',
        ])->values();
    }

    public function allocationsStore(Request $request)
    {
        $data = $request->validate(['destination' => ['required', 'string'], 'consultant_id' => ['required']]);
        $consultant = User::find(Ids::parse((string) $data['consultant_id']));
        if (! $consultant) {
            abort(404, 'Consultant not found');
        }
        if (! $consultant->hasPermission('leads.assignable')) {
            abort(400, 'Assigned user must be a sales consultant or admin');
        }
        Allocation::updateOrCreate(['destination' => $data['destination']], ['consultant_id' => $consultant->id]);
        ActivityLogger::log($request->user(), 'set_allocation', 'allocation', $data['destination'], null, ['consultant_id' => (string) $consultant->id]);

        return ['success' => true];
    }

    public function allocationsDestroy(string $aid)
    {
        Allocation::where('id', Ids::parse($aid))->delete();

        return ['success' => true];
    }

    // ---------- /suppliers ----------
    public function suppliersIndex()
    {
        $groups = \Illuminate\Support\Facades\DB::table('supplier_group_members')->get(['supplier_id', 'supplier_group_id'])
            ->groupBy('supplier_id')->map(fn ($r) => $r->pluck('supplier_group_id')->map(fn ($i) => (string) $i)->values()->all());

        return Supplier::orderBy('name')->get()->map(fn ($s) => array_merge($s->toArray(), ['group_ids' => $groups[$s->id] ?? []]))->values();
    }

    /** Put a supplier in exactly these groups (ids that do not exist are ignored). */
    private function syncGroups(Supplier $s, array $ids): void
    {
        $valid = \App\Models\SupplierGroup::whereIn('id', collect($ids)->map(fn ($i) => Ids::parse((string) $i))->filter()->all())->pluck('id')->all();
        \Illuminate\Support\Facades\DB::table('supplier_group_members')->where('supplier_id', $s->id)->delete();
        foreach ($valid as $gid) {
            \Illuminate\Support\Facades\DB::table('supplier_group_members')->insert(['supplier_group_id' => $gid, 'supplier_id' => $s->id]);
        }
    }

    public function suppliersStore(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'destination' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'portal_enabled' => ['sometimes', 'boolean'],
            'group_ids' => ['sometimes', 'nullable', 'array'],
        ]);
        $data['email'] = strtolower($data['email']);
        if (Supplier::where('email', $data['email'])->exists()) {
            abort(400, 'Supplier with this email already exists');
        }
        $s = Supplier::create([
            'name' => $data['name'], 'email' => $data['email'],
            'phone' => $data['phone'] ?? '', 'destination' => $data['destination'] ?? '', 'country' => $data['country'] ?? '',
            'portal_enabled' => (bool) ($data['portal_enabled'] ?? false),
        ]);
        if ($s->portal_enabled) {
            \App\Services\SupplierPortal\PortalMail::ensureUser($s);
        }
        if (array_key_exists('group_ids', $data)) {
            $this->syncGroups($s, (array) $data['group_ids']);
        }
        ActivityLogger::log($request->user(), 'create_supplier', 'supplier', $s->id, null, ['name' => $s->name]);

        return $s->fresh();
    }

    public function suppliersUpdate(Request $request, string $sid)
    {
        $s = Supplier::find(Ids::parse($sid));
        if (! $s) {
            \App\Crm\Fail::notFound('Supplier not found');
        }
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'destination' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'portal_enabled' => ['sometimes', 'boolean'],
            'group_ids' => ['sometimes', 'nullable', 'array'],
        ]);
        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);
            if (Supplier::where('email', $data['email'])->where('id', '<>', $s->id)->exists()) {
                abort(400, 'Another supplier already uses this email');
            }
        }
        $before = $s->only(['name', 'email', 'phone', 'destination', 'country', 'portal_enabled']);
        foreach (['phone', 'destination', 'country'] as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = (string) ($data[$k] ?? '');
            }
        }
        $groupIds = $data['group_ids'] ?? null;
        unset($data['group_ids']);
        $s->fill($data)->save();
        if ($groupIds !== null || $request->has('group_ids')) {
            $this->syncGroups($s, (array) $groupIds);
        }
        if ($s->portal_enabled) {
            \App\Services\SupplierPortal\PortalMail::ensureUser($s);
        } elseif (! $s->portal_enabled) {
            // switching the portal off ends every open supplier session at once
            \Illuminate\Support\Facades\DB::table('supplier_sessions')->whereIn('supplier_user_id', \App\Models\SupplierUser::where('supplier_id', $s->id)->select('id'))->delete();
        }
        ActivityLogger::log($request->user(), 'update_supplier', 'supplier', $s->id, $before, $s->only(array_keys($before)));

        return $s->fresh();
    }

    public function suppliersDestroy(Request $request, string $sid)
    {
        $id = Ids::parse($sid);
        if (\Illuminate\Support\Facades\DB::table('supplier_bills')->where('supplier_id', $id)->exists()
            || \Illuminate\Support\Facades\DB::table('supplier_payments')->where('supplier_id', $id)->exists()) {
            \App\Crm\Fail::http(409, 'This supplier has bills or payments in the books and cannot be deleted.');
        }
        Supplier::where('id', $id)->delete();
        ActivityLogger::log($request->user(), 'delete_supplier', 'supplier', $id);

        return ['success' => true];
    }
}
