<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\TransportCountry;
use App\Models\TransportPlace;
use App\Models\TransportProvider;
use App\Models\TransportRate;
use App\Models\TransportRoute;
use App\Models\TransportVehicle;
use App\Services\SpreadsheetRows;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The transport master (Operations -> Transport). Everyone with transport.view sees providers, vehicles and routes (to choose transport on a quotation);
 * vendor rates, margins, countries, places and the import are for transport.manage (Operations and Admin).
 */
class TransportController extends Controller
{
    private function manager(Request $r): bool
    {
        return (bool) $r->user()?->hasPermission('transport.manage');
    }

    private function find(string $model, string $id, string $what)
    {
        $m = ctype_digit($id) ? $model::find((int) $id) : null;
        if (! $m) {
            Fail::notFound("$what not found");
        }

        return $m;
    }

    private function date(?string $v, string $field): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            Fail::unprocessable([$field => 'Use dates like 2026-12-20']);
        }
    }

    // ------------------------------------------------------------------ rows

    private function vehicleRow(TransportVehicle $v): array
    {
        return ['id' => (string) $v->id, 'provider_id' => (string) $v->provider_id, 'name' => $v->name, 'mode' => $v->mode, 'seats' => (int) $v->seats, 'notes' => $v->notes, 'active' => (bool) $v->active];
    }

    private function routeRow(TransportRoute $r): array
    {
        return ['id' => (string) $r->id, 'provider_id' => (string) $r->provider_id, 'title' => $r->title, 'kind' => $r->kind,
            'from_place_id' => $r->from_place_id ? (string) $r->from_place_id : null, 'to_place_id' => $r->to_place_id ? (string) $r->to_place_id : null,
            'duration' => $r->duration, 'inclusions' => $r->inclusions, 'active' => (bool) $r->active];
    }

    private function rateRow(TransportRate $r): array
    {
        return ['id' => (string) $r->id, 'provider_id' => (string) $r->provider_id, 'vehicle_id' => (string) $r->vehicle_id, 'route_id' => $r->route_id ? (string) $r->route_id : null,
            'basis' => $r->basis, 'season' => $r->season, 'start_date' => $r->start_date?->toDateString(), 'end_date' => $r->end_date?->toDateString(),
            'cost' => $r->cost, 'child_cost' => $r->child_cost, 'currency' => $r->currency, 'dates_needed' => $r->start_date === null || $r->end_date === null];
    }

    private function placeRow(TransportPlace $p): array
    {
        return ['id' => (string) $p->id, 'country_code' => $p->country_code, 'name' => $p->name, 'kind' => $p->kind, 'city' => $p->city, 'code' => $p->code, 'timezone' => $p->timezone, 'active' => (bool) $p->active];
    }

    private function row(TransportProvider $p, bool $detail, bool $manager): array
    {
        $a = ['id' => (string) $p->id, 'name' => $p->name, 'country_code' => $p->country_code, 'destination' => $p->destination, 'city' => $p->city,
            'modes' => $p->modes ?? [], 'currency' => $p->currency, 'contact_name' => $p->contact_name, 'phone' => $p->phone, 'email' => $p->email,
            'notes' => $p->notes, 'active' => (bool) $p->active];
        if ($manager) {
            $a['margin_type'] = $p->margin_type;
            $a['margin_value'] = $p->margin_value;
        }
        if ($detail) {
            $a['vehicles'] = $p->vehicles->map(fn ($v) => $this->vehicleRow($v))->values()->all();
            $a['routes'] = $p->routes->map(fn ($r) => $this->routeRow($r))->values()->all();
            $a['rates'] = $manager ? $p->rates->map(fn ($r) => $this->rateRow($r))->values()->all() : [];
        }

        return $a;
    }

    // ------------------------------------------------------------------ lookups

    public function meta()
    {
        return [
            'countries' => TransportCountry::orderBy('name')->get()->map(fn ($c) => ['code' => $c->code, 'name' => $c->name, 'currency' => $c->currency, 'timezone' => $c->timezone, 'active' => (bool) $c->active])->values(),
            'modes' => TransportProvider::MODES, 'route_kinds' => TransportRoute::KINDS, 'place_kinds' => TransportPlace::KINDS, 'bases' => TransportRate::BASES,
        ];
    }

    public function destinations()
    {
        return TransportProvider::query()->select('destination')->distinct()->orderBy('destination')->pluck('destination')->values();
    }

    // ------------------------------------------------------------------ countries and places

    public function storeCountry(Request $request)
    {
        $d = Fail::validate(Fail::body($request), ['code' => 'required|alpha|size:2', 'name' => 'required|string|max:120', 'currency' => 'required|alpha|size:3', 'timezone' => 'nullable|string|max:60']);
        $d['code'] = strtoupper($d['code']);
        $d['currency'] = strtoupper($d['currency']);
        if (TransportCountry::find($d['code'])) {
            Fail::bad('That country is already set up.');
        }
        $c = TransportCountry::create($d + ['active' => true]);
        ActivityLogger::log($request->user(), 'create_transport_country', 'transport', $c->code, null, $d);

        return $c->toArray();
    }

    public function updateCountry(Request $request, string $code)
    {
        $c = TransportCountry::find(strtoupper($code)) ?: Fail::notFound('Country not found');
        $d = Fail::validate(Fail::body($request), ['name' => 'sometimes|required|string|max:120', 'currency' => 'sometimes|required|alpha|size:3', 'timezone' => 'nullable|string|max:60', 'active' => 'sometimes|boolean']);
        if (isset($d['currency'])) {
            $d['currency'] = strtoupper($d['currency']);
        }
        $c->fill($d)->save();

        return $c->toArray();
    }

    public function places(Request $request)
    {
        return TransportPlace::query()->when($request->query('country'), fn ($q, $c) => $q->where('country_code', strtoupper((string) $c)))
            ->orderBy('country_code')->orderBy('kind')->orderBy('name')->limit(2000)->get()->map(fn ($p) => $this->placeRow($p))->values();
    }

    private function validPlace(Request $request, bool $partial): array
    {
        $req = $partial ? 'sometimes|required' : 'required';
        $d = Fail::validate(Fail::body($request), ['country_code' => "$req|alpha|size:2", 'name' => "$req|string|max:160", 'kind' => 'nullable|in:'.implode(',', TransportPlace::KINDS),
            'city' => 'nullable|string|max:120', 'code' => 'nullable|string|max:12', 'timezone' => 'nullable|string|max:60', 'active' => 'sometimes|boolean']);
        if (isset($d['country_code'])) {
            $d['country_code'] = strtoupper($d['country_code']);
            if (! TransportCountry::find($d['country_code'])) {
                Fail::unprocessable(['country_code' => 'Set this country up first.']);
            }
        }

        return $d;
    }

    public function storePlace(Request $request)
    {
        $d = $this->validPlace($request, false);

        return $this->placeRow(TransportPlace::create($d + ['kind' => $d['kind'] ?? 'city']));
    }

    public function updatePlace(Request $request, string $id)
    {
        $p = $this->find(TransportPlace::class, $id, 'Place');
        $p->fill($this->validPlace($request, true))->save();

        return $this->placeRow($p);
    }

    public function destroyPlace(string $id)
    {
        $this->find(TransportPlace::class, $id, 'Place')->delete();

        return ['success' => true];
    }

    // ------------------------------------------------------------------ providers

    public function index(Request $request)
    {
        $q = TransportProvider::query()->withCount(['vehicles', 'routes', 'rates']);
        if (($v = (string) $request->query('destination', '')) !== '') {
            $q->where('destination', $v);
        }
        if (($v = (string) $request->query('country', '')) !== '') {
            $q->where('country_code', strtoupper($v));
        }
        if ($request->query('active', '1') === '1') {
            $q->where('active', true);
        }
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($s, '\\%_').'%';
            $op = Fail::ilike();
            $q->where(fn ($w) => $w->where('name', $op, $like)->orWhere('destination', $op, $like)->orWhere('city', $op, $like));
        }
        $m = $this->manager($request);

        return $q->orderBy('destination')->orderBy('name')->limit(1000)->get()
            ->map(fn (TransportProvider $p) => $this->row($p, false, $m) + ['vehicles_count' => $p->vehicles_count, 'routes_count' => $p->routes_count, 'rates_count' => $m ? $p->rates_count : null])->values();
    }

    public function show(Request $request, string $id)
    {
        $p = $this->find(TransportProvider::class, $id, 'Provider')->load(['vehicles', 'routes', 'rates']);

        return $this->row($p, true, $this->manager($request));
    }

    private function validProvider(Request $request, bool $partial): array
    {
        $req = $partial ? 'sometimes|required' : 'required';
        $d = Fail::validate(Fail::body($request), [
            'name' => "$req|string|max:200", 'country_code' => "$req|alpha|size:2", 'destination' => "$req|string|max:120", 'city' => 'nullable|string|max:120',
            'modes' => 'nullable|array|max:4', 'modes.*' => 'in:'.implode(',', TransportProvider::MODES), 'currency' => 'nullable|alpha|size:3',
            'contact_name' => 'nullable|string|max:120', 'phone' => 'nullable|string|max:60', 'email' => 'nullable|email|max:200', 'notes' => 'nullable|string|max:2000',
            'margin_type' => 'nullable|in:percent,amount', 'margin_value' => 'nullable|numeric|min:0|max:10000000', 'active' => 'sometimes|boolean',
        ]);
        foreach (['name', 'destination', 'city', 'contact_name', 'phone', 'email', 'notes'] as $k) {
            if (array_key_exists($k, $d) && is_string($d[$k])) {
                $d[$k] = trim($d[$k]) === '' ? null : trim($d[$k]);
            }
        }
        if (isset($d['country_code'])) {
            $d['country_code'] = strtoupper($d['country_code']);
            $country = TransportCountry::find($d['country_code']) ?: Fail::unprocessable(['country_code' => 'Set this country up first.']);
            $d['currency'] = strtoupper($d['currency'] ?? $country->currency);
        } elseif (isset($d['currency'])) {
            $d['currency'] = strtoupper($d['currency']);
        }
        if (isset($d['modes'])) {
            $d['modes'] = array_values(array_unique($d['modes']));
        }
        if (array_key_exists('margin_type', $d) && ! $d['margin_type']) {
            $d['margin_type'] = null;
            $d['margin_value'] = null;
        }

        return $d;
    }

    public function store(Request $request)
    {
        $d = $this->validProvider($request, false);
        if (TransportProvider::whereRaw('lower(name) = ?', [mb_strtolower($d['name'])])->where('destination', $d['destination'])->exists()) {
            Fail::bad("{$d['name']} is already in the master for {$d['destination']}.");
        }
        $p = TransportProvider::create($d + ['created_by' => $request->user()->id]);
        ActivityLogger::log($request->user(), 'create_transport_provider', 'transport', $p->id, null, ['name' => $p->name, 'destination' => $p->destination]);

        return $this->row($p->load(['vehicles', 'routes', 'rates']), true, true);
    }

    public function update(Request $request, string $id)
    {
        $p = $this->find(TransportProvider::class, $id, 'Provider');
        $d = $this->validProvider($request, true);
        if (isset($d['currency']) && $d['currency'] !== $p->currency && $p->rates()->exists()) {
            Fail::bad('This provider already has rates in '.$p->currency.'. Remove them before changing the currency.');
        }
        $before = $p->only(['name', 'destination', 'currency', 'margin_type', 'margin_value', 'active']);
        $p->fill($d)->save();
        ActivityLogger::log($request->user(), 'update_transport_provider', 'transport', $p->id, $before, $p->only(array_keys($before)));

        return $this->row($p->fresh(['vehicles', 'routes', 'rates']), true, true);
    }

    public function destroy(Request $request, string $id)
    {
        $p = $this->find(TransportProvider::class, $id, 'Provider');
        $name = $p->name;
        $p->delete();
        ActivityLogger::log($request->user(), 'delete_transport_provider', 'transport', $id, ['name' => $name], null);

        return ['success' => true];
    }

    // ------------------------------------------------------------------ vehicles

    private function validVehicle(Request $request, bool $partial): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return Fail::validate(Fail::body($request), ['name' => "$req|string|max:200", 'mode' => 'nullable|in:'.implode(',', TransportProvider::MODES),
            'seats' => "$req|integer|min:1|max:100", 'notes' => 'nullable|string|max:600', 'active' => 'sometimes|boolean']);
    }

    public function storeVehicle(Request $request, string $id)
    {
        $p = $this->find(TransportProvider::class, $id, 'Provider');
        $d = $this->validVehicle($request, false);
        $d['name'] = trim($d['name']);
        if (TransportVehicle::where('provider_id', $p->id)->whereRaw('lower(name) = ?', [mb_strtolower($d['name'])])->exists()) {
            Fail::bad('This provider already has a vehicle with that name.');
        }

        return $this->vehicleRow(TransportVehicle::create($d + ['provider_id' => $p->id, 'mode' => $d['mode'] ?? 'cab']));
    }

    public function updateVehicle(Request $request, string $id)
    {
        $v = $this->find(TransportVehicle::class, $id, 'Vehicle');
        $v->fill($this->validVehicle($request, true))->save();

        return $this->vehicleRow($v);
    }

    public function destroyVehicle(string $id)
    {
        $this->find(TransportVehicle::class, $id, 'Vehicle')->delete();

        return ['success' => true];
    }

    // ------------------------------------------------------------------ routes

    private function validRoute(Request $request, bool $partial): array
    {
        $req = $partial ? 'sometimes|required' : 'required';
        $d = Fail::validate(Fail::body($request), ['title' => "$req|string|max:240", 'kind' => 'nullable|in:'.implode(',', TransportRoute::KINDS),
            'from_place_id' => 'nullable|integer|exists:transport_places,id', 'to_place_id' => 'nullable|integer|exists:transport_places,id',
            'duration' => 'nullable|string|max:80', 'inclusions' => 'nullable|string|max:1000', 'active' => 'sometimes|boolean']);
        if (isset($d['title'])) {
            $d['title'] = trim($d['title']);
        }

        return $d;
    }

    public function storeRoute(Request $request, string $id)
    {
        $p = $this->find(TransportProvider::class, $id, 'Provider');
        $d = $this->validRoute($request, false);
        if (TransportRoute::where('provider_id', $p->id)->whereRaw('lower(title) = ?', [mb_strtolower($d['title'])])->exists()) {
            Fail::bad('This provider already has a route with that title.');
        }

        return $this->routeRow(TransportRoute::create($d + ['provider_id' => $p->id, 'kind' => $d['kind'] ?? 'sightseeing']));
    }

    public function updateRoute(Request $request, string $id)
    {
        $r = $this->find(TransportRoute::class, $id, 'Route');
        $r->fill($this->validRoute($request, true))->save();

        return $this->routeRow($r);
    }

    public function destroyRoute(string $id)
    {
        $this->find(TransportRoute::class, $id, 'Route')->delete();

        return ['success' => true];
    }

    // ------------------------------------------------------------------ rates

    /** A dated rate may not overlap another dated rate of the same vehicle, route and basis. Rates without dates are not compared. */
    private function assertNoClash(int $providerId, int $vehicleId, ?int $routeId, string $basis, ?string $start, ?string $end, ?int $ignoreId): void
    {
        if (! $start || ! $end) {
            return;
        }
        $clash = TransportRate::where('provider_id', $providerId)->where('vehicle_id', $vehicleId)->where('basis', $basis)
            ->when($routeId, fn ($q) => $q->where('route_id', $routeId), fn ($q) => $q->whereNull('route_id'))
            ->when($ignoreId, fn ($q) => $q->where('id', '<>', $ignoreId))
            ->whereNotNull('start_date')->where('start_date', '<=', $end)->where('end_date', '>=', $start)->first();
        if ($clash) {
            Fail::bad(sprintf('This overlaps the %s rate of %s to %s for the same vehicle and route.', $clash->season ?: 'existing', $clash->start_date->format('d M Y'), $clash->end_date->format('d M Y')));
        }
    }

    private function validRate(Request $request, TransportProvider $p, ?TransportRate $existing): array
    {
        $d = Fail::validate(Fail::body($request), [
            'vehicle_id' => ($existing ? 'sometimes|' : '').'required|integer', 'route_id' => 'nullable|integer', 'basis' => ($existing ? 'sometimes|' : '').'required|in:'.implode(',', array_keys(TransportRate::BASES)),
            'season' => 'nullable|string|max:80', 'start_date' => 'nullable|string', 'end_date' => 'nullable|string',
            'cost' => ($existing ? 'sometimes|' : '').'required|numeric|min:0|max:100000000', 'child_cost' => 'nullable|numeric|min:0|max:100000000',
        ]);
        if (isset($d['vehicle_id']) && ! TransportVehicle::where('provider_id', $p->id)->where('id', $d['vehicle_id'])->exists()) {
            Fail::unprocessable(['vehicle_id' => 'Choose one of this provider\'s vehicles']);
        }
        if (! empty($d['route_id']) && ! TransportRoute::where('provider_id', $p->id)->where('id', $d['route_id'])->exists()) {
            Fail::unprocessable(['route_id' => 'Choose one of this provider\'s routes']);
        }
        foreach (['start_date', 'end_date'] as $k) {
            if (array_key_exists($k, $d)) {
                $d[$k] = $this->date($d[$k], $k);
            }
        }
        $start = array_key_exists('start_date', $d) ? $d['start_date'] : $existing?->start_date?->toDateString();
        $end = array_key_exists('end_date', $d) ? $d['end_date'] : $existing?->end_date?->toDateString();
        if (($start === null) !== ($end === null)) {
            Fail::unprocessable(['end_date' => 'Give both the start and the end date, or leave both empty until the client gives the season dates']);
        }
        if ($start && $end < $start) {
            Fail::unprocessable(['end_date' => 'The end date is before the start date']);
        }
        $this->assertNoClash($p->id, (int) ($d['vehicle_id'] ?? $existing?->vehicle_id), (int) (array_key_exists('route_id', $d) ? $d['route_id'] : $existing?->route_id) ?: null,
            (string) ($d['basis'] ?? $existing?->basis), $start, $end, $existing?->id);

        return $d;
    }

    public function storeRate(Request $request, string $id)
    {
        $p = $this->find(TransportProvider::class, $id, 'Provider');
        $d = $this->validRate($request, $p, null);
        $r = TransportRate::create($d + ['provider_id' => $p->id, 'currency' => $p->currency]);
        ActivityLogger::log($request->user(), 'create_transport_rate', 'transport', $p->id, null, ['vehicle_id' => $r->vehicle_id, 'basis' => $r->basis, 'cost' => $r->cost]);

        return $this->rateRow($r);
    }

    public function updateRate(Request $request, string $id)
    {
        $r = $this->find(TransportRate::class, $id, 'Rate');
        $r->fill($this->validRate($request, TransportProvider::findOrFail($r->provider_id), $r))->save();

        return $this->rateRow($r);
    }

    public function destroyRate(string $id)
    {
        $this->find(TransportRate::class, $id, 'Rate')->delete();

        return ['success' => true];
    }

    // ------------------------------------------------------------------ import

    private const IMPORT_COLUMNS = ['provider', 'country', 'destination', 'currency', 'mode', 'vehicle', 'seats', 'route', 'route_kind', 'basis', 'season', 'start_date', 'end_date', 'cost', 'child_cost'];

    public function importTemplate()
    {
        $rows = [self::IMPORT_COLUMNS,
            ['Manali Taxi Service', 'IN', 'Manali', 'INR', 'cab', 'Ertiga', '6', 'Local Manali, Solang Valley, Kullu-Naggar', 'sightseeing', 'per_day', 'Off Season', '', '', '5000', ''],
            ['Volvo Bus: Delhi / Chandigarh - Manali', 'IN', 'Manali', 'INR', 'bus', 'Volvo seat', '1', '', '', 'per_person', 'Mid Season', '', '', '2500', ''],
            ['Lion City Cabs', 'SG', 'Singapore', 'SGD', 'cab', 'Sedan', '4', 'Airport to city hotel', 'transfer', 'per_transfer', 'All year', '2026-01-01', '2026-12-31', '55', '']];
        $csv = "\xEF\xBB\xBF".implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', $c).'"', $r)), $rows))."\n";

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="transport-rates-template.csv"']);
    }

    private function importDate(string $v): string|false|null
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString();
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $v);
            } catch (\Throwable) {
                continue;
            }
            if ($d && $d->format($fmt) === $v) {
                return $d->toDateString();
            }
        }

        return false;
    }

    /** POST /transport/import (file, dry_run): providers, vehicles, routes and rate cards from a sheet. A dry run only reports what would happen. */
    public function import(Request $request)
    {
        $file = $request->file('file');
        $ext = strtolower((string) $file?->getClientOriginalExtension());
        if (! $file || ! in_array($ext, ['csv', 'xlsx'], true)) {
            Fail::unprocessable(['file' => 'Upload a .csv or .xlsx file (use the template).']);
        }
        $rows = SpreadsheetRows::read($file->getRealPath(), $ext);
        $head = array_map(fn ($h) => strtolower(trim(str_replace([' ', '-'], '_', (string) $h))), array_shift($rows) ?? []);
        foreach (['provider', 'country', 'destination', 'vehicle', 'basis', 'cost'] as $need) {
            if (! in_array($need, $head, true)) {
                Fail::unprocessable(['file' => "The sheet needs a \"$need\" column. Use the template."]);
            }
        }
        $dry = filter_var($request->input('dry_run', false), FILTER_VALIDATE_BOOL);
        $countries = TransportCountry::all()->keyBy('code');
        $errors = [];
        $seen = ['providers' => [], 'vehicles' => [], 'routes' => [], 'rates' => 0, 'updated' => 0];
        $plan = [];
        foreach ($rows as $i => $cells) {
            if (! array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                continue;
            }
            $r = [];
            foreach ($head as $k => $name) {
                $r[$name] = trim((string) ($cells[$k] ?? ''));
            }
            $line = $i + 2;
            $code = strtoupper($r['country'] ?? '');
            $start = $this->importDate($r['start_date'] ?? '');
            $end = $this->importDate($r['end_date'] ?? '');
            $cost = str_replace(',', '', $r['cost'] ?? '');
            $child = str_replace(',', '', $r['child_cost'] ?? '');
            $mode = strtolower($r['mode'] ?? '') ?: 'cab';
            $kind = strtolower($r['route_kind'] ?? '') ?: 'sightseeing';
            $bad = null;
            if (($r['provider'] ?? '') === '' || ($r['destination'] ?? '') === '' || ($r['vehicle'] ?? '') === '') {
                $bad = 'provider, destination and vehicle are needed';
            } elseif (! $countries->has($code)) {
                $bad = "country \"$code\" is not set up yet (add it under Countries first)";
            } elseif (! in_array($mode, TransportProvider::MODES, true)) {
                $bad = 'mode must be cab, van, bus or ferry';
            } elseif (! isset(TransportRate::BASES[strtolower($r['basis'] ?? '')])) {
                $bad = 'basis must be per_day, per_transfer, per_route or per_person';
            } elseif (! is_numeric($cost) || (float) $cost < 0) {
                $bad = 'cost is not a number';
            } elseif ($child !== '' && (! is_numeric($child) || (float) $child < 0)) {
                $bad = 'child_cost is not a number';
            } elseif ($start === false || $end === false || ($start === null) !== ($end === null) || ($start && $end < $start)) {
                $bad = 'check the start and end dates (use 2026-12-20, or leave both empty)';
            } elseif (($r['route'] ?? '') !== '' && ! in_array($kind, TransportRoute::KINDS, true)) {
                $bad = 'route_kind must be sightseeing, transfer or intercity';
            }
            if ($bad) {
                $errors[] = ['row' => $line, 'error' => $bad];
                continue;
            }
            $plan[] = ['row' => $line, 'r' => $r, 'code' => $code, 'mode' => $mode, 'kind' => $kind, 'basis' => strtolower($r['basis']), 'start' => $start, 'end' => $end, 'cost' => (float) $cost, 'child' => $child === '' ? null : (float) $child];
        }

        DB::beginTransaction();
        try {
            foreach ($plan as $p) {
                $r = $p['r'];
                $prov = TransportProvider::whereRaw('lower(name) = ?', [mb_strtolower($r['provider'])])->where('destination', $r['destination'])->first();
                if (! $prov) {
                    $cur = strtoupper($r['currency'] ?? '') ?: $countries[$p['code']]->currency;
                    $prov = TransportProvider::create(['name' => $r['provider'], 'country_code' => $p['code'], 'destination' => $r['destination'], 'currency' => $cur, 'modes' => [$p['mode']], 'created_by' => $request->user()->id]);
                    $seen['providers'][$prov->id] = true;
                } elseif (! in_array($p['mode'], $prov->modes ?? [], true)) {
                    $prov->modes = array_values(array_merge($prov->modes ?? [], [$p['mode']]));
                    $prov->save();
                }
                $veh = TransportVehicle::where('provider_id', $prov->id)->whereRaw('lower(name) = ?', [mb_strtolower($r['vehicle'])])->first()
                    ?: TransportVehicle::create(['provider_id' => $prov->id, 'name' => $r['vehicle'], 'mode' => $p['mode'], 'seats' => ctype_digit($r['seats'] ?? '') && (int) $r['seats'] > 0 ? min(100, (int) $r['seats']) : 4]);
                $seen['vehicles'][$veh->id] = true;
                $route = null;
                if (($r['route'] ?? '') !== '') {
                    $route = TransportRoute::where('provider_id', $prov->id)->whereRaw('lower(title) = ?', [mb_strtolower($r['route'])])->first()
                        ?: TransportRoute::create(['provider_id' => $prov->id, 'title' => $r['route'], 'kind' => $p['kind']]);
                    $seen['routes'][$route->id] = true;
                }
                $same = TransportRate::where('provider_id', $prov->id)->where('vehicle_id', $veh->id)->where('basis', $p['basis'])
                    ->when($route, fn ($q) => $q->where('route_id', $route->id), fn ($q) => $q->whereNull('route_id'))
                    ->where('season', ($r['season'] ?? '') ?: null)->first();
                try {
                    $this->assertNoClash($prov->id, $veh->id, $route?->id, $p['basis'], $p['start'], $p['end'], $same?->id);
                } catch (\Throwable) {
                    $errors[] = ['row' => $p['row'], 'error' => 'the dates overlap another season of the same vehicle and route'];
                    continue;
                }
                $data = ['basis' => $p['basis'], 'season' => ($r['season'] ?? '') ?: null, 'start_date' => $p['start'], 'end_date' => $p['end'], 'cost' => $p['cost'], 'child_cost' => $p['child'], 'currency' => $prov->currency];
                if ($same) {
                    $same->fill($data)->save();
                    $seen['updated']++;
                } else {
                    TransportRate::create(['provider_id' => $prov->id, 'vehicle_id' => $veh->id, 'route_id' => $route?->id] + $data);
                    $seen['rates']++;
                }
            }
            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        if (! $dry) {
            ActivityLogger::log($request->user(), 'import_transport', 'transport', '', null, ['providers_new' => count($seen['providers']), 'rates_new' => $seen['rates'], 'rates_updated' => $seen['updated']]);
        }

        return ['dry_run' => $dry, 'providers_new' => count($seen['providers']), 'vehicles_seen' => count($seen['vehicles']), 'routes_seen' => count($seen['routes']),
            'rates_new' => $seen['rates'], 'rates_updated' => $seen['updated'], 'errors' => $errors];
    }
}
