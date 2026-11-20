<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\TransportDestination;
use App\Models\TransportLine;
use App\Models\TransportLineSeason;
use App\Models\TransportSupplier;
use App\Services\SpreadsheetRows;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Operations -> Transport -> Rates: one list, one line per rate. Only the type, destination, service name, price and how it is charged are required.
 * Costs and prices are for transport.manage (Operations and Admin); sales never reach this list.
 */
class TransportLineController extends Controller
{
    private function find(string $id): TransportLine
    {
        $l = ctype_digit($id) ? TransportLine::with(['destination', 'supplier', 'seasons'])->find((int) $id) : null;
        if (! $l) {
            Fail::notFound('Rate not found');
        }

        return $l;
    }

    private function like(string $s): string
    {
        return '%'.addcslashes(trim($s), '\\%_').'%';
    }

    // ------------------------------------------------------------------ remembered names

    private function destination(string $name, ?string $country, ?string $currency): TransportDestination
    {
        $name = trim($name);
        $d = TransportDestination::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
        if ($d) {
            return $d;
        }

        return TransportDestination::create(['name' => $name, 'country' => trim((string) $country) ?: 'India', 'currency' => strtoupper(trim((string) $currency)) ?: 'INR']);
    }

    private function supplier(?string $name): ?TransportSupplier
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        return TransportSupplier::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first() ?: TransportSupplier::create(['name' => mb_substr($name, 0, 150)]);
    }

    /** GET /transport/lines/lookups: the names to suggest while typing (destinations shared with Hotels, suppliers, places already used). */
    public function lookups()
    {
        $dest = TransportDestination::orderBy('name')->get();
        $counts = TransportLine::query()->select('destination_id', DB::raw('count(*) as n'))->groupBy('destination_id')->pluck('n', 'destination_id');
        $known = $dest->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        $destinations = $dest->map(fn ($d) => ['name' => $d->name, 'country' => $d->country, 'currency' => $d->currency, 'known' => true, 'rates' => (int) ($counts[$d->id] ?? 0)])->all();
        foreach (Hotel::query()->select('destination')->distinct()->orderBy('destination')->pluck('destination') as $h) {
            if (! in_array(mb_strtolower($h), $known, true)) {
                $destinations[] = ['name' => $h, 'country' => 'India', 'currency' => 'INR', 'known' => false, 'rates' => 0];          // in Hotels already; its country is confirmed on its first rate
            }
        }
        usort($destinations, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        $places = collect(TransportLine::query()->whereNotNull('from_name')->pluck('from_name'))->merge(TransportLine::query()->whereNotNull('to_name')->pluck('to_name'))
            ->countBy()->map(fn ($n, $name) => ['name' => (string) $name, 'rates' => $n])->sortKeys()->values();

        return [
            'destinations' => $destinations, 'places' => $places,
            'suppliers' => TransportLine::query()->join('transport_suppliers', 'transport_suppliers.id', '=', 'transport_lines.supplier_id')->select('transport_suppliers.name', DB::raw('count(*) as rates'))->groupBy('transport_suppliers.name')->orderBy('transport_suppliers.name')->get()->map(fn ($s) => ['name' => $s->name, 'rates' => (int) $s->rates])->values(),
            'types' => TransportLine::TYPES, 'charged' => TransportLine::CHARGED, 'ways' => TransportLine::WAYS,
        ];
    }

    // ------------------------------------------------------------------ the list

    public function index(Request $request)
    {
        $q = TransportLine::query()->with(['destination', 'supplier', 'seasons']);
        if (in_array($t = (string) $request->query('type', ''), array_keys(TransportLine::TYPES), true)) {
            $q->where('type', $t);
        }
        if ($request->query('active', '1') === '1') {
            $q->where('active', true);
        }
        if (($d = trim((string) $request->query('destination', ''))) !== '') {
            $q->whereHas('destination', fn ($w) => $w->whereRaw('lower(name) = ?', [mb_strtolower($d)]));
        }
        if ($request->query('needs_price') === '1') {
            $q->where(fn ($w) => $w->whereNull('price')->orWhere('price', '<=', 0));
        }
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $like = $this->like($s);
            $op = Fail::ilike();
            $q->where(fn ($w) => $w->where('name', $op, $like)->orWhere('from_name', $op, $like)->orWhere('to_name', $op, $like)
                ->orWhereHas('destination', fn ($x) => $x->where('name', $op, $like))->orWhereHas('supplier', fn ($x) => $x->where('name', $op, $like)));
        }

        return $q->orderBy('destination_id')->orderBy('type')->orderBy('name')->limit(3000)->get()->map(fn (TransportLine $l) => $l->toApi())->values();
    }

    // ------------------------------------------------------------------ add and change

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

    /** Dated prices of one line (its own and its seasons) may not overlap. @param list<array{0:?string,1:?string,2:string}> $periods from, to, name */
    private function assertNoOverlap(array $periods): void
    {
        $dated = array_values(array_filter($periods, fn ($p) => $p[0] && $p[1]));
        foreach ($dated as $i => $a) {
            foreach (array_slice($dated, $i + 1) as $b) {
                if ($a[0] <= $b[1] && $a[1] >= $b[0]) {
                    Fail::bad(sprintf('The dates of "%s" and "%s" overlap.', $a[2] ?: 'this price', $b[2] ?: 'this price'));
                }
            }
        }
    }

    private function periodsOf(TransportLine $l, ?string $from, ?string $to, ?string $name, ?int $exceptSeason = null): array
    {
        $out = [[$from, $to, (string) $name]];
        foreach ($l->seasons as $s) {
            if ($exceptSeason !== (int) $s->id) {
                $out[] = [$s->valid_from?->toDateString(), $s->valid_to?->toDateString(), $s->name];
            }
        }

        return $out;
    }

    private function valid(Request $request, bool $partial): array
    {
        $req = $partial ? 'sometimes|required' : 'required';
        $d = Fail::validate(Fail::body($request), [
            'type' => "$req|in:".implode(',', array_keys(TransportLine::TYPES)), 'destination' => "$req|string|max:120", 'country' => 'nullable|string|max:100', 'dest_currency' => 'nullable|alpha|size:3',
            'name' => "$req|string|max:200", 'charged' => "$req|in:".implode(',', array_keys(TransportLine::CHARGED)), 'price' => "$req|numeric|min:0|max:100000000",
            'currency' => 'nullable|alpha|size:3', 'supplier' => 'nullable|string|max:150', 'from_name' => 'nullable|string|max:120', 'to_name' => 'nullable|string|max:120',
            'way' => 'nullable|in:one_way,return', 'season_name' => 'nullable|string|max:80', 'valid_from' => 'nullable|string', 'valid_to' => 'nullable|string',
            'seats' => 'nullable|integer|min:1|max:100', 'hours_per_day' => 'nullable|numeric|min:0|max:24', 'km_included' => 'nullable|integer|min:0|max:100000',
            'extra_hour' => 'nullable|numeric|min:0|max:100000000', 'note' => 'nullable|string|max:600', 'active' => 'sometimes|boolean',
        ]);
        foreach (['name', 'from_name', 'to_name', 'season_name', 'note', 'supplier'] as $k) {
            if (array_key_exists($k, $d) && is_string($d[$k])) {
                $d[$k] = trim($d[$k]) === '' ? null : trim($d[$k]);
            }
        }
        foreach (['valid_from', 'valid_to'] as $k) {
            if (array_key_exists($k, $d)) {
                $d[$k] = $this->date($d[$k], $k);
            }
        }

        return $d;
    }

    private function lineFields(array $d): array
    {
        return array_intersect_key($d, array_flip(['type', 'name', 'charged', 'price', 'from_name', 'to_name', 'way', 'season_name', 'valid_from', 'valid_to', 'seats', 'hours_per_day', 'km_included', 'extra_hour', 'note', 'active']));
    }

    public function store(Request $request)
    {
        $d = $this->valid($request, false);
        if (($d['valid_from'] ?? null) xor ($d['valid_to'] ?? null)) {
            Fail::unprocessable(['valid_to' => 'Give both dates, or leave both empty for a price that applies all year']);
        }
        if (($d['valid_from'] ?? null) && $d['valid_to'] < $d['valid_from']) {
            Fail::unprocessable(['valid_to' => 'The end date is before the start date']);
        }
        $dest = $this->destination($d['destination'], $d['country'] ?? null, $d['dest_currency'] ?? null);
        $line = TransportLine::create($this->lineFields($d) + ['destination_id' => $dest->id, 'supplier_id' => $this->supplier($d['supplier'] ?? null)?->id,
            'currency' => strtoupper($d['currency'] ?? '') ?: $dest->currency, 'created_by' => $request->user()->id]);
        ActivityLogger::log($request->user(), 'create_transport_rate', 'transport', $line->id, null, ['name' => $line->name, 'destination' => $dest->name, 'price' => $line->price]);

        return $this->find((string) $line->id)->toApi();
    }

    public function update(Request $request, string $id)
    {
        $l = $this->find($id);
        $d = $this->valid($request, true);
        $from = array_key_exists('valid_from', $d) ? $d['valid_from'] : $l->valid_from?->toDateString();
        $to = array_key_exists('valid_to', $d) ? $d['valid_to'] : $l->valid_to?->toDateString();
        if (($from === null) !== ($to === null)) {
            Fail::unprocessable(['valid_to' => 'Give both dates, or leave both empty for a price that applies all year']);
        }
        if ($from && $to < $from) {
            Fail::unprocessable(['valid_to' => 'The end date is before the start date']);
        }
        $this->assertNoOverlap($this->periodsOf($l, $from, $to, $d['season_name'] ?? $l->season_name));
        $extra = [];
        if (isset($d['destination'])) {
            $extra['destination_id'] = $this->destination($d['destination'], $d['country'] ?? null, $d['dest_currency'] ?? null)->id;
        }
        if (array_key_exists('supplier', $d)) {
            $extra['supplier_id'] = $this->supplier($d['supplier'])?->id;
        }
        if (isset($d['currency'])) {
            $extra['currency'] = strtoupper($d['currency']);
        }
        $before = $l->only(['name', 'price', 'charged', 'currency']);
        $l->fill($this->lineFields($d) + $extra)->save();
        ActivityLogger::log($request->user(), 'update_transport_rate', 'transport', $l->id, $before, $l->only(array_keys($before)));

        return $this->find($id)->toApi();
    }

    public function destroy(Request $request, string $id)
    {
        $l = $this->find($id);
        $name = $l->name;
        $l->delete();
        ActivityLogger::log($request->user(), 'delete_transport_rate', 'transport', $id, ['name' => $name], null);

        return ['success' => true];
    }

    /** Copy a rate (for example Ertiga to Innova): the copy keeps everything, with "(copy)" on the name to rename. */
    public function duplicate(Request $request, string $id)
    {
        $l = $this->find($id);
        $copy = $l->replicate(['created_at', 'updated_at']);
        $copy->name = mb_substr($l->name.' (copy)', 0, 200);
        $copy->created_by = $request->user()->id;
        $copy->save();
        foreach ($l->seasons as $s) {
            $copy->seasons()->create($s->only(['name', 'valid_from', 'valid_to', 'price']));
        }

        return $this->find((string) $copy->id)->toApi();
    }

    // ------------------------------------------------------------------ seasons inside a line

    private function validSeason(Request $request, bool $partial): array
    {
        $req = $partial ? 'sometimes|required' : 'required';
        $d = Fail::validate(Fail::body($request), ['name' => "$req|string|max:80", 'valid_from' => 'nullable|string', 'valid_to' => 'nullable|string', 'price' => "$req|numeric|min:0|max:100000000"]);
        foreach (['valid_from', 'valid_to'] as $k) {
            if (array_key_exists($k, $d)) {
                $d[$k] = $this->date($d[$k], $k);
            }
        }

        return $d;
    }

    public function storeSeason(Request $request, string $id)
    {
        $l = $this->find($id);
        $d = $this->validSeason($request, false);
        $this->checkSeason($l, $d, null);
        $l->seasons()->create($d);
        ActivityLogger::log($request->user(), 'add_transport_season', 'transport', $l->id, null, ['season' => $d['name'], 'price' => $d['price']]);

        return $this->find($id)->toApi();
    }

    public function updateSeason(Request $request, string $id)
    {
        $s = ctype_digit($id) ? TransportLineSeason::find((int) $id) : null;
        if (! $s) {
            Fail::notFound('Season not found');
        }
        $l = $this->find((string) $s->line_id);
        $d = $this->validSeason($request, true);
        $this->checkSeason($l, $d + ['valid_from' => $s->valid_from?->toDateString(), 'valid_to' => $s->valid_to?->toDateString(), 'name' => $s->name], (int) $s->id);
        $s->fill($d)->save();

        return $this->find((string) $s->line_id)->toApi();
    }

    public function destroySeason(string $id)
    {
        $s = ctype_digit($id) ? TransportLineSeason::find((int) $id) : null;
        if (! $s) {
            Fail::notFound('Season not found');
        }
        $lineId = (string) $s->line_id;
        $s->delete();

        return $this->find($lineId)->toApi();
    }

    private function checkSeason(TransportLine $l, array $d, ?int $exceptId): void
    {
        $from = $d['valid_from'] ?? null;
        $to = $d['valid_to'] ?? null;
        if (($from === null) !== ($to === null)) {
            Fail::unprocessable(['valid_to' => 'Give both dates, or leave both empty until the client gives the season dates']);
        }
        if ($from && $to < $from) {
            Fail::unprocessable(['valid_to' => 'The end date is before the start date']);
        }
        $periods = [[$l->valid_from?->toDateString(), $l->valid_to?->toDateString(), (string) $l->season_name]];
        foreach ($l->seasons as $s) {
            if ($exceptId !== (int) $s->id) {
                $periods[] = [$s->valid_from?->toDateString(), $s->valid_to?->toDateString(), $s->name];
            }
        }
        $periods[] = [$from, $to, (string) ($d['name'] ?? '')];
        $this->assertNoOverlap($periods);
    }

    // ------------------------------------------------------------------ price changes

    /** POST /transport/lines/bulk-price {ids, direction, percent, preview}: raise or lower the prices of several rates, showing old and new first. */
    public function bulkPrice(Request $request)
    {
        $d = Fail::validate(Fail::body($request), ['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer', 'direction' => 'required|in:increase,decrease', 'percent' => 'required|numeric|gt:0|max:500', 'preview' => 'sometimes|boolean']);
        if ($d['direction'] === 'decrease' && $d['percent'] >= 100) {
            Fail::unprocessable(['percent' => 'A price cannot drop by 100% or more']);
        }
        $factor = $d['direction'] === 'increase' ? 1 + $d['percent'] / 100 : 1 - $d['percent'] / 100;
        $apply = fn (?float $p) => $p === null ? null : round($p * $factor, 2);
        $changes = [];
        $preview = filter_var($d['preview'] ?? true, FILTER_VALIDATE_BOOL);
        DB::beginTransaction();
        try {
            foreach (TransportLine::with(['destination', 'seasons'])->whereIn('id', $d['ids'])->get() as $l) {
                $row = ['id' => (string) $l->id, 'name' => $l->name, 'destination' => $l->destination?->name, 'currency' => $l->currency,
                    'old' => $l->price, 'new' => $apply($l->price), 'seasons' => $l->seasons->map(fn ($s) => ['name' => $s->name, 'old' => $s->price, 'new' => $apply($s->price)])->values()->all()];
                $changes[] = $row;
                if (! $preview) {
                    $l->price = $row['new'];
                    $l->save();
                    foreach ($l->seasons as $s) {
                        $s->price = $apply($s->price);
                        $s->save();
                    }
                }
            }
            $preview ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        if (! $preview) {
            ActivityLogger::log($request->user(), 'transport_bulk_price', 'transport', '', null, ['count' => count($changes), 'direction' => $d['direction'], 'percent' => $d['percent']]);
        }

        return ['preview' => $preview, 'changes' => $changes];
    }

    // ------------------------------------------------------------------ import

    private const IMPORT_COLUMNS = ['type', 'destination', 'country', 'currency', 'service', 'from', 'to', 'way', 'charged', 'price', 'season', 'valid_from', 'valid_to', 'supplier', 'seats', 'hours_per_day', 'km_included', 'extra_hour', 'note'];

    public function importTemplate()
    {
        $rows = [self::IMPORT_COLUMNS,
            ['cab', 'Manali', 'India', 'INR', 'Ertiga, 6 seats: local sightseeing', '', '', '', 'per_day', '5000', 'Off season', '', '', 'Manali Taxi Service', '6', '', '', '', ''],
            ['bus', 'Manali', '', '', 'Volvo, semi-sleeper', 'Delhi', 'Manali', 'one_way', 'per_person', '1800', '', '', '', 'Volvo desk', '', '', '', '', ''],
            ['cab', 'Singapore', 'Singapore', 'SGD', 'MPV, 6 seats: airport to hotel', 'Changi Airport', 'Hotel', '', 'per_trip', '60', '', '', '', '', '6', '', '', '', '']];
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

    private function charged(string $v): ?string
    {
        $v = strtolower(trim(str_replace(['_', '-'], ' ', $v)));

        return match (true) {
            in_array($v, ['per day', 'day', 'daily', 'a day', 'per day with hours'], true) => 'per_day',
            in_array($v, ['per trip', 'trip', 'per transfer', 'transfer', 'per route', 'route', 'per way', 'one trip'], true) => 'per_trip',
            in_array($v, ['per person', 'person', 'pp', 'per seat', 'seat', 'per head'], true) => 'per_person',
            default => null,
        };
    }

    /**
     * POST /transport/lines/import (file, dry_run, choices): a rate sheet. Every row is Ready, Check (a choice is needed: how it is charged, or a new
     * destination's country and currency) or Missing (will be skipped). A dry run only reports; nothing is saved until the import is confirmed.
     */
    public function import(Request $request)
    {
        $file = $request->file('file');
        $ext = strtolower((string) $file?->getClientOriginalExtension());
        if (! $file || ! in_array($ext, ['csv', 'xlsx'], true)) {
            Fail::unprocessable(['file' => 'Upload a .csv or .xlsx file (use the template).']);
        }
        $rows = SpreadsheetRows::read($file->getRealPath(), $ext);
        $head = array_map(fn ($h) => strtolower(trim(str_replace([' ', '-'], '_', (string) $h))), array_shift($rows) ?? []);
        $head = array_map(fn ($h) => $h === 'name' ? 'service' : $h, $head);
        foreach (['type', 'destination', 'service', 'price'] as $need) {
            if (! in_array($need, $head, true)) {
                Fail::unprocessable(['file' => "The sheet needs a \"$need\" column. Use the template."]);
            }
        }
        $dry = filter_var($request->input('dry_run', false), FILTER_VALIDATE_BOOL);
        $choices = (array) json_decode((string) $request->input('choices', '{}'), true);
        $pickCharged = (array) ($choices['charged'] ?? []);
        $pickDest = (array) ($choices['destinations'] ?? []);
        $known = TransportDestination::all()->keyBy(fn ($d) => mb_strtolower($d->name));

        $sheetDest = [];                                                // a new destination named with a country and currency on any row of the sheet counts for all its rows
        foreach ($rows as $cells) {
            $x = [];
            foreach ($head as $k => $name) {
                $x[$name] = trim((string) ($cells[$k] ?? ''));
            }
            if (($x['destination'] ?? '') !== '' && ($x['country'] ?? '') !== '' && ($x['currency'] ?? '') !== '') {
                $sheetDest[mb_strtolower($x['destination'])] = ['country' => $x['country'], 'currency' => strtoupper($x['currency'])];
            }
        }
        $out = [];
        $plan = [];
        $newDest = [];
        foreach ($rows as $i => $cells) {
            if (! array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                continue;
            }
            $r = [];
            foreach ($head as $k => $name) {
                $r[$name] = trim((string) ($cells[$k] ?? ''));
            }
            $line = $i + 2;
            $type = strtolower($r['type'] ?? '');
            $type = ['cabs' => 'cab', 'car' => 'cab', 'taxi' => 'cab', 'tempo' => 'van', 'tempo traveller' => 'van', 'vans' => 'van', 'buses' => 'bus', 'coach' => 'bus', 'ferries' => 'ferry', 'boat' => 'ferry'][$type] ?? $type;
            $dest = $r['destination'] ?? '';
            $price = str_replace(',', '', $r['price'] ?? '');
            $item = ['row' => $line, 'status' => 'Ready', 'type' => $type, 'destination' => $dest, 'service' => $r['service'] ?? '', 'charged' => null, 'price' => is_numeric($price) ? (float) $price : null, 'currency' => null, 'note' => '', 'needs' => null];

            if (! isset(TransportLine::TYPES[$type]) || $dest === '' || ($r['service'] ?? '') === '') {
                $item['status'] = 'Missing';
                $item['note'] = 'Needs a type (cab, van, bus or ferry), a destination and a service name. Will be skipped.';
            } elseif (! is_numeric($price) || (float) $price < 0) {
                $item['status'] = 'Missing';
                $item['note'] = 'Price is empty in the sheet. Will be skipped.';
            } else {
                $from = $this->importDate($r['valid_from'] ?? '');
                $to = $this->importDate($r['valid_to'] ?? '');
                $ch = $this->charged($r['charged'] ?? '') ?: ($pickCharged[(string) $line] ?? null);
                $ch = isset(TransportLine::CHARGED[$ch]) ? $ch : null;
                $dk = mb_strtolower($dest);
                $d = $known->get($dk);
                $item['currency'] = $d?->currency;                                  // shown with the price, even before the row is resolved
                $country = trim($r['country'] ?? '') ?: ($sheetDest[$dk]['country'] ?? '');
                $cur = strtoupper(trim($r['currency'] ?? '')) ?: ($sheetDest[$dk]['currency'] ?? '');
                $choice = (array) ($pickDest[$dest] ?? []);
                if ($from === false || $to === false || ($from === null) !== ($to === null) || ($from && $to < $from)) {
                    $item['status'] = 'Missing';
                    $item['note'] = 'Check the season dates (use 2026-12-20, or leave both empty). Will be skipped.';
                } elseif (! $d && ! (($country !== '' && $cur !== '') || (! empty($choice['country']) && ! empty($choice['currency'])))) {
                    $item['status'] = 'Check';
                    $item['needs'] = 'destination';
                    $item['note'] = "New destination: choose the country and currency for $dest.";
                    $newDest[$dest] = ['name' => $dest, 'country' => 'India', 'currency' => 'INR'];
                } elseif (! $ch) {
                    $item['status'] = 'Check';
                    $item['needs'] = 'charged';
                    $item['note'] = 'Charged per day, per trip or per person? Choose.';
                } else {
                    $item['charged'] = $ch;
                    $item['currency'] = $cur ?: ($d?->currency ?? strtoupper((string) $choice['currency']));
                    $item['note'] = $d ? 'Matched to '.$d->name.', '.$d->country : 'New destination '.$dest.', '.($country ?: $choice['country']);
                    $plan[] = ['row' => $line, 'r' => $r, 'type' => $type, 'charged' => $ch, 'price' => (float) $price, 'from' => $from, 'to' => $to, 'country' => $country ?: ($choice['country'] ?? null), 'currency' => $cur ?: ($choice['currency'] ?? null)];
                }
            }
            $out[] = $item;
        }

        $summary = ['ready' => count(array_filter($out, fn ($x) => $x['status'] === 'Ready')), 'check' => count(array_filter($out, fn ($x) => $x['status'] === 'Check')), 'missing' => count(array_filter($out, fn ($x) => $x['status'] === 'Missing'))];
        if ($dry) {
            return ['dry_run' => true, 'summary' => $summary, 'rows' => $out, 'new_destinations' => array_values($newDest)];
        }

        $new = $updated = 0;
        DB::beginTransaction();
        try {
            foreach ($plan as $p) {
                $r = $p['r'];
                $dest = $this->destination($r['destination'], $p['country'], $p['currency']);
                $sup = $this->supplier($r['supplier'] ?? null);
                $line = TransportLine::where('destination_id', $dest->id)->where('type', $p['type'])->whereRaw('lower(name) = ?', [mb_strtolower($r['service'])])
                    ->when($sup, fn ($q) => $q->where('supplier_id', $sup->id), fn ($q) => $q->whereNull('supplier_id'))->first();
                $season = ($r['season'] ?? '') !== '' ? $r['season'] : null;
                $num = fn ($k) => ($r[$k] ?? '') !== '' && is_numeric(str_replace(',', '', $r[$k])) ? (float) str_replace(',', '', $r[$k]) : null;
                if ($line && $line->season_name !== $season && ($season !== null || $line->season_name !== null)) {
                    $s = $line->seasons()->whereRaw('lower(name) = ?', [mb_strtolower((string) $season)])->first();          // another season of a service already in the list
                    $data = ['name' => $season ?: 'Season', 'valid_from' => $p['from'], 'valid_to' => $p['to'], 'price' => $p['price']];
                    $s ? $s->fill($data)->save() : $line->seasons()->create($data);
                    $updated++;
                    continue;
                }
                $data = ['name' => $r['service'], 'charged' => $p['charged'], 'price' => $p['price'], 'season_name' => $season, 'valid_from' => $p['from'], 'valid_to' => $p['to'],
                    'from_name' => ($r['from'] ?? '') ?: null, 'to_name' => ($r['to'] ?? '') ?: null, 'way' => in_array(strtolower(str_replace(' ', '_', $r['way'] ?? '')), ['one_way', 'return'], true) ? strtolower(str_replace(' ', '_', $r['way'])) : null,
                    'seats' => ctype_digit($r['seats'] ?? '') && (int) $r['seats'] > 0 ? min(100, (int) $r['seats']) : null, 'hours_per_day' => $num('hours_per_day'),
                    'km_included' => ctype_digit($r['km_included'] ?? '') ? (int) $r['km_included'] : null, 'extra_hour' => $num('extra_hour'), 'note' => ($r['note'] ?? '') ?: null];
                if ($line) {
                    $line->fill($data)->save();
                    $updated++;
                } else {
                    TransportLine::create($data + ['type' => $p['type'], 'destination_id' => $dest->id, 'supplier_id' => $sup?->id, 'currency' => $p['currency'] ?: $dest->currency, 'created_by' => $request->user()->id]);
                    $new++;
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        ActivityLogger::log($request->user(), 'import_transport_rates', 'transport', '', null, ['new' => $new, 'updated' => $updated]);

        return ['dry_run' => false, 'summary' => $summary, 'new' => $new, 'updated' => $updated, 'skipped' => count($out) - count($plan)];
    }
}
