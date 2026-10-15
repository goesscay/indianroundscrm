<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\PackageDay;
use App\Models\PackageSeason;
use App\Models\TravelPackage;
use App\Models\User;
use App\Services\WebsiteTours;
use App\Support\ActivityLogger;
use App\Support\Numbers;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PackageController extends Controller
{
    private function find(string $id, ?User $viewer = null): TravelPackage
    {
        $q = TravelPackage::with(['seasons', 'itineraryDays', 'departures']);
        if ($viewer) {
            $q->visibleTo($viewer);   // someone who cannot see a package gets "not found", like a package that does not exist
        }
        $p = ctype_digit($id) ? $q->find((int) $id) : null;
        if (! $p) {
            Fail::notFound('Package not found');
        }

        return $p;
    }

    private function validated(array $b, bool $partial): array
    {
        $req = $partial ? 'sometimes|' : '';

        return Fail::validate($b, [
            'name' => ($partial ? 'sometimes|required' : 'required').'|string|max:200',
            'code' => 'nullable|string|max:40',
            'destination' => 'nullable|string|max:200',
            'category' => $req.'nullable|in:'.implode(',', TravelPackage::CATEGORIES),
            'nights' => 'nullable|integer|min:0|max:365', 'days' => 'nullable|integer|min:0|max:366',
            'summary' => 'nullable|string', 'inclusions' => 'nullable|string', 'exclusions' => 'nullable|string', 'terms' => 'nullable|string',
            'adult_price' => 'nullable|numeric|min:0', 'child_price' => 'nullable|numeric|min:0',
            'adult_cost' => 'nullable|numeric|min:0', 'child_cost' => 'nullable|numeric|min:0',
            'gst_rate' => 'nullable|numeric|min:0|max:100',
            'active' => 'sometimes|boolean',
            'seasons' => 'sometimes|nullable|array|max:40', 'itinerary' => 'sometimes|nullable|array|max:60',
        ]);
    }

    private function date(mixed $v, string $field): string
    {
        $v = is_scalar($v) ? substr((string) $v, 0, 10) : '';
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

    private function txt(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return $v === '' ? null : $v;
    }

    private function sync(TravelPackage $p, array $d): void
    {
        if (array_key_exists('seasons', $d)) {
            $p->seasons()->delete();
            foreach ((array) $d['seasons'] as $i => $s) {
                $name = is_array($s) ? trim((string) ($s['name'] ?? '')) : '';
                if ($name === '') {
                    continue;
                }
                $start = $this->date($s['start_date'] ?? null, "seasons.$i.start_date");
                $end = $this->date($s['end_date'] ?? null, "seasons.$i.end_date");
                if ($end < $start) {
                    Fail::unprocessable(["seasons.$i.end_date" => 'End date is before the start date']);
                }
                PackageSeason::create([
                    'package_id' => $p->id, 'name' => mb_substr($name, 0, 120), 'start_date' => $start, 'end_date' => $end,
                    'adult_price' => max(0, (float) ($s['adult_price'] ?? 0)), 'child_price' => max(0, (float) ($s['child_price'] ?? 0)),
                    'adult_cost' => max(0, (float) ($s['adult_cost'] ?? 0)), 'child_cost' => max(0, (float) ($s['child_cost'] ?? 0)),
                ]);
            }
        }
        if (array_key_exists('itinerary', $d)) {
            $p->itineraryDays()->delete();
            $n = 0;
            foreach ((array) $d['itinerary'] as $day) {
                $title = is_array($day) ? trim((string) ($day['title'] ?? '')) : '';
                if ($title === '') {
                    continue;
                }
                PackageDay::create(['package_id' => $p->id, 'day_no' => ++$n, 'title' => mb_substr($title, 0, 200), 'description' => $this->txt($day['description'] ?? null)]);
            }
        }
    }

    private function fill(TravelPackage $p, array $d): void
    {
        foreach (['name', 'destination', 'summary', 'inclusions', 'exclusions', 'terms'] as $f) {
            if (array_key_exists($f, $d)) {
                $p->{$f} = $f === 'name' ? trim((string) $d[$f]) : $this->txt($d[$f]);
            }
        }
        foreach (['category'] as $f) {
            if (! empty($d[$f])) {
                $p->{$f} = $d[$f];
            }
        }
        foreach (['nights', 'days'] as $f) {
            if (array_key_exists($f, $d)) {
                $p->{$f} = (int) ($d[$f] ?? 0);
            }
        }
        foreach (['adult_price', 'child_price', 'adult_cost', 'child_cost'] as $f) {
            if (array_key_exists($f, $d)) {
                $p->{$f} = (float) ($d[$f] ?? 0);
            }
        }
        if (array_key_exists('gst_rate', $d) && $d['gst_rate'] !== null) {
            $p->gst_rate = (float) $d['gst_rate'];
        }
        if (array_key_exists('active', $d)) {
            $p->active = (bool) $d['active'];
        }
    }

    // ------------------------------------------------------------------ routes

    public function index(Request $request)
    {
        $q = TravelPackage::query()->visibleTo($request->user());
        if ($request->user()->hasPermission('packages.manage')) {
            $q->withCount('assignees');
        }
        if (($v = (string) $request->query('category', '')) !== '') {
            $q->where('category', $v);
        }
        if (($v = (string) $request->query('destination', '')) !== '') {
            $q->whereIn('destination', array_values(array_filter(explode('|', $v), fn ($x) => $x !== '')));   // one, or several joined with |
        }
        $active = (string) $request->query('active', '');
        if ($active === '1' || $active === '0') {
            $q->where('active', $active === '1');
        }
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($s, '\\%_').'%';
            $op = Fail::ilike();
            $q->where(fn ($w) => $w->where('name', $op, $like)->orWhere('code', $op, $like)->orWhere('destination', $op, $like));
        }
        $q->orderBy('name')->orderBy('id');

        if (Paging::requested($request)) {
            return Paging::paginate($request, $q, fn (TravelPackage $p) => $p->toApi());
        }
        $limit = max(1, (int) $request->query('limit', 500));

        return $q->limit($limit)->get()->map(fn (TravelPackage $p) => $p->toApi())->values();
    }

    public function options(Request $request)
    {
        $out = [
            'categories' => TravelPackage::CATEGORIES,
            'destinations' => TravelPackage::query()->visibleTo($request->user())->whereNotNull('destination')->where('destination', '!=', '')->distinct()->orderBy('destination')->pluck('destination'),
        ];
        if ($request->user()->hasPermission('packages.manage')) {
            $out['website'] = [
                'packages' => TravelPackage::where('source', 'website')->count(),
                'unlocked' => TravelPackage::where('source', 'website')->where('source_unlocked', true)->count(),
                'synced_at' => TravelPackage::where('source', 'website')->max('source_synced_at'),
                'url' => WebsiteTours::baseUrl(),
            ];
        }

        return $out;
    }

    public function show(Request $request, string $id)
    {
        $p = $this->find($id, $request->user());
        $out = $p->toApi(true);
        if ($request->user()->hasPermission('packages.manage')) {
            $out['assignees'] = $p->assignees()->orderBy('name')->get(['users.id', 'users.name'])->map(fn ($u) => ['id' => (string) $u->id, 'name' => $u->name])->values()->all();
        }

        return $out;
    }

    public function price(Request $request, string $id)
    {
        $date = (string) $request->query('date', '');
        $p = $this->find($id, $request->user());

        return $p->ratesFor(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $d = $this->validated(Fail::body($request), false);
        $code = $this->txt($d['code'] ?? null);
        if ($code !== null && TravelPackage::where('code', $code)->exists()) {
            Fail::http(409, "Package code $code is already used.");
        }

        $p = DB::transaction(function () use ($d, $code, $user) {
            $p = new TravelPackage(['code' => $code ?? Numbers::next('package_seq', 'PKG-', 4), 'created_by' => $user->id, 'category' => 'domestic']);
            $this->fill($p, $d);
            $p->save();
            $this->sync($p, $d);

            return $p;
        });
        ActivityLogger::log($user, 'create_package', 'package', $p->id, null, ['code' => $p->code, 'name' => $p->name]);

        return $this->find((string) $p->id)->toApi(true);
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        $p = $this->find($id);
        $d = $this->validated(Fail::body($request), true);
        $d = array_diff_key($d, array_flip($p->lockedFields()));   // what the website decides cannot be changed here (unlock the package first)
        $code = array_key_exists('code', $d) ? $this->txt($d['code']) : null;
        if ($code !== null && $code !== $p->code) {
            if (TravelPackage::where('code', $code)->where('id', '!=', $p->id)->exists()) {
                Fail::http(409, "Package code $code is already used.");
            }
            $p->code = $code;
        }
        $before = ['name' => $p->name, 'adult_price' => $p->adult_price, 'child_price' => $p->child_price, 'active' => $p->active];
        DB::transaction(function () use ($p, $d) {
            $this->fill($p, $d);
            $p->save();
            $this->sync($p, $d);
        });
        ActivityLogger::log($user, 'update_package', 'package', $p->id, $before,
            ['name' => $p->name, 'adult_price' => $p->adult_price, 'child_price' => $p->child_price, 'active' => $p->active]);

        return $this->find($id)->toApi(true);
    }

    public function destroy(Request $request, string $id)
    {
        $p = $this->find($id);
        if ($p->source === 'website' && ! $p->source_unlocked) {
            Fail::http(409, 'This package comes from the website and would come back at the next sync. Make it inactive instead, or unlock it first.');
        }
        $p->delete(); // quotes keep their own copy of the lines and itinerary; their package link is cleared
        ActivityLogger::log($request->user(), 'delete_package', 'package', $id, ['code' => $p->code, 'name' => $p->name], null);

        return ['ok' => true];
    }

    // ------------------------------------------------------------------ website sync

    /** POST /packages/sync-website */
    public function syncWebsite(Request $request)
    {
        try {
            $r = app(WebsiteTours::class)->run();
        } catch (\Throwable $e) {
            Fail::http(502, 'The website could not be read: '.$e->getMessage());
        }
        ActivityLogger::log($request->user(), 'sync_website_packages', 'package', 'website', null, $r);

        return $r;
    }

    /** POST /packages/{id}/sync-lock {unlocked: bool}: take a website package over (the sync leaves it alone) or hand it back. */
    public function syncLock(Request $request, string $id)
    {
        $p = $this->find($id);
        if ($p->source !== 'website') {
            Fail::http(409, 'Only packages that come from the website have a sync lock.');
        }
        $d = Fail::validate(Fail::body($request), ['unlocked' => 'required|boolean']);
        $p->forceFill(['source_unlocked' => (bool) $d['unlocked']])->save();
        ActivityLogger::log($request->user(), $d['unlocked'] ? 'unlock_website_package' : 'relock_website_package', 'package', $p->id, null, ['code' => $p->code]);

        return $this->find($id)->toApi(true);
    }

    // ------------------------------------------------------------------ who sees which package

    /** People a package can be assigned to: those who use packages but do not already see them all. */
    private function assignable(): \Illuminate\Support\Collection
    {
        return User::where('active', true)->where('account_status', 'active')->orderBy('name')->get()
            ->filter(fn (User $u) => ! $u->hasAnyPermission('packages.view_all', 'packages.manage') && $u->hasAnyPermission('packages.view', 'quotes.create'));
    }

    /** GET /packages/assignable-users */
    public function assignableUsers()
    {
        return $this->assignable()->map(fn (User $u) => [
            'id' => (string) $u->id, 'name' => $u->name, 'code' => $u->consultant_code ?: ($u->staff_code ?: ''), 'destinations' => $u->destinations ?? [],
        ])->values();
    }

    /** PUT /packages/{id}/assignees {user_ids: [...]}: exactly these people see the package (besides Operations and Admin). */
    public function setAssignees(Request $request, string $id)
    {
        $p = $this->find($id);
        $d = Fail::validate(Fail::body($request), ['user_ids' => 'present|array|max:500', 'user_ids.*' => 'required']);
        $ok = $this->assignable()->pluck('id')->map(fn ($i) => (int) $i)->all();
        $want = array_values(array_intersect(array_map(fn ($i) => (int) $i, $d['user_ids']), $ok));
        $have = DB::table('package_user')->where('package_id', $p->id)->pluck('user_id')->map(fn ($i) => (int) $i)->all();
        DB::table('package_user')->where('package_id', $p->id)->whereIn('user_id', array_diff($have, $want))->delete();
        $add = array_diff($want, $have);
        if ($add) {
            DB::table('package_user')->insert(array_map(fn ($uid) => ['package_id' => $p->id, 'user_id' => $uid, 'assigned_by' => $request->user()->id, 'created_at' => now()], array_values($add)));
        }
        ActivityLogger::log($request->user(), 'assign_package', 'package', $p->id, null, ['users' => $want]);

        return ['assignees' => User::whereIn('id', $want)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => (string) $u->id, 'name' => $u->name])->values()->all()];
    }

    /**
     * POST /packages/assign {user_ids, package_ids?, destinations?, mode: add|remove}
     * Give (or take away) several packages from several people at once; "destinations" picks every package of those destinations.
     */
    public function assign(Request $request)
    {
        $d = Fail::validate(Fail::body($request), [
            'user_ids' => 'required|array|min:1|max:200', 'user_ids.*' => 'required',
            'package_ids' => 'nullable|array|max:1000', 'package_ids.*' => 'required',
            'destinations' => 'nullable|array|max:50', 'destinations.*' => 'required|string|max:200',
            'mode' => 'required|in:add,remove',
        ]);
        if (empty($d['package_ids']) && empty($d['destinations'])) {
            Fail::unprocessable(['package_ids' => 'Choose packages or destinations']);
        }
        $users = array_values(array_intersect(array_map(fn ($i) => (int) $i, $d['user_ids']), $this->assignable()->pluck('id')->map(fn ($i) => (int) $i)->all()));
        $q = TravelPackage::query();
        $q->where(function ($w) use ($d) {
            if (! empty($d['package_ids'])) {
                $w->orWhereIn('id', array_map(fn ($i) => (int) $i, $d['package_ids']));
            }
            if (! empty($d['destinations'])) {
                $w->orWhereIn(DB::raw('lower(destination)'), array_map(fn ($x) => mb_strtolower(trim($x)), $d['destinations']));
            }
        });
        $packages = $q->pluck('id')->all();
        $changed = 0;
        if ($users && $packages) {
            if ($d['mode'] === 'remove') {
                $changed = DB::table('package_user')->whereIn('package_id', $packages)->whereIn('user_id', $users)->delete();
            } else {
                foreach ($users as $uid) {
                    $have = DB::table('package_user')->where('user_id', $uid)->whereIn('package_id', $packages)->pluck('package_id')->all();
                    $rows = array_map(fn ($pid) => ['package_id' => $pid, 'user_id' => $uid, 'assigned_by' => $request->user()->id, 'created_at' => now()], array_values(array_diff($packages, $have)));
                    foreach (array_chunk($rows, 500) as $chunk) {
                        DB::table('package_user')->insert($chunk);
                    }
                    $changed += count($rows);
                }
            }
        }
        ActivityLogger::log($request->user(), $d['mode'] === 'add' ? 'assign_packages' : 'unassign_packages', 'package', 'bulk', null, ['packages' => count($packages), 'users' => count($users), 'changed' => $changed]);

        return ['packages' => count($packages), 'users' => count($users), 'changed' => $changed];
    }
}
