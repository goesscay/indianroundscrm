<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\HotelRate;
use App\Models\HotelRoom;
use App\Models\Media;
use App\Services\HotelPricing;
use App\Services\MediaStore;
use App\Services\SpreadsheetRows;
use App\Support\ActivityLogger;
use App\Support\Ids;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The hotel master. Everyone with hotels.view sees hotels, rooms and photos (to choose a hotel on a quotation);
 * vendor rates, margins and the price calculator are for hotels.manage (Operations and Admin).
 */
class HotelController extends Controller
{
    private function manager(Request $r): bool
    {
        return (bool) $r->user()?->hasPermission('hotels.manage');
    }

    private function find(string $id): Hotel
    {
        $h = ctype_digit($id) ? Hotel::find((int) $id) : null;
        if (! $h) {
            Fail::notFound('Hotel not found');
        }

        return $h;
    }

    private function room(string $id): HotelRoom
    {
        $r = ctype_digit($id) ? HotelRoom::with('hotel')->find((int) $id) : null;
        if (! $r) {
            Fail::notFound('Room not found');
        }

        return $r;
    }

    private function rateRow(HotelRate $r): array
    {
        return ['id' => (string) $r->id, 'room_id' => (string) $r->room_id, 'meal_plan' => $r->meal_plan, 'season' => $r->season, 'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString()]
            + array_combine(HotelRate::PRICES, array_map(fn ($p) => $r->{$p}, HotelRate::PRICES));
    }

    private function row(Hotel $h, bool $detail, bool $manager): array
    {
        $cover = $h->relationLoaded('photos') ? $h->photos->first() : null;
        $a = [
            'id' => (string) $h->id, 'name' => $h->name, 'destination' => $h->destination, 'city' => $h->city, 'stars' => $h->stars, 'address' => $h->address,
            'phone' => $h->phone, 'website' => $h->website, 'google_rating' => $h->google_rating, 'google_reviews' => $h->google_reviews,
            'description' => $h->description, 'amenities' => $h->amenities ?? [], 'active' => (bool) $h->active,
            'cover' => $cover ? $cover->toApi() : null,
        ];
        if ($manager) {
            $a['margin_type'] = $h->margin_type;
            $a['margin_value'] = $h->margin_value;
            $a['margin'] = HotelPricing::margin($h);
        }
        if ($detail) {
            $a['photos'] = $h->photos->map(fn (Media $m) => $m->toApi())->values()->all();
            $a['rooms'] = $h->rooms->map(fn (HotelRoom $r) => [
                'id' => (string) $r->id, 'name' => $r->name, 'max_adults' => (int) $r->max_adults, 'notes' => $r->notes, 'active' => (bool) $r->active,
                'meals' => $r->rates->pluck('meal_plan')->unique()->values()->all(),            // which meal plans have rates (the prices stay hidden)
                'rates' => $manager ? $r->rates->map(fn ($x) => $this->rateRow($x))->values()->all() : [],
            ])->values()->all();
        }

        return $a;
    }

    // ------------------------------------------------------------------ hotels

    public function index(Request $request)
    {
        $q = Hotel::query()->with(['photos' => fn ($p) => $p->limit(1)]);
        if (($v = (string) $request->query('destination', '')) !== '') {
            $q->where('destination', $v);
        }
        if (($v = (string) $request->query('stars', '')) !== '' && ctype_digit($v)) {
            $q->where('stars', (int) $v);
        }
        if ($request->query('active', '1') === '1') {
            $q->where('active', true);
        }
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($s, '\\%_').'%';
            $op = Fail::ilike();
            $q->where(fn ($w) => $w->where('name', $op, $like)->orWhere('city', $op, $like)->orWhere('destination', $op, $like));
        }
        $m = $this->manager($request);

        return $q->orderBy('destination')->orderBy('name')->limit(1000)->get()
            ->map(fn (Hotel $h) => $this->row($h, false, $m) + ['rooms_count' => $h->rooms()->count()])->values();
    }

    public function destinations()
    {
        return Hotel::query()->select('destination')->distinct()->orderBy('destination')->pluck('destination')->values();
    }

    public function show(Request $request, string $id)
    {
        $h = $this->find($id)->load(['photos', 'rooms.rates']);

        return $this->row($h, true, $this->manager($request));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';
        $d = Fail::validate(Fail::body($request), [
            'name' => "$req|string|max:200", 'destination' => "$req|string|max:120", 'city' => 'nullable|string|max:120',
            'stars' => 'nullable|integer|min:1|max:5', 'address' => 'nullable|string|max:600', 'phone' => 'nullable|string|max:60',
            'website' => 'nullable|string|max:255', 'google_rating' => 'nullable|numeric|min:0|max:5', 'google_reviews' => 'nullable|integer|min:0',
            'description' => 'nullable|string|max:4000', 'amenities' => 'nullable|array|max:40', 'amenities.*' => 'string|max:60',
            'margin_type' => 'nullable|in:percent,amount', 'margin_value' => 'nullable|numeric|min:0|max:10000000', 'active' => 'sometimes|boolean',
        ]);
        foreach (['name', 'destination', 'city', 'address', 'phone', 'website', 'description'] as $k) {
            if (array_key_exists($k, $d) && is_string($d[$k])) {
                $d[$k] = trim($d[$k]) === '' ? null : trim($d[$k]);
            }
        }
        if (isset($d['amenities'])) {
            $d['amenities'] = array_values(array_filter(array_map('trim', $d['amenities']), fn ($x) => $x !== ''));
        }
        if (array_key_exists('margin_type', $d) && ! $d['margin_type']) {
            $d['margin_type'] = null;
            $d['margin_value'] = null;
        }

        return $d;
    }

    public function store(Request $request)
    {
        $d = $this->validated($request);
        if (Hotel::whereRaw('lower(name) = ?', [mb_strtolower($d['name'])])->where('destination', $d['destination'])->exists()) {
            Fail::bad("{$d['name']} is already in the master for {$d['destination']}.");
        }
        $h = Hotel::create($d + ['created_by' => $request->user()->id]);
        ActivityLogger::log($request->user(), 'create_hotel', 'hotel', $h->id, null, ['name' => $h->name, 'destination' => $h->destination]);

        return $this->row($h->load(['photos', 'rooms.rates']), true, true);
    }

    public function update(Request $request, string $id)
    {
        $h = $this->find($id);
        $d = $this->validated($request, true);
        $before = $h->only(['name', 'destination', 'stars', 'margin_type', 'margin_value', 'active']);
        $h->fill($d)->save();
        ActivityLogger::log($request->user(), 'update_hotel', 'hotel', $h->id, $before, $h->only(array_keys($before)));

        return $this->row($h->fresh(['photos', 'rooms.rates']), true, true);
    }

    public function destroy(Request $request, string $id)
    {
        $h = $this->find($id);
        foreach ($h->photos as $p) {
            MediaStore::delete($p);
        }
        $name = $h->name;
        $h->delete();
        ActivityLogger::log($request->user(), 'delete_hotel', 'hotel', $id, ['name' => $name], null);

        return ['success' => true];
    }

    // ------------------------------------------------------------------ rooms and rates

    public function storeRoom(Request $request, string $id)
    {
        $h = $this->find($id);
        $d = Fail::validate(Fail::body($request), ['name' => 'required|string|max:200', 'max_adults' => 'nullable|integer|min:1|max:6', 'notes' => 'nullable|string|max:600']);
        if (HotelRoom::where('hotel_id', $h->id)->whereRaw('lower(name) = ?', [mb_strtolower(trim($d['name']))])->exists()) {
            Fail::bad('This hotel already has a room with that name.');
        }
        $r = HotelRoom::create(['hotel_id' => $h->id, 'name' => trim($d['name']), 'max_adults' => $d['max_adults'] ?? 2, 'notes' => $d['notes'] ?? null]);

        return ['id' => (string) $r->id, 'name' => $r->name, 'max_adults' => (int) $r->max_adults, 'notes' => $r->notes, 'active' => true, 'rates' => []];
    }

    public function updateRoom(Request $request, string $rid)
    {
        $r = $this->room($rid);
        $d = Fail::validate(Fail::body($request), ['name' => 'sometimes|required|string|max:200', 'max_adults' => 'nullable|integer|min:1|max:6', 'notes' => 'nullable|string|max:600', 'active' => 'sometimes|boolean']);
        $r->fill(array_filter($d, fn ($v) => $v !== null) + (array_key_exists('notes', $d) ? ['notes' => $d['notes']] : []))->save();

        return ['id' => (string) $r->id, 'name' => $r->name, 'max_adults' => (int) $r->max_adults, 'notes' => $r->notes, 'active' => (bool) $r->active];
    }

    public function destroyRoom(Request $request, string $rid)
    {
        $this->room($rid)->delete();

        return ['success' => true];
    }

    private function validRate(Request $request, ?HotelRate $existing, HotelRoom $room): array
    {
        $rules = ['meal_plan' => 'required|in:'.implode(',', array_keys(HotelRate::MEALS)), 'season' => 'nullable|string|max:80', 'start_date' => 'required|string', 'end_date' => 'required|string'];
        foreach (HotelRate::PRICES as $p) {
            $rules[$p] = 'nullable|numeric|min:0|max:100000000';
        }
        $d = Fail::validate(Fail::body($request), $rules);
        try {
            $d['start_date'] = Carbon::parse($d['start_date'])->toDateString();
            $d['end_date'] = Carbon::parse($d['end_date'])->toDateString();
        } catch (\Throwable) {
            Fail::unprocessable(['start_date' => 'Use dates like 2026-12-20']);
        }
        if ($d['end_date'] < $d['start_date']) {
            Fail::unprocessable(['end_date' => 'The end date is before the start date']);
        }
        if (($d['double'] ?? null) === null && ($d['single'] ?? null) === null) {
            Fail::unprocessable(['double' => 'Enter at least the double (or single) room rate']);
        }
        $clash = HotelRate::where('room_id', $room->id)->where('meal_plan', $d['meal_plan'])->when($existing, fn ($q) => $q->where('id', '<>', $existing->id))
            ->where('start_date', '<=', $d['end_date'])->where('end_date', '>=', $d['start_date'])->first();
        if ($clash) {
            Fail::bad(sprintf('This overlaps the %s rate of %s to %s for the same room and meal plan.', $clash->season ?: 'existing', $clash->start_date->format('d M Y'), $clash->end_date->format('d M Y')));
        }

        return $d;
    }

    public function storeRate(Request $request, string $rid)
    {
        $room = $this->room($rid);
        $r = HotelRate::create(['room_id' => $room->id] + $this->validRate($request, null, $room));
        ActivityLogger::log($request->user(), 'create_hotel_rate', 'hotel', $room->hotel_id, null, ['room' => $room->name, 'meal_plan' => $r->meal_plan]);

        return $this->rateRow($r);
    }

    public function updateRate(Request $request, string $rateId)
    {
        $r = HotelRate::with('room.hotel')->find((int) $rateId);
        if (! $r) {
            Fail::notFound('Rate not found');
        }
        $r->fill($this->validRate($request, $r, $r->room))->save();

        return $this->rateRow($r);
    }

    public function destroyRate(Request $request, string $rateId)
    {
        $r = HotelRate::find((int) $rateId);
        if (! $r) {
            Fail::notFound('Rate not found');
        }
        $r->delete();

        return ['success' => true];
    }

    /** GET /hotels/{id}/estimate: what a stay costs, the margin, and the selling price; a quick way to check the rates. */
    public function estimate(Request $request, string $id)
    {
        $h = $this->find($id);
        $room = HotelRoom::where('hotel_id', $h->id)->find((int) $request->query('room_id'));
        if (! $room) {
            Fail::unprocessable(['room_id' => 'Choose a room']);
        }
        $meal = (string) $request->query('meal_plan', 'CP');
        if (! isset(HotelRate::MEALS[$meal])) {
            Fail::unprocessable(['meal_plan' => 'Unknown meal plan']);
        }
        $in = (string) $request->query('check_in', '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $in)) {
            Fail::unprocessable(['check_in' => 'Use a date like 2026-12-20']);
        }

        return HotelPricing::estimate($room, $meal, $in, max(1, min(60, (int) $request->query('nights', 1))), max(1, min(40, (int) $request->query('adults', 2))),
            max(0, (int) $request->query('child_with_bed', 0)), max(0, (int) $request->query('child_no_bed', 0)));
    }

    // ------------------------------------------------------------------ photos

    public function addPhotos(Request $request, string $id)
    {
        $h = $this->find($id);
        $files = $request->file('files', []);
        $files = is_array($files) ? $files : [$files];
        if (! $files) {
            Fail::unprocessable(['files' => 'Choose at least one picture']);
        }
        $pos = (int) Media::where('hotel_id', $h->id)->max('position');
        $out = [];
        foreach (array_slice($files, 0, 15) as $f) {
            $m = MediaStore::store($f, ['hotel_id' => $h->id, 'destination' => $h->destination, 'kind' => 'hotel', 'position' => ++$pos], $request->user());
            $out[] = $m;
        }
        if (! Media::where('hotel_id', $h->id)->where('is_cover', true)->exists() && $out) {
            $out[0]->forceFill(['is_cover' => true])->save();           // the first picture is the cover until someone picks another
        }

        return array_map(fn (Media $m) => $m->fresh()->toApi(), $out);
    }

    // ------------------------------------------------------------------ import

    private const IMPORT_COLUMNS = ['hotel', 'destination', 'city', 'stars', 'room', 'max_adults', 'meal_plan', 'season', 'start_date', 'end_date', 'single', 'double', 'triple', 'extra_adult', 'child_with_bed', 'child_no_bed'];

    public function importTemplate()
    {
        $rows = [self::IMPORT_COLUMNS,
            ['Mareema Hotel Manali', 'Manali', 'Simsa', '3', 'Deluxe Room with Balcony View', '2', 'MAP', 'Peak', '2026-12-20', '2027-01-05', '', '4200', '5200', '1500', '1300', '700'],
            ['Mareema Hotel Manali', 'Manali', 'Simsa', '3', 'Deluxe Room with Balcony View', '2', 'MAP', 'Regular', '2027-01-06', '2027-03-31', '', '3200', '4000', '1200', '1000', '500']];
        $csv = "\xEF\xBB\xBF".implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', $c).'"', $r)), $rows))."\n";

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="hotel-rates-template.csv"']);
    }

    private function importDate(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {                 // an Excel date number
            return Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString();
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $v);
            } catch (\Throwable) {
                continue;                                                  // not this format; try the next
            }
            if ($d && $d->format($fmt) === $v) {
                return $d->toDateString();
            }
        }

        return null;
    }

    /** POST /hotels/import (file, dry_run): hotels, rooms and rates from a sheet. A dry run only reports what would happen. */
    public function import(Request $request)
    {
        $file = $request->file('file');
        $ext = strtolower((string) $file?->getClientOriginalExtension());
        if (! $file || ! in_array($ext, ['csv', 'xlsx'], true)) {
            Fail::unprocessable(['file' => 'Upload a .csv or .xlsx file (use the template).']);
        }
        $rows = SpreadsheetRows::read($file->getRealPath(), $ext);
        $head = array_map(fn ($h) => strtolower(trim(str_replace([' ', '-'], '_', (string) $h))), array_shift($rows) ?? []);
        foreach (['hotel', 'destination', 'room', 'meal_plan', 'start_date', 'end_date'] as $need) {
            if (! in_array($need, $head, true)) {
                Fail::unprocessable(['file' => "The sheet needs a \"$need\" column. Use the template."]);
            }
        }
        $dry = filter_var($request->input('dry_run', false), FILTER_VALIDATE_BOOL);
        $errors = [];
        $seen = ['hotels' => [], 'rooms' => [], 'rates' => 0, 'updated' => 0];
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
            $meal = strtoupper($r['meal_plan'] ?? '');
            $start = $this->importDate($r['start_date'] ?? '');
            $end = $this->importDate($r['end_date'] ?? '');
            $num = fn (string $k) => ($r[$k] ?? '') === '' ? null : (is_numeric(str_replace(',', '', $r[$k])) ? (float) str_replace(',', '', $r[$k]) : false);
            $prices = [];
            $bad = null;
            foreach (HotelRate::PRICES as $p) {
                $v = $num($p);
                if ($v === false || ($v !== null && $v < 0)) {
                    $bad = "$p is not a number";
                }
                $prices[$p] = $v === false ? null : $v;
            }
            if (($r['hotel'] ?? '') === '' || ($r['destination'] ?? '') === '' || ($r['room'] ?? '') === '') {
                $bad = 'hotel, destination and room are needed';
            } elseif (! isset(HotelRate::MEALS[$meal])) {
                $bad = 'meal_plan must be EP, CP, MAP or AP';
            } elseif (! $start || ! $end || $end < $start) {
                $bad = 'check the start and end dates (use 2026-12-20)';
            } elseif ($prices['double'] === null && $prices['single'] === null) {
                $bad = 'enter the double (or single) rate';
            }
            if ($bad) {
                $errors[] = ['row' => $line, 'error' => $bad];
                continue;
            }
            $plan[] = ['row' => $line, 'r' => $r, 'meal' => $meal, 'start' => $start, 'end' => $end, 'prices' => $prices];
        }

        DB::beginTransaction();
        try {
            foreach ($plan as $p) {
                $r = $p['r'];
                $h = Hotel::whereRaw('lower(name) = ?', [mb_strtolower($r['hotel'])])->where('destination', $r['destination'])->first();
                if (! $h) {
                    $h = Hotel::create(['name' => $r['hotel'], 'destination' => $r['destination'], 'city' => ($r['city'] ?? '') ?: null,
                        'stars' => ctype_digit($r['stars'] ?? '') && (int) $r['stars'] >= 1 && (int) $r['stars'] <= 5 ? (int) $r['stars'] : null, 'created_by' => $request->user()->id]);
                    $seen['hotels'][$h->id] = true;
                }
                $room = HotelRoom::where('hotel_id', $h->id)->whereRaw('lower(name) = ?', [mb_strtolower($r['room'])])->first()
                    ?: HotelRoom::create(['hotel_id' => $h->id, 'name' => $r['room'], 'max_adults' => ctype_digit($r['max_adults'] ?? '') && (int) $r['max_adults'] > 0 ? min(6, (int) $r['max_adults']) : 2]);
                $seen['rooms'][$room->id] = true;
                $clash = HotelRate::where('room_id', $room->id)->where('meal_plan', $p['meal'])->where('start_date', '<=', $p['end'])->where('end_date', '>=', $p['start'])->get();
                $same = $clash->first(fn ($x) => $x->start_date->toDateString() === $p['start'] && $x->end_date->toDateString() === $p['end']);
                if ($clash->count() > 1 || ($clash->count() === 1 && ! $same)) {
                    $errors[] = ['row' => $p['row'], 'error' => 'the dates overlap another season of the same room and meal plan'];
                    continue;
                }
                $data = ['meal_plan' => $p['meal'], 'season' => ($r['season'] ?? '') ?: null, 'start_date' => $p['start'], 'end_date' => $p['end']] + $p['prices'];
                if ($same) {
                    $same->fill($data)->save();
                    $seen['updated']++;
                } else {
                    HotelRate::create(['room_id' => $room->id] + $data);
                    $seen['rates']++;
                }
            }
            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        if (! $dry) {
            ActivityLogger::log($request->user(), 'import_hotels', 'hotel', '', null, ['hotels_new' => count($seen['hotels']), 'rates_new' => $seen['rates'], 'rates_updated' => $seen['updated']]);
        }

        return ['dry_run' => $dry, 'hotels_new' => count($seen['hotels']), 'rooms_seen' => count($seen['rooms']), 'rates_new' => $seen['rates'], 'rates_updated' => $seen['updated'], 'errors' => $errors];
    }
}
