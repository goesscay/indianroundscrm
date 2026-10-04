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
        if (! in_array($consultant->role, ['sales', 'admin'], true)) {
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
        return Supplier::orderBy('name')->get();
    }

    public function suppliersStore(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'destination' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
        ]);
        $data['email'] = strtolower($data['email']);
        if (Supplier::where('email', $data['email'])->exists()) {
            abort(400, 'Supplier with this email already exists');
        }
        $s = Supplier::create([
            'name' => $data['name'], 'email' => $data['email'],
            'phone' => $data['phone'] ?? '', 'destination' => $data['destination'] ?? '', 'country' => $data['country'] ?? '',
        ]);
        ActivityLogger::log($request->user(), 'create_supplier', 'supplier', $s->id, null, ['name' => $s->name]);

        return $s->fresh();
    }

    public function suppliersDestroy(Request $request, string $sid)
    {
        $id = Ids::parse($sid);
        Supplier::where('id', $id)->delete();
        ActivityLogger::log($request->user(), 'delete_supplier', 'supplier', $id);

        return ['success' => true];
    }
}
