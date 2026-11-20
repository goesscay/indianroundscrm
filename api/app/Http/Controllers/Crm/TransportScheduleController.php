<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\TransportSchedule;
use App\Services\SpreadsheetRows;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Flights and trains: scheduled services with their times, running days and classes. Details only, no price anywhere.
 * Everyone with transport.view sees them (to suggest them on a quotation); transport.manage keeps the list.
 */
class TransportScheduleController extends Controller
{
    private function find(string $id): TransportSchedule
    {
        $s = ctype_digit($id) ? TransportSchedule::find((int) $id) : null;
        if (! $s) {
            Fail::notFound('Flight or train not found');
        }

        return $s;
    }

    private function like(string $s): string
    {
        return '%'.addcslashes(trim($s), '\\%_').'%';
    }

    /** GET /transport/schedules?kind&q&route&from&to&date&stale&active */
    public function index(Request $request)
    {
        $q = TransportSchedule::query();
        if (in_array($k = (string) $request->query('kind', ''), TransportSchedule::KINDS, true)) {
            $q->where('kind', $k);
        }
        if ($request->query('active', '1') === '1') {
            $q->where('active', true);
        }
        $op = Fail::ilike();
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $like = $this->like($s);
            $q->where(fn ($w) => $w->where('title', $op, $like)->orWhere('from_name', $op, $like)->orWhere('to_name', $op, $like)->orWhere('from_code', $op, $like)->orWhere('to_code', $op, $like)->orWhere('serves', $op, $like));
        }
        if (($r = (string) $request->query('route', '')) !== '' && str_contains($r, ' → ')) {
            [$a, $b] = explode(' → ', $r, 2);
            $q->where('from_name', $a)->where('to_name', $b);
        }
        // Going: it starts where the customer starts and ends at, or near ("serves"), the destination.
        // Return: the other way round: it starts at, or near, the destination and ends where the customer started.
        $return = $request->query('direction') === 'return';
        if (($f = trim((string) $request->query('from', ''))) !== '') {
            $like = $this->like($f);
            $q->where(fn ($w) => $w->where('from_name', $op, $like)->orWhere('from_code', $op, $like)->when($return, fn ($x) => $x->orWhere('serves', $op, $like)));
        }
        if (($t = trim((string) $request->query('to', ''))) !== '') {
            $like = $this->like($t);
            $q->where(fn ($w) => $w->where('to_name', $op, $like)->orWhere('to_code', $op, $like)->when(! $return, fn ($x) => $x->orWhere('serves', $op, $like)));
        }
        $rows = $q->orderBy('kind')->orderBy('from_name')->orderBy('to_name')->orderBy('departs')->limit(2000)->get();
        if (($date = (string) $request->query('date', '')) !== '' && ($day = TransportSchedule::dayKey($date))) {
            $rows = $rows->filter(fn ($x) => in_array($day, (array) $x->days, true))->values();       // only the services that run on that day
        }
        $out = $rows->map(fn ($x) => $x->toApi());
        if ($request->query('stale') === '1') {
            $out = $out->filter(fn ($x) => $x['needs_update'])->values();
        }

        return $out->values();
    }

    /** GET /transport/schedules/routes?kind: the distinct routes, for the "All routes" filter. */
    public function routes(Request $request)
    {
        return TransportSchedule::query()->when(in_array($k = (string) $request->query('kind', ''), TransportSchedule::KINDS, true), fn ($q) => $q->where('kind', $k))
            ->where('active', true)->select('from_name', 'to_name')->distinct()->orderBy('from_name')->orderBy('to_name')->get()->map(fn ($r) => $r->from_name.' → '.$r->to_name)->values();
    }

    /** GET /transport/schedules/origins: where people start from, for the quotation's From box. */
    public function origins()
    {
        return TransportSchedule::query()->where('active', true)->distinct()->orderBy('from_name')->pluck('from_name')->values();
    }

    private function valid(Request $request, bool $partial, ?string $kind = null): array
    {
        $req = $partial ? 'sometimes|required' : 'required';
        $time = ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        $d = Fail::validate(Fail::body($request), [
            'kind' => "$req|in:".implode(',', TransportSchedule::KINDS), 'title' => "$req|string|max:200", 'from_name' => "$req|string|max:120", 'from_code' => 'nullable|string|max:12',
            'to_name' => "$req|string|max:120", 'to_code' => 'nullable|string|max:12', 'serves' => 'nullable|string|max:255', 'departs' => $time, 'arrives' => $time,
            'arrives_next_day' => 'sometimes|boolean', 'days' => "$req|array|min:1|max:7", 'days.*' => 'in:'.implode(',', array_keys(TransportSchedule::DAYS)),
            'classes' => 'nullable|string|max:120', 'note' => 'nullable|string|max:600', 'active' => 'sometimes|boolean',
        ]);
        foreach (['title', 'from_name', 'from_code', 'to_name', 'to_code', 'serves', 'classes', 'note'] as $k) {
            if (array_key_exists($k, $d) && is_string($d[$k])) {
                $d[$k] = trim($d[$k]) === '' ? null : trim($d[$k]);
            }
        }
        foreach (['from_code', 'to_code'] as $k) {
            if (! empty($d[$k])) {
                $d[$k] = strtoupper($d[$k]);
            }
        }
        if (isset($d['days'])) {
            $d['days'] = array_values(array_intersect(array_keys(TransportSchedule::DAYS), $d['days']));       // in week order, no repeats
        }

        return $d;
    }

    public function store(Request $request)
    {
        $d = $this->valid($request, false);
        $s = TransportSchedule::create($d + ['created_by' => $request->user()->id]);
        ActivityLogger::log($request->user(), 'create_transport_schedule', 'transport', $s->id, null, ['kind' => $s->kind, 'title' => $s->title]);

        return $s->toApi();
    }

    public function update(Request $request, string $id)
    {
        $s = $this->find($id);
        $d = $this->valid($request, true);
        $before = $s->only(['kind', 'title', 'from_name', 'to_name', 'departs', 'arrives', 'days', 'active']);
        $s->fill($d)->save();
        $s->touch();                                                                    // saving a review without a change still counts as "updated"
        ActivityLogger::log($request->user(), 'update_transport_schedule', 'transport', $s->id, $before, $s->only(array_keys($before)));

        return $s->toApi();
    }

    public function duplicate(Request $request, string $id)
    {
        $s = $this->find($id);
        $copy = $s->replicate(['created_at', 'updated_at']);
        $copy->title = $s->title.' (copy)';
        $copy->created_by = $request->user()->id;
        $copy->save();

        return $copy->toApi();
    }

    public function destroy(Request $request, string $id)
    {
        $s = $this->find($id);
        $title = $s->title;
        $s->delete();
        ActivityLogger::log($request->user(), 'delete_transport_schedule', 'transport', $id, ['title' => $title], null);

        return ['success' => true];
    }

    // ------------------------------------------------------------------ import

    private const IMPORT_COLUMNS = ['type', 'name', 'from', 'from_code', 'to', 'to_code', 'serves', 'departs', 'arrives', 'next_day', 'days', 'classes', 'note'];

    public function importTemplate()
    {
        $rows = [self::IMPORT_COLUMNS,
            ['flight', 'Air India AI 123', 'Delhi', 'DEL', 'Kullu-Manali', 'KUU', 'Manali', '09:10', '10:35', '', 'Daily', 'Economy', 'The airport is about 40 km from Manali. We can arrange a cab.'],
            ['train', 'Shatabdi 12011', 'New Delhi', 'NDLS', 'Kalka', 'KLK', 'Shimla, Manali', '07:40', '11:45', '', 'Daily except Sun', 'CC, EC', 'Arrives at Kalka. Taxi or bus onward to Shimla or Manali.'],
            ['train', 'Example overnight train', 'Delhi', '', 'Jammu', '', '', '22:00', '06:30', 'yes', 'Mon, Wed, Fri', 'SL, 3A', '']];
        $csv = "\xEF\xBB\xBF".implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', $c).'"', $r)), $rows))."\n";

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="flights-trains-template.csv"']);
    }

    /** "Daily", "Daily except Sun, Fri", "Mon, Wed" (or full day names) into the day keys; null if it cannot be read. @return list<string>|null */
    public static function parseDays(string $v): ?array
    {
        $v = strtolower(trim($v));
        $all = array_keys(TransportSchedule::DAYS);
        if ($v === '' || $v === 'daily' || $v === 'every day' || $v === 'all') {
            return $all;
        }
        $pick = function (string $s) use ($all): ?array {
            $out = [];
            foreach (preg_split('/[\s,;\/&]+/', $s, -1, PREG_SPLIT_NO_EMPTY) as $w) {
                if ($w === 'and') {
                    continue;
                }
                $k = substr($w, 0, 3);
                if (! in_array($k, $all, true)) {
                    return null;
                }
                $out[] = $k;
            }

            return $out ?: null;
        };
        if (preg_match('/^(daily|every day)\s+except\s+(.+)$/', $v, $m)) {
            $ex = $pick($m[2]);

            return $ex === null ? null : array_values(array_diff($all, $ex));
        }
        $d = $pick($v);

        return $d === null ? null : array_values(array_intersect($all, $d));
    }

    /** POST /transport/schedules/import (file, dry_run): flights and trains from a sheet. A dry run only reports what would happen. */
    public function import(Request $request)
    {
        $file = $request->file('file');
        $ext = strtolower((string) $file?->getClientOriginalExtension());
        if (! $file || ! in_array($ext, ['csv', 'xlsx'], true)) {
            Fail::unprocessable(['file' => 'Upload a .csv or .xlsx file (use the template).']);
        }
        $rows = SpreadsheetRows::read($file->getRealPath(), $ext);
        $head = array_map(fn ($h) => strtolower(trim(str_replace([' ', '-'], '_', (string) $h))), array_shift($rows) ?? []);
        foreach (['type', 'name', 'from', 'to', 'days'] as $need) {
            if (! in_array($need, $head, true)) {
                Fail::unprocessable(['file' => "The sheet needs a \"$need\" column. Use the template."]);
            }
        }
        $dry = filter_var($request->input('dry_run', false), FILTER_VALIDATE_BOOL);
        $errors = [];
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
            $kind = strtolower($r['type'] ?? '');
            $days = self::parseDays($r['days'] ?? '');
            $bad = null;
            if (! in_array($kind, TransportSchedule::KINDS, true)) {
                $bad = 'type must be flight or train';
            } elseif (($r['name'] ?? '') === '' || ($r['from'] ?? '') === '' || ($r['to'] ?? '') === '') {
                $bad = 'name, from and to are needed';
            } elseif ($days === null) {
                $bad = 'days: use Daily, Daily except Sun, or a list like Mon, Wed, Fri';
            } else {
                foreach (['departs', 'arrives'] as $t) {
                    if (($r[$t] ?? '') !== '' && ! preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $r[$t])) {
                        $bad = "$t must be a time like 07:40";
                    }
                }
            }
            if ($bad) {
                $errors[] = ['row' => $line, 'error' => $bad];
                continue;
            }
            $time = fn ($t) => $t === '' ? null : str_pad($t, 5, '0', STR_PAD_LEFT);
            $plan[] = ['kind' => $kind, 'title' => $r['name'], 'from_name' => $r['from'], 'from_code' => ($r['from_code'] ?? '') !== '' ? strtoupper($r['from_code']) : null,
                'to_name' => $r['to'], 'to_code' => ($r['to_code'] ?? '') !== '' ? strtoupper($r['to_code']) : null, 'serves' => ($r['serves'] ?? '') ?: null,
                'departs' => $time($r['departs'] ?? ''), 'arrives' => $time($r['arrives'] ?? ''), 'arrives_next_day' => in_array(strtolower($r['next_day'] ?? ''), ['yes', 'y', '1', 'true'], true),
                'days' => $days, 'classes' => ($r['classes'] ?? '') ?: null, 'note' => ($r['note'] ?? '') ?: null];
        }

        $new = $updated = 0;
        DB::beginTransaction();
        try {
            foreach ($plan as $p) {
                $same = TransportSchedule::where('kind', $p['kind'])->whereRaw('lower(title) = ?', [mb_strtolower($p['title'])])->where('from_name', $p['from_name'])->where('to_name', $p['to_name'])->first();
                if ($same) {
                    $same->fill($p)->save();
                    $same->touch();
                    $updated++;
                } else {
                    TransportSchedule::create($p + ['created_by' => $request->user()->id]);
                    $new++;
                }
            }
            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        if (! $dry) {
            ActivityLogger::log($request->user(), 'import_transport_schedules', 'transport', '', null, ['new' => $new, 'updated' => $updated]);
        }

        return ['dry_run' => $dry, 'new' => $new, 'updated' => $updated, 'errors' => $errors];
    }
}
