<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Quarterly sales forecast on the Indian financial year (Apr-Mar).
 * Won deals count in the quarter of their close date (closed_at, IST); open deals in the quarter of their
 * expected close date. Open deals past their expected close are flagged, not hidden.
 */
class ForecastController extends Controller
{
    private const TZ = 'Asia/Kolkata';
    private const MONTHS = [1 => 'Apr–Jun', 2 => 'Jul–Sep', 3 => 'Oct–Dec', 4 => 'Jan–Mar'];

    // ------------------------------------------------------------------ helpers

    private static function today(): Carbon
    {
        return Carbon::now(self::TZ)->startOfDay();
    }

    /** FY start year for a date (Apr-Dec -> same year; Jan-Mar -> previous year). */
    public static function fyOf(Carbon $d): int
    {
        return $d->month >= 4 ? $d->year : $d->year - 1;
    }

    /** Quarter 1..4 within the FY for a date. */
    public static function quarterOf(Carbon $d): int
    {
        return intdiv(($d->month + 8) % 12, 3) + 1;
    }

    /** @return array{0:Carbon,1:Carbon} inclusive first and last day of a quarter */
    private static function range(int $fy, int $q): array
    {
        $start = Carbon::create($fy, 4, 1, 0, 0, 0, self::TZ)->addMonthsNoOverflow(($q - 1) * 3);

        return [$start, $start->copy()->addMonthsNoOverflow(3)->subDay()];
    }

    private function fy(Request $request): int
    {
        $v = (string) $request->query('fy', '');
        $fy = ctype_digit($v) ? (int) $v : self::fyOf(self::today());
        if ($fy < 2000 || $fy > 2100) {
            Fail::unprocessable(['fy' => 'Invalid financial year']);
        }

        return $fy;
    }

    /** owner filter -> null (everyone) | user id. Without forecasts.view_all the caller only ever sees their own. */
    private function ownerFilter(Request $request, User $user): ?int
    {
        if (! $user->hasPermission('forecasts.view_all')) {
            return (int) $user->id;
        }
        $o = (string) $request->query('owner', 'all');
        if ($o === 'me') {
            return (int) $user->id;
        }

        return ctype_digit($o) ? (int) $o : null;
    }

    private function deals(?int $owner)
    {
        $q = Deal::query()->with(['owner:id,name', 'customer:id,customer_no,name']);
        if ($owner !== null) {
            $q->where('deals.owner_id', $owner);
        }

        return $q;
    }

    /** Quarter (1..4) of a deal for the FY, plus which bucket it falls into; null when it is outside this FY. */
    private function bucket(Deal $d, int $fy, Carbon $today): ?array
    {
        if ($d->stage === 'won') {
            $when = $d->closed_at?->copy()->timezone(self::TZ);
            if (! $when || self::fyOf($when) !== $fy) {
                return null;
            }

            return ['kind' => 'won', 'q' => self::quarterOf($when)];
        }
        if ($d->stage === 'lost') {
            return null;
        }
        if (! $d->expected_close) {
            return ['kind' => 'undated', 'q' => null];
        }
        $when = Carbon::parse($d->expected_close->format('Y-m-d'), self::TZ);
        if (self::fyOf($when) !== $fy) {
            return null;
        }

        return ['kind' => 'open', 'q' => self::quarterOf($when), 'overdue' => $when->lt($today)];
    }

    /** Targets keyed [quarter] for the chosen scope (team = user 0, owner = that user, everyone = team row or sum of owners). */
    private function targets(int $fy, ?int $owner): array
    {
        $rows = DB::table('forecast_targets')->where('fy', $fy)->get();
        $out = [];
        for ($q = 1; $q <= 4; $q++) {
            $qr = $rows->where('quarter', $q);
            if ($owner !== null) {
                $r = $qr->firstWhere('user_id', $owner);
            } else {
                $r = $qr->firstWhere('user_id', 0);
                if (! $r && $qr->isNotEmpty()) { // no team figure set: show the sum of the consultants' targets
                    $r = (object) ['amount' => $qr->sum('amount'), 'margin_amount' => $qr->whereNotNull('margin_amount')->sum('margin_amount') ?: null];
                }
            }
            $out[$q] = ['amount' => (float) ($r->amount ?? 0), 'margin' => isset($r->margin_amount) ? (float) $r->margin_amount : null];
        }

        return $out;
    }

    // ------------------------------------------------------------------ routes

    public function show(Request $request)
    {
        $user = $request->user();
        $fy = $this->fy($request);
        $owner = $this->ownerFilter($request, $user);
        $today = self::today();
        $all = $user->hasPermission('forecasts.view_all');

        $deals = $this->deals($owner)->where(function ($w) use ($fy) {
            $start = Carbon::create($fy, 4, 1, 0, 0, 0, self::TZ);
            $end = $start->copy()->addYear();
            $w->where(fn ($x) => $x->where('stage', 'won')->whereBetween('closed_at', [$start->copy()->utc(), $end->copy()->utc()]))
                ->orWhere(fn ($x) => $x->whereNotIn('stage', ['won', 'lost'])
                    ->where(fn ($y) => $y->whereNull('expected_close')->orWhereBetween('expected_close', [$start->toDateString(), $end->copy()->subDay()->toDateString()])));
        })->get();

        $quarters = [];
        $tg = $this->targets($fy, $owner);
        $cur = self::fyOf($today) === $fy ? self::quarterOf($today) : null;
        for ($q = 1; $q <= 4; $q++) {
            [$s, $e] = self::range($fy, $q);
            $quarters[$q] = [
                'quarter' => $q, 'label' => "Q$q", 'months' => self::MONTHS[$q], 'start' => $s->toDateString(), 'end' => $e->toDateString(),
                'current' => $cur === $q, 'target' => $tg[$q]['amount'], 'margin_target' => $tg[$q]['margin'],
                'won' => 0.0, 'won_count' => 0, 'won_margin' => 0.0,
                'open' => 0.0, 'open_count' => 0, 'weighted' => 0.0, 'overdue_count' => 0, 'overdue_value' => 0.0,
            ];
        }
        $undated = ['count' => 0, 'value' => 0.0, 'weighted' => 0.0];
        $byOwner = [];
        foreach ($deals as $d) {
            $b = $this->bucket($d, $fy, $today);
            if (! $b) {
                continue;
            }
            $oid = (string) ($d->owner_id ?? 0);
            $byOwner[$oid] ??= ['id' => $oid === '0' ? null : $oid, 'name' => $d->owner?->name ?? 'Unassigned', 'won' => 0.0, 'won_count' => 0, 'open' => 0.0, 'weighted' => 0.0, 'open_count' => 0];
            if ($b['kind'] === 'won') {
                $x = &$quarters[$b['q']];
                $x['won'] += $d->selling_amount; $x['won_count']++; $x['won_margin'] += $d->selling_amount - $d->supplier_cost;
                $byOwner[$oid]['won'] += $d->selling_amount; $byOwner[$oid]['won_count']++;
                unset($x);
            } elseif ($b['kind'] === 'undated') {
                $undated['count']++; $undated['value'] += $d->selling_amount; $undated['weighted'] += $d->selling_amount * $d->probability / 100;
                $byOwner[$oid]['open'] += $d->selling_amount; $byOwner[$oid]['weighted'] += $d->selling_amount * $d->probability / 100; $byOwner[$oid]['open_count']++;
            } else {
                $x = &$quarters[$b['q']];
                $x['open'] += $d->selling_amount; $x['open_count']++; $x['weighted'] += $d->selling_amount * $d->probability / 100;
                if ($b['overdue']) {
                    $x['overdue_count']++; $x['overdue_value'] += $d->selling_amount;
                }
                $byOwner[$oid]['open'] += $d->selling_amount; $byOwner[$oid]['weighted'] += $d->selling_amount * $d->probability / 100; $byOwner[$oid]['open_count']++;
                unset($x);
            }
        }
        $quarters = array_values(array_map(function ($x) {
            foreach (['won', 'won_margin', 'open', 'weighted', 'overdue_value'] as $k) {
                $x[$k] = round($x[$k], 2);
            }

            return $x;
        }, $quarters));

        // targets per consultant (only for the team view)
        $ownerTargets = DB::table('forecast_targets')->where('fy', $fy)->where('user_id', '>', 0)->get()->groupBy('user_id')->map(fn ($g) => (float) $g->sum('amount'));
        $owners = [];
        if ($all && $owner === null) {
            foreach ($ownerTargets as $uid => $amt) {
                if (! isset($byOwner[(string) $uid])) {
                    $byOwner[(string) $uid] = ['id' => (string) $uid, 'name' => User::find($uid)?->name ?? 'User', 'won' => 0.0, 'won_count' => 0, 'open' => 0.0, 'weighted' => 0.0, 'open_count' => 0];
                }
            }
            foreach ($byOwner as $row) {
                $row['target'] = $row['id'] ? (float) ($ownerTargets[(int) $row['id']] ?? 0) : 0.0;
                $row['won'] = round($row['won'], 2); $row['open'] = round($row['open'], 2); $row['weighted'] = round($row['weighted'], 2);
                $owners[] = $row;
            }
            usort($owners, fn ($a, $b) => $b['won'] <=> $a['won']);
        }

        $sum = fn (string $k) => round(array_sum(array_column($quarters, $k)), 2);
        $target = $sum('target');
        $won = $sum('won');

        return [
            'fy' => $fy, 'label' => sprintf('FY %d–%s', $fy, substr((string) ($fy + 1), -2)),
            'fys' => range(self::fyOf($today) - 2, self::fyOf($today) + 1),
            'owner' => $owner === null ? 'all' : (string) $owner, 'can_view_all' => $all,
            'can_set_targets' => $user->hasPermission('forecasts.manage_targets'),
            'quarters' => $quarters,
            'totals' => [
                'target' => $target, 'won' => $won, 'won_count' => array_sum(array_column($quarters, 'won_count')),
                'won_margin' => $sum('won_margin'), 'pct' => $target > 0 ? round($won / $target * 100, 1) : null,
                'open' => round($sum('open') + $undated['value'], 2), 'weighted' => round($sum('weighted') + $undated['weighted'], 2),
                'open_count' => array_sum(array_column($quarters, 'open_count')) + $undated['count'],
                'overdue_count' => array_sum(array_column($quarters, 'overdue_count')), 'overdue_value' => $sum('overdue_value'),
            ],
            'undated' => ['count' => $undated['count'], 'value' => round($undated['value'], 2), 'weighted' => round($undated['weighted'], 2)],
            'owners' => $owners,
            'consultants' => $all ? User::query()->where('active', true)->orderBy('name')->get(['id', 'name'])
                ->filter(fn (User $u) => $u->hasPermission('leads.assignable') || $u->role === 'admin')->map(fn ($u) => ['id' => (string) $u->id, 'name' => $u->name])->values() : [],
        ];
    }

    /** Deals behind one cell: kind = won | open | overdue | undated, quarter 1..4 (not used for undated). */
    public function dealsList(Request $request)
    {
        $user = $request->user();
        $fy = $this->fy($request);
        $owner = $this->ownerFilter($request, $user);
        $kind = (string) $request->query('kind', 'open');
        $q = (int) $request->query('quarter', 0);
        if (! in_array($kind, ['won', 'open', 'overdue', 'undated'], true) || ($kind !== 'undated' && ($q < 1 || $q > 4))) {
            Fail::unprocessable(['kind' => 'Invalid request']);
        }
        $today = self::today();
        $out = [];
        foreach ($this->deals($owner)->get() as $d) {
            $b = $this->bucket($d, $fy, $today);
            if (! $b) {
                continue;
            }
            $match = match ($kind) {
                'won' => $b['kind'] === 'won' && $b['q'] === $q,
                'open' => $b['kind'] === 'open' && $b['q'] === $q,
                'overdue' => $b['kind'] === 'open' && $b['q'] === $q && $b['overdue'],
                'undated' => $b['kind'] === 'undated',
            };
            if ($match) {
                $out[] = $d->toApi();
            }
        }
        usort($out, fn ($a, $b) => $b['selling_amount'] <=> $a['selling_amount']);

        return array_slice($out, 0, 200);
    }

    public function setTarget(Request $request)
    {
        $user = $request->user();
        $d = Fail::validate(Fail::body($request), [
            'fy' => 'required|integer|min:2000|max:2100', 'quarter' => 'required|integer|min:1|max:4',
            'user_id' => 'nullable', 'amount' => 'nullable|numeric|min:0', 'margin_amount' => 'nullable|numeric|min:0',
        ]);
        $uid = 0;
        if (isset($d['user_id']) && $d['user_id'] !== '' && (string) $d['user_id'] !== '0') {
            if (! ctype_digit((string) $d['user_id']) || ! User::where('id', (int) $d['user_id'])->where('active', true)->exists()) {
                Fail::unprocessable(['user_id' => 'User not found or inactive']);
            }
            $uid = (int) $d['user_id'];
        }
        $amount = (float) ($d['amount'] ?? 0);
        $margin = isset($d['margin_amount']) && $d['margin_amount'] !== '' ? (float) $d['margin_amount'] : null;
        $key = ['fy' => (int) $d['fy'], 'quarter' => (int) $d['quarter'], 'user_id' => $uid];
        $before = DB::table('forecast_targets')->where($key)->first();

        if ($amount <= 0 && $margin === null) {
            DB::table('forecast_targets')->where($key)->delete();
        } else {
            DB::table('forecast_targets')->updateOrInsert($key, ['amount' => $amount, 'margin_amount' => $margin, 'updated_at' => now(), 'created_at' => $before->created_at ?? now()]);
        }
        ActivityLogger::log($user, 'set_forecast_target', 'forecast', implode('-', $key),
            $before ? ['amount' => $before->amount, 'margin_amount' => $before->margin_amount] : null, ['amount' => $amount, 'margin_amount' => $margin]);

        return ['ok' => true];
    }
}
