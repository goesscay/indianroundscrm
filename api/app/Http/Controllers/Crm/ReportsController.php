<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Services\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportsController extends Controller
{
    private function today(): Carbon
    {
        return Carbon::now('Asia/Kolkata')->startOfDay();
    }

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

    /** Financial year start (1 April) containing the date. */
    private function fyStart(Carbon $d): Carbon
    {
        return Carbon::create(ForecastController::fyOf($d), 4, 1, 0, 0, 0, 'Asia/Kolkata');
    }

    /** @return array{0:string,1:string} from, to (defaults: this financial year to today) */
    private function range(Request $request): array
    {
        $to = $this->date($request->query('to'), 'to') ?? $this->today()->toDateString();
        $from = $this->date($request->query('from'), 'from') ?? $this->fyStart(Carbon::parse($to, 'Asia/Kolkata'))->toDateString();
        if ($from > $to) {
            Fail::unprocessable(['from' => 'The start date is after the end date']);
        }

        return [$from, $to];
    }

    private function net(Account $a, array $b): float
    {
        return $a->balanceOf($b['debit'] ?? 0.0, $b['credit'] ?? 0.0);
    }

    // ------------------------------------------------------------------ profit and loss

    public function profitLoss(Request $request)
    {
        [$from, $to] = $this->range($request);
        $bal = Ledger::balances($to, $from);
        $income = $cost = $expenses = [];
        foreach (Account::whereIn('type', ['income', 'expense'])->orderBy('code')->get() as $a) {
            $b = $bal[$a->id] ?? null;
            if (! $b) {
                continue;
            }
            $row = ['id' => (string) $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => $this->net($a, $b)];
            if ($a->type === 'income') {
                $income[] = $row;
            } elseif ($a->subtype === 'cost_of_sales') {
                $cost[] = $row;
            } else {
                $expenses[] = $row;
            }
        }
        $sum = fn (array $r) => round(array_sum(array_column($r, 'amount')), 2);
        $inc = $sum($income);
        $cos = $sum($cost);
        $exp = $sum($expenses);

        return [
            'from' => $from, 'to' => $to, 'income' => $income, 'cost_of_sales' => $cost, 'expenses' => $expenses,
            'total_income' => $inc, 'total_cost_of_sales' => $cos, 'gross_profit' => round($inc - $cos, 2),
            'total_expenses' => $exp, 'net_profit' => round($inc - $cos - $exp, 2),
        ];
    }

    // ------------------------------------------------------------------ balance sheet

    public function balanceSheet(Request $request)
    {
        $asOf = $this->date($request->query('as_of'), 'as_of') ?? $this->today()->toDateString();
        $bal = Ledger::balances($asOf);
        $groups = ['asset' => [], 'liability' => [], 'equity' => []];
        $profit = 0.0;
        foreach (Account::orderBy('code')->get() as $a) {
            $b = $bal[$a->id] ?? null;
            if (! $b) {
                continue;
            }
            $v = $this->net($a, $b);
            if ($a->type === 'income') {
                $profit += $v;
            } elseif ($a->type === 'expense') {
                $profit -= $v;
            } elseif (abs($v) > 0.004) {
                $groups[$a->type][] = ['id' => (string) $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => $v];
            }
        }
        $profit = round($profit, 2);
        $sum = fn (array $r) => round(array_sum(array_column($r, 'amount')), 2);
        $assets = $sum($groups['asset']);
        $liab = $sum($groups['liability']);
        $eq = round($sum($groups['equity']) + $profit, 2);

        return [
            'as_of' => $asOf, 'assets' => $groups['asset'], 'liabilities' => $groups['liability'], 'equity' => $groups['equity'],
            'profit_to_date' => $profit, 'total_assets' => $assets, 'total_liabilities' => $liab, 'total_equity' => $eq,
            'balanced' => abs($assets - ($liab + $eq)) < 0.005,
        ];
    }

    // ------------------------------------------------------------------ ageing

    private static function bucket(int $daysPastDue): string
    {
        return match (true) {
            $daysPastDue <= 0 => 'current', $daysPastDue <= 30 => 'd1_30', $daysPastDue <= 60 => 'd31_60', $daysPastDue <= 90 => 'd61_90', default => 'd90p',
        };
    }

    private const BUCKETS = ['current', 'd1_30', 'd31_60', 'd61_90', 'd90p'];

    private function ageTotals(array $rows): array
    {
        $t = ['balance' => 0.0] + array_fill_keys(self::BUCKETS, 0.0);
        foreach ($rows as $r) {
            foreach (['balance', ...self::BUCKETS] as $k) {
                $t[$k] = round($t[$k] + $r[$k], 2);
            }
        }

        return $t;
    }

    public function receivablesAgeing()
    {
        $today = $this->today();
        $rows = [];
        $invoices = Invoice::with(['customer:id,customer_no,name', 'installments'])->where('status', 'issued')->get()
            ->filter(fn (Invoice $i) => $i->balance() > 0.005);
        foreach ($invoices as $inv) {
            // unpaid part of each instalment (earliest paid first); a credit note takes from the latest ones
            $left = max(0.0, $inv->paid_total);
            $open = [];
            foreach ($inv->installments as $i) {
                $paid = min($i->amount, $left);
                $left -= $paid;
                if ($i->amount - $paid > 0.004) {
                    $open[] = ['due' => $i->due_date->format('Y-m-d'), 'amount' => round($i->amount - $paid, 2)];
                }
            }
            if (! $open) {
                $due = ($inv->issue_date ?? $today)->copy()->addDays((int) config('crm.invoice_due_days', 7))->format('Y-m-d');
                $open[] = ['due' => $due, 'amount' => $inv->balance()];
            }
            $excess = round(array_sum(array_column($open, 'amount')) - $inv->balance(), 2);
            for ($k = count($open) - 1; $k >= 0 && $excess > 0.004; $k--) {
                $cut = min($open[$k]['amount'], $excess);
                $open[$k]['amount'] = round($open[$k]['amount'] - $cut, 2);
                $excess = round($excess - $cut, 2);
            }
            $row = ['customer_id' => (string) $inv->customer_id, 'customer' => $inv->customer?->name, 'invoice_id' => (string) $inv->id, 'invoice_no' => $inv->invoice_no,
                'title' => $inv->title, 'balance' => $inv->balance()] + array_fill_keys(self::BUCKETS, 0.0);
            $row['next_due'] = null;
            foreach ($open as $o) {
                if ($o['amount'] <= 0) {
                    continue;
                }
                $days = (int) Carbon::parse($o['due'], 'Asia/Kolkata')->startOfDay()->diffInDays($today, false);
                $row[self::bucket($days)] = round($row[self::bucket($days)] + $o['amount'], 2);
                $row['next_due'] ??= $o['due'];
            }
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => strcmp((string) $a['customer'], (string) $b['customer']) ?: strcmp((string) $a['invoice_no'], (string) $b['invoice_no']));

        return ['as_of' => $today->toDateString(), 'items' => $rows, 'totals' => $this->ageTotals($rows)];
    }

    public function payablesAgeing()
    {
        $today = $this->today();
        $rows = [];
        foreach (SupplierBill::with('supplier:id,name')->where('status', 'open')->get()->filter(fn (SupplierBill $b) => $b->balance() > 0.005) as $b) {
            $due = ($b->due_date ?? $b->bill_date)->format('Y-m-d');
            $days = (int) Carbon::parse($due, 'Asia/Kolkata')->startOfDay()->diffInDays($today, false);
            $row = ['supplier_id' => (string) $b->supplier_id, 'supplier' => $b->supplier?->name, 'bill_id' => (string) $b->id, 'bill_no' => $b->bill_no, 'supplier_ref' => $b->supplier_ref,
                'due_date' => $due, 'balance' => $b->balance()] + array_fill_keys(self::BUCKETS, 0.0);
            $row[self::bucket($days)] = $b->balance();
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => strcmp((string) $a['supplier'], (string) $b['supplier']) ?: strcmp($a['due_date'], $b['due_date']));

        return ['as_of' => $today->toDateString(), 'items' => $rows, 'totals' => $this->ageTotals($rows)];
    }

    // ------------------------------------------------------------------ GST summary

    public function gstSummary(Request $request)
    {
        [$from, $to] = $this->range($request);
        $ids = [];
        foreach (['4000', '4090', '2200', '2210', '2220', '2230', '2240'] as $code) {
            $ids[$code] = Ledger::id($code);
        }
        $driver = DB::getDriverName();
        $month = $driver === 'pgsql' ? "to_char(e.entry_date, 'YYYY-MM')" : "strftime('%Y-%m', e.entry_date)";
        $rows = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->whereIn('l.account_id', array_values($ids))->whereBetween('e.entry_date', [$from, $to])
            ->selectRaw("$month as m, l.account_id, SUM(l.credit) - SUM(l.debit) as net")->groupBy('m', 'l.account_id')->get();

        $byMonth = [];
        $flip = array_flip($ids);
        foreach ($rows as $r) {
            $code = (string) $flip[(int) $r->account_id]; // numeric string keys come back as ints
            $byMonth[$r->m] ??= ['month' => $r->m, 'taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'gst_other' => 0.0, 'tcs' => 0.0];
            $k = match ($code) { '4000', '4090' => 'taxable', '2210' => 'cgst', '2220' => 'sgst', '2230' => 'igst', '2200' => 'gst_other', default => 'tcs' };
            $byMonth[$r->m][$k] = round($byMonth[$r->m][$k] + (float) $r->net, 2);
        }
        ksort($byMonth);
        $items = array_values(array_map(function ($m) {
            $m['gst_total'] = round($m['cgst'] + $m['sgst'] + $m['igst'] + $m['gst_other'], 2);

            return $m;
        }, $byMonth));
        $tot = ['taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'gst_other' => 0.0, 'tcs' => 0.0, 'gst_total' => 0.0];
        foreach ($items as $m) {
            foreach ($tot as $k => $_) {
                $tot[$k] = round($tot[$k] + $m[$k], 2);
            }
        }

        return ['from' => $from, 'to' => $to, 'items' => $items, 'totals' => $tot, 'note' => 'Output tax on sales, net of credit notes. Input tax credit is not tracked: supplier costs are booked at their full amount.'];
    }

    // ------------------------------------------------------------------ party statements

    public function partyLedger(Request $request)
    {
        $type = (string) $request->query('type', '');
        $id = (string) $request->query('id', '');
        if (! in_array($type, ['customer', 'supplier'], true) || ! ctype_digit($id)) {
            Fail::unprocessable(['type' => 'Choose a customer or a supplier']);
        }
        $party = $type === 'customer' ? Customer::find((int) $id) : Supplier::find((int) $id);
        if (! $party) {
            Fail::notFound(ucfirst($type).' not found');
        }
        $from = $this->date($request->query('from'), 'from');
        $to = $this->date($request->query('to'), 'to');
        $col = $type === 'customer' ? 'customer_id' : 'supplier_id';
        $accounts = $type === 'customer' ? [Ledger::id('1100'), Ledger::id('2100')] : [Ledger::id('2000'), Ledger::id('1300')];
        // customer: what they owe us (debit - credit). supplier: what we owe them (credit - debit).
        $sign = $type === 'customer' ? 1 : -1;

        $base = fn () => DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')->where("l.$col", (int) $id)->whereIn('l.account_id', $accounts);
        $opening = 0.0;
        if ($from) {
            $o = $base()->where('e.entry_date', '<', $from)->selectRaw('COALESCE(SUM(l.debit),0) as d, COALESCE(SUM(l.credit),0) as c')->first();
            $opening = round($sign * ($o->d - $o->c), 2);
        }
        $q = $base()->select('e.id as entry_id', 'e.entry_no', 'e.entry_date', 'e.narration', 'e.source_type', 'l.memo', 'l.debit', 'l.credit')->orderBy('e.entry_date')->orderBy('e.id')->orderBy('l.id');
        if ($from) {
            $q->where('e.entry_date', '>=', $from);
        }
        if ($to) {
            $q->where('e.entry_date', '<=', $to);
        }
        $run = $opening;
        $items = [];
        $td = $tc = 0.0;
        foreach ($q->limit(20000)->get() as $r) {
            $d = round((float) $r->debit, 2);
            $c = round((float) $r->credit, 2);
            $run = round($run + $sign * ($d - $c), 2);
            $td += $d;
            $tc += $c;
            $items[] = ['entry_id' => (string) $r->entry_id, 'entry_no' => $r->entry_no, 'date' => substr((string) $r->entry_date, 0, 10), 'narration' => $r->narration,
                'source_type' => $r->source_type, 'memo' => $r->memo, 'debit' => $d, 'credit' => $c, 'balance' => $run];
        }
        $page = max(1, (int) $request->query('page', 1));
        $per = min(200, max(1, (int) $request->query('per_page', 50)));

        return [
            'type' => $type, 'party' => ['id' => (string) $party->id, 'name' => $party->name], 'opening' => $opening, 'closing' => $run,
            'total_debit' => round($td, 2), 'total_credit' => round($tc, 2),
            'position' => $type === 'customer' ? ($run >= 0 ? 'owes you' : 'is owed by you') : ($run >= 0 ? 'you owe them' : 'they owe you'),
            'items' => array_slice($items, ($page - 1) * $per, $per), 'total' => count($items), 'page' => $page, 'per_page' => $per, 'pages' => (int) max(1, ceil(count($items) / $per)),
        ];
    }

    // ------------------------------------------------------------------ per-trip books

    public function dealBooks(Request $request, string $id)
    {
        $deal = DealController::find($id, $request->user());
        $lines = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.deal_id', $deal->id)->whereIn('a.type', ['income', 'expense'])
            ->selectRaw('a.type, a.code, a.name, SUM(l.debit) as d, SUM(l.credit) as c')->groupBy('a.type', 'a.code', 'a.name')->orderBy('a.code')->get();
        $rev = $cost = [];
        foreach ($lines as $r) {
            if ($r->type === 'income') {
                $rev[] = ['code' => $r->code, 'name' => $r->name, 'amount' => round($r->c - $r->d, 2)];
            } else {
                $cost[] = ['code' => $r->code, 'name' => $r->name, 'amount' => round($r->d - $r->c, 2)];
            }
        }
        $income = round(array_sum(array_column($rev, 'amount')), 2);
        $costs = round(array_sum(array_column($cost, 'amount')), 2);
        $bills = SupplierBill::with('supplier:id,name')->where('deal_id', $deal->id)->orWhereIn('id', DB::table('supplier_bill_lines')->where('deal_id', $deal->id)->select('bill_id'))
            ->orderByDesc('bill_date')->get()->map(fn (SupplierBill $b) => $b->toApi())->values();

        return ['revenue' => $rev, 'costs' => $cost, 'total_revenue' => $income, 'total_costs' => $costs, 'profit' => round($income - $costs, 2),
            'margin_pct' => $income > 0 ? round(($income - $costs) / $income * 100, 1) : null, 'bills' => $bills];
    }
}
