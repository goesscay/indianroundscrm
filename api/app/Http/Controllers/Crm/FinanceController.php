<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Invoice;
use App\Services\Ledger;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinanceController extends Controller
{
    private function date(mixed $v, string $field): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = is_scalar($v) ? substr((string) $v, 0, 10) : '';
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || strtotime($v) === false) {
            Fail::unprocessable([$field => 'Invalid date (use YYYY-MM-DD)']);
        }

        return $v;
    }

    private static function today(): Carbon
    {
        return Carbon::now('Asia/Kolkata')->startOfDay();
    }

    // ------------------------------------------------------------------ dashboard

    /** Net of an income / expense account type between two dates, in its own direction. */
    private function net(string $type, ?string $from, ?string $to): float
    {
        $accounts = Account::where('type', $type)->pluck('id');
        $q = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')->whereIn('l.account_id', $accounts);
        if ($from) {
            $q->where('e.entry_date', '>=', $from);
        }
        if ($to) {
            $q->where('e.entry_date', '<=', $to);
        }
        $r = $q->selectRaw('COALESCE(SUM(l.debit),0) as d, COALESCE(SUM(l.credit),0) as c')->first();

        return round($type === 'income' ? $r->c - $r->d : $r->d - $r->c, 2);
    }

    public function dashboard()
    {
        $today = self::today();
        $monthStart = $today->copy()->startOfMonth()->toDateString();
        $fy = ForecastController::fyOf($today);
        $fyStart = Carbon::create($fy, 4, 1, 0, 0, 0, 'Asia/Kolkata')->toDateString();
        $bal = Ledger::balances();
        $dn = fn (string $code) => ($b = $bal[Ledger::id($code)] ?? null) ? round($b['debit'] - $b['credit'], 2) : 0.0; // debit-normal
        $cn = fn (string $code) => -$dn($code);                                                                          // credit-normal

        $banks = BankAccount::with('account')->where('active', true)->orderByDesc('is_default')->orderBy('name')->get()->map(function ($b) use ($bal) {
            $x = $bal[$b->account_id] ?? ['debit' => 0.0, 'credit' => 0.0];

            return ['id' => (string) $b->id, 'name' => $b->name, 'kind' => $b->kind, 'balance' => round($x['debit'] - $x['credit'], 2)];
        })->values();
        // money not yet assigned to a named bank still sits in the system Cash and Bank accounts
        foreach ([['1000', 'Cash in hand', 'cash'], ['1010', 'Bank (unassigned)', 'bank']] as [$code, $name, $kind]) {
            $v = $dn($code);
            if (abs($v) > 0.004) {
                $banks->push(['id' => null, 'name' => $name, 'kind' => $kind, 'balance' => $v]);
            }
        }

        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = $today->copy()->startOfMonth()->subMonthsNoOverflow($i);
            $from = $m->toDateString();
            $to = $m->copy()->endOfMonth()->toDateString();
            $months[] = ['month' => $m->format('Y-m'), 'label' => $m->format('M'), 'income' => $this->net('income', $from, $to), 'expenses' => $this->net('expense', $from, $to)];
        }

        $out = Invoice::where('status', 'issued')->get(['total', 'credited_total', 'paid_total']);
        $owed = $out->map(fn ($i) => max(0, $i->total - $i->credited_total - $i->paid_total));

        return [
            'banks' => $banks, 'bank_total' => round((float) $banks->sum('balance'), 2),
            'receivable' => $dn('1100'), 'payable' => $cn('2000'), 'advances' => $cn('2100'),
            'gst_payable' => round($cn('2200') + $cn('2210') + $cn('2220') + $cn('2230'), 2), 'tcs_payable' => $cn('2240'),
            'month' => ['income' => $this->net('income', $monthStart, null), 'expenses' => $this->net('expense', $monthStart, null)],
            'year' => ['income' => $this->net('income', $fyStart, null), 'expenses' => $this->net('expense', $fyStart, null), 'label' => sprintf('FY %d–%02d', $fy, ($fy + 1) % 100)],
            'months' => $months,
            'invoices_outstanding' => ['count' => $owed->filter(fn ($v) => $v > 0.005)->count(), 'total' => round((float) $owed->sum(), 2)],
            'lock_date' => Ledger::lockDate(),
            'entries' => (int) DB::table('journal_entries')->count(),
        ];
    }

    // ------------------------------------------------------------------ ledger and trial balance

    public function ledger(Request $request, string $id)
    {
        $acc = ctype_digit($id) ? Account::find((int) $id) : null;
        if (! $acc) {
            Fail::notFound('Account not found');
        }
        $from = $this->date($request->query('from'), 'from');
        $to = $this->date($request->query('to'), 'to');

        $opening = ['debit' => 0.0, 'credit' => 0.0];
        if ($from) {
            $o = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')->where('l.account_id', $acc->id)->where('e.entry_date', '<', $from)
                ->selectRaw('COALESCE(SUM(l.debit),0) as d, COALESCE(SUM(l.credit),0) as c')->first();
            $opening = ['debit' => (float) $o->d, 'credit' => (float) $o->c];
        }
        $q = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')->where('l.account_id', $acc->id)
            ->select('e.id as entry_id', 'e.entry_no', 'e.entry_date', 'e.narration', 'e.source_type', 'l.memo', 'l.debit', 'l.credit')
            ->orderBy('e.entry_date')->orderBy('e.id')->orderBy('l.id');
        if ($from) {
            $q->where('e.entry_date', '>=', $from);
        }
        if ($to) {
            $q->where('e.entry_date', '<=', $to);
        }
        $rows = $q->limit(20000)->get();

        $run = $acc->balanceOf($opening['debit'], $opening['credit']);
        $openingBal = $run;
        $items = [];
        $td = $tc = 0.0;
        foreach ($rows as $r) {
            $d = round((float) $r->debit, 2);
            $c = round((float) $r->credit, 2);
            $run = round($run + ($acc->debitNormal() ? $d - $c : $c - $d), 2);
            $td += $d;
            $tc += $c;
            $items[] = ['entry_id' => (string) $r->entry_id, 'entry_no' => $r->entry_no, 'date' => substr((string) $r->entry_date, 0, 10), 'narration' => $r->narration,
                'source_type' => $r->source_type, 'memo' => $r->memo, 'debit' => $d, 'credit' => $c, 'balance' => $run];
        }
        $total = count($items);
        $page = max(1, (int) $request->query('page', 1));
        $per = min(200, max(1, (int) $request->query('per_page', 50)));

        return [
            'account' => $acc->toArray() + ['type_label' => Account::TYPE_LABELS[$acc->type] ?? $acc->type],
            'opening' => $openingBal, 'closing' => $run, 'total_debit' => round($td, 2), 'total_credit' => round($tc, 2),
            'items' => array_slice($items, ($page - 1) * $per, $per), 'total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => (int) max(1, ceil($total / $per)),
        ];
    }

    public function trialBalance(Request $request)
    {
        $asOf = $this->date($request->query('as_of'), 'as_of') ?? self::today()->toDateString();
        $bal = Ledger::balances($asOf);
        $rows = [];
        $dr = $cr = 0.0;
        foreach (Account::orderBy('code')->get() as $a) {
            $b = $bal[$a->id] ?? ['debit' => 0.0, 'credit' => 0.0];
            if ($b['debit'] == 0.0 && $b['credit'] == 0.0) {
                continue;
            }
            $net = round($b['debit'] - $b['credit'], 2);
            $rows[] = ['id' => (string) $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'debit' => $b['debit'], 'credit' => $b['credit'],
                'closing_debit' => $net > 0 ? $net : 0.0, 'closing_credit' => $net < 0 ? -$net : 0.0];
            $dr += $net > 0 ? $net : 0;
            $cr += $net < 0 ? -$net : 0;
        }

        return ['as_of' => $asOf, 'items' => $rows, 'total_debit' => round($dr, 2), 'total_credit' => round($cr, 2), 'balanced' => abs($dr - $cr) < 0.005];
    }

    // ------------------------------------------------------------------ period lock

    public function settings()
    {
        return ['lock_date' => Ledger::lockDate()];
    }

    public function updateSettings(Request $request)
    {
        $d = Fail::validate(Fail::body($request), ['lock_date' => 'nullable|string']);
        $date = $this->date($d['lock_date'] ?? null, 'lock_date');
        if ($date && $date > self::today()->toDateString()) {
            Fail::unprocessable(['lock_date' => 'You can only close the books up to today']);
        }
        $before = Ledger::lockDate();
        DB::table('accounting_settings')->updateOrInsert(['key' => 'lock_date'], ['value' => $date]);
        ActivityLogger::log($request->user(), 'set_lock_date', 'accounting', 'lock_date', ['lock_date' => $before], ['lock_date' => $date]);

        return ['lock_date' => $date];
    }
}
