<?php

namespace App\Http\Controllers\Crm;

use App\Crm\Fail;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Deal;
use App\Models\MoneyEntry;
use App\Models\Receipt;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\SupplierPayment;
use App\Services\Billing;
use App\Services\Ledger;
use App\Services\PayablesPosting;
use App\Support\ActivityLogger;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Supplier bills, supplier payments and other money in / out. */
class PayablesController extends Controller
{
    // ------------------------------------------------------------------ helpers

    private function today(): string
    {
        return now('Asia/Kolkata')->toDateString();
    }

    private function date(mixed $v, string $field, bool $allowFuture = false): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = is_scalar($v) ? substr((string) $v, 0, 10) : '';
        try {
            $c = Carbon::createFromFormat('!Y-m-d', $v);
            if ($c === false || $c->format('Y-m-d') !== $v) {
                throw new \Exception('bad');
            }
        } catch (\Throwable) {
            Fail::unprocessable([$field => 'Invalid date (use YYYY-MM-DD)']);
        }
        if (! $allowFuture && $v > $this->today()) {
            Fail::unprocessable([$field => 'The date cannot be in the future']);
        }

        return $v;
    }

    private function bank(mixed $v): ?int
    {
        if ($v === null || $v === '' || $v === 'auto') {
            return null;
        }
        $b = is_scalar($v) && ctype_digit((string) $v) ? BankAccount::where('id', (int) $v)->where('active', true)->first() : null;
        if (! $b) {
            Fail::unprocessable(['bank_account_id' => 'Bank account not found or inactive']);
        }

        return (int) $b->id;
    }

    private function txt(mixed $v, int $max): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function findBill(string $id): SupplierBill
    {
        $b = ctype_digit($id) ? SupplierBill::with(['supplier', 'deal', 'lines.account', 'payments'])->find((int) $id) : null;
        if (! $b) {
            Fail::notFound('Bill not found');
        }

        return $b;
    }

    private function findPayment(string $id): SupplierPayment
    {
        $p = ctype_digit($id) ? SupplierPayment::with(['supplier', 'bill'])->find((int) $id) : null;
        if (! $p) {
            Fail::notFound('Payment not found');
        }

        return $p;
    }

    private function settleBill(SupplierBill $b): void
    {
        $b->paid_total = round((float) SupplierPayment::where('bill_id', $b->id)->where('status', 'valid')->sum('amount'), 2);
        $b->save();
    }

    private function snap(SupplierBill $b): array
    {
        return ['bill_no' => $b->bill_no, 'total' => $b->total, 'status' => $b->status, 'supplier_id' => $b->supplier_id];
    }

    // ------------------------------------------------------------------ bills

    public function bills(Request $request)
    {
        $q = SupplierBill::query()->with(['supplier:id,name', 'deal:id,deal_no,name']);
        $today = $this->today();
        $status = (string) $request->query('status', '');
        if ($status === 'cancelled') {
            $q->where('status', 'cancelled');
        } elseif ($status !== '') {
            $q->where('status', 'open');
            if ($status === 'paid') {
                $q->whereRaw('total - paid_total <= 0.005');
            } elseif ($status === 'unpaid') {
                $q->whereRaw('total - paid_total > 0.005');
            } elseif ($status === 'overdue') {
                $q->whereRaw('total - paid_total > 0.005')->whereNotNull('due_date')->where('due_date', '<', $today);
            }
        }
        foreach (['supplier_id', 'deal_id'] as $f) {
            if (($v = (string) $request->query($f, '')) !== '') {
                $q->where($f, ctype_digit($v) ? (int) $v : -1);
            }
        }
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($s, '\\%_').'%';
            $op = Fail::ilike();
            $q->where(fn ($w) => $w->where('bill_no', $op, $like)->orWhere('supplier_ref', $op, $like)
                ->orWhereIn('supplier_id', Supplier::query()->select('id')->where('name', $op, $like)));
        }
        $q->orderByDesc('bill_date')->orderByDesc('id');

        if (Paging::requested($request)) {
            $res = Paging::paginate($request, $q, fn (SupplierBill $b) => $b->toApi());
            $res['outstanding'] = ! $request->user()->hasPermission('finance.view') ? null : round((float) SupplierBill::where('status', 'open')->whereRaw('total - paid_total > 0')->sum(DB::raw('total - paid_total')), 2);

            return $res;
        }

        return $q->limit(max(1, (int) $request->query('limit', 500)))->get()->map(fn (SupplierBill $b) => $b->toApi())->values();
    }

    /** The expense accounts a bill line can go to: what the bill form offers to people who do not see the whole chart of accounts. */
    public function billOptions()
    {
        return ['accounts' => \App\Models\Account::where('type', 'expense')->where('active', true)->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn ($a) => ['id' => (string) $a->id, 'code' => $a->code, 'name' => $a->name])->values()];
    }

    public function showBill(string $id)
    {
        return $this->findBill($id)->toApi(true);
    }

    public function storeBill(Request $request)
    {
        $user = $request->user();
        $d = Fail::validate(Fail::body($request), [
            'supplier_id' => 'required', 'supplier_ref' => 'nullable|string|max:80', 'deal_id' => 'nullable',
            'bill_date' => 'required|string', 'due_date' => 'nullable|string', 'notes' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1|max:50', 'allow_duplicate' => 'sometimes|boolean',
        ]);
        $supplier = is_scalar($d['supplier_id']) && ctype_digit((string) $d['supplier_id']) ? Supplier::find((int) $d['supplier_id']) : null;
        if (! $supplier) {
            Fail::unprocessable(['supplier_id' => 'Supplier not found']);
        }
        $deal = ! empty($d['deal_id']) && ctype_digit((string) $d['deal_id']) ? Deal::find((int) $d['deal_id']) : null;
        if (! empty($d['deal_id']) && ! $deal) {
            Fail::unprocessable(['deal_id' => 'Deal not found']);
        }
        $billDate = $this->date($d['bill_date'], 'bill_date');
        $due = $this->date($d['due_date'] ?? null, 'due_date', true);
        if ($due && $due < $billDate) {
            Fail::unprocessable(['due_date' => 'The due date is before the bill date']);
        }
        $ref = $this->txt($d['supplier_ref'] ?? null, 80);
        if ($ref && empty($d['allow_duplicate']) && SupplierBill::where('supplier_id', $supplier->id)->where('supplier_ref', $ref)->where('status', 'open')->exists()) {
            Fail::http(409, "A bill with the supplier's reference $ref has already been entered for {$supplier->name}.");
        }

        $lines = [];
        $total = 0.0;
        foreach ($d['lines'] as $i => $l) {
            $amount = round((float) ($l['amount'] ?? 0), 2);
            $desc = is_array($l) ? trim((string) ($l['description'] ?? '')) : '';
            if ($desc === '' || $amount <= 0) {
                Fail::unprocessable(["lines.$i" => 'Each line needs a description and an amount']);
            }
            $accId = isset($l['account_id']) && $l['account_id'] !== '' ? $l['account_id'] : Ledger::id('5000');
            $acc = ctype_digit((string) $accId) ? Account::where('id', (int) $accId)->where('active', true)->where('type', 'expense')->first() : null;
            if (! $acc) {
                Fail::unprocessable(["lines.$i.account_id" => 'Choose an active expense account']);
            }
            $lineDeal = ! empty($l['deal_id']) && ctype_digit((string) $l['deal_id']) ? Deal::find((int) $l['deal_id']) : null;
            if (! empty($l['deal_id']) && ! $lineDeal) {
                Fail::unprocessable(["lines.$i.deal_id" => 'Deal not found']);
            }
            $lines[] = ['description' => mb_substr($desc, 0, 300), 'amount' => $amount, 'account_id' => $acc->id, 'deal_id' => $lineDeal?->id];
            $total += $amount;
        }

        $bill = DB::transaction(function () use ($d, $supplier, $deal, $billDate, $due, $ref, $lines, $total, $user) {
            $b = SupplierBill::create([
                'bill_no' => Billing::nextNumber('SB', Carbon::parse($billDate, 'Asia/Kolkata')), 'supplier_ref' => $ref, 'supplier_id' => $supplier->id, 'deal_id' => $deal?->id,
                'bill_date' => $billDate, 'due_date' => $due, 'total' => round($total, 2), 'paid_total' => 0, 'status' => 'open',
                'notes' => $this->txt($d['notes'] ?? null, 2000), 'created_by' => $user->id,
            ]);
            foreach ($lines as $i => $l) {
                SupplierBillLine::create($l + ['bill_id' => $b->id, 'position' => $i]);
            }
            PayablesPosting::bill($b->fresh(['lines']), $user);
            foreach (array_unique(array_filter(array_merge([$deal?->id], array_column($lines, 'deal_id')))) as $dealId) {
                PayablesPosting::syncDealCost((int) $dealId);
            }

            return $b;
        });
        ActivityLogger::log($user, 'create_supplier_bill', 'supplier_bill', $bill->id, null, $this->snap($bill));

        return $this->findBill((string) $bill->id)->toApi(true);
    }

    public function cancelBill(Request $request, string $id)
    {
        $user = $request->user();
        $b = $this->findBill($id);
        $d = Fail::validate(Fail::body($request), ['reason' => 'required|string|max:300']);
        if (! $user->hasPermission('finance.payables') && (int) $b->created_by !== (int) $user->id) {
            Fail::forbidden('You can cancel only the bills you entered yourself.');          // finance can cancel any
        }
        if ($b->status !== 'open') {
            Fail::http(409, 'This bill is already cancelled.');
        }
        if (SupplierPayment::where('bill_id', $b->id)->where('status', 'valid')->exists()) {
            Fail::http(409, 'This bill has payments. Cancel the payments first.');
        }
        $before = $this->snap($b);
        DB::transaction(function () use ($b, $d, $user) {
            $b->status = 'cancelled';
            $b->cancelled_at = now();
            $b->cancel_reason = trim($d['reason']);
            $b->save();
            PayablesPosting::billCancelled($b, $user);
            foreach (array_unique(array_filter(array_merge([$b->deal_id], $b->lines->pluck('deal_id')->all()))) as $dealId) {
                PayablesPosting::syncDealCost((int) $dealId);
            }
        });
        ActivityLogger::log($user, 'cancel_supplier_bill', 'supplier_bill', $b->id, $before, $this->snap($b));

        return $this->findBill($id)->toApi(true);
    }

    // ------------------------------------------------------------------ supplier payments

    private function paymentBody(Request $request): array
    {
        $d = Fail::validate(Fail::body($request), [
            'amount' => 'required|numeric|min:0.01|max:100000000', 'paid_date' => 'nullable|string',
            'mode' => 'nullable|in:'.implode(',', Receipt::MODES), 'reference' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:1000', 'bank_account_id' => 'nullable',
        ]);
        $d['paid_date'] = $this->date($d['paid_date'] ?? null, 'paid_date') ?? $this->today();
        $d['bank'] = $this->bank($d['bank_account_id'] ?? null);

        return $d;
    }

    private function recordPayment(Request $request, Supplier $supplier, ?SupplierBill $bill, array $d): SupplierPayment
    {
        $user = $request->user();

        return DB::transaction(function () use ($supplier, $bill, $d, $user) {
            $p = SupplierPayment::create([
                'payment_no' => Billing::nextNumber('SP', Carbon::parse($d['paid_date'], 'Asia/Kolkata')), 'status' => 'valid', 'supplier_id' => $supplier->id, 'bill_id' => $bill?->id,
                'amount' => round((float) $d['amount'], 2), 'paid_date' => $d['paid_date'], 'mode' => $d['mode'] ?? 'bank_transfer',
                'reference' => $this->txt($d['reference'] ?? null, 120), 'bank_account_id' => $d['bank'], 'notes' => $this->txt($d['notes'] ?? null, 1000), 'created_by' => $user->id,
            ]);
            if ($bill) {
                $this->settleBill($bill);
            }
            PayablesPosting::payment($p, $bill, $user);
            ActivityLogger::log($user, 'record_supplier_payment', 'supplier_payment', $p->id, null, ['payment_no' => $p->payment_no, 'amount' => $p->amount, 'bill_id' => $p->bill_id]);

            return $p;
        });
    }

    public function payBill(Request $request, string $id)
    {
        $b = $this->findBill($id);
        if ($b->status !== 'open') {
            Fail::http(409, 'A cancelled bill cannot be paid.');
        }
        $d = $this->paymentBody($request);
        if ($d['amount'] > $b->balance() + 0.005) {
            Fail::unprocessable(['amount' => sprintf('This is more than the balance of ₹%s. Record the extra as an advance to the supplier.', number_format($b->balance(), 2))]);
        }
        $p = $this->recordPayment($request, $b->supplier, $b, $d);

        return ['payment' => $this->findPayment((string) $p->id)->toApi(), 'bill' => $this->findBill($id)->toApi(true)];
    }

    public function advance(Request $request, string $id)
    {
        $s = ctype_digit($id) ? Supplier::find((int) $id) : null;
        if (! $s) {
            Fail::notFound('Supplier not found');
        }
        $p = $this->recordPayment($request, $s, null, $this->paymentBody($request));

        return $this->findPayment((string) $p->id)->toApi();
    }

    public function payments(Request $request)
    {
        $q = SupplierPayment::query()->with(['supplier:id,name', 'bill:id,bill_no,supplier_ref']);
        foreach (['supplier_id', 'bill_id', 'status', 'mode'] as $f) {
            if (($v = (string) $request->query($f, '')) !== '') {
                $q->where($f, str_ends_with($f, '_id') ? (ctype_digit($v) ? (int) $v : -1) : $v);
            }
        }
        if ((string) $request->query('advances', '') === '1') {
            $q->whereNull('bill_id')->where('status', 'valid');
        }
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($s, '\\%_').'%';
            $op = Fail::ilike();
            $q->where(fn ($w) => $w->where('payment_no', $op, $like)->orWhere('reference', $op, $like)
                ->orWhereIn('supplier_id', Supplier::query()->select('id')->where('name', $op, $like)));
        }
        $q->orderByDesc('paid_date')->orderByDesc('id');

        if (Paging::requested($request)) {
            $res = Paging::paginate($request, $q, fn (SupplierPayment $p) => $p->toApi());
            $res['totals'] = [
                'paid' => round((float) SupplierPayment::where('status', 'valid')->sum('amount'), 2),
                'advances' => round((float) SupplierPayment::where('status', 'valid')->whereNull('bill_id')->sum('amount'), 2),
            ];

            return $res;
        }

        return $q->limit(max(1, (int) $request->query('limit', 500)))->get()->map(fn (SupplierPayment $p) => $p->toApi())->values();
    }

    public function showPayment(string $id)
    {
        return $this->findPayment($id)->toApi();
    }

    public function applyAdvance(Request $request, string $id)
    {
        $user = $request->user();
        $p = $this->findPayment($id);
        $d = Fail::validate(Fail::body($request), ['bill_id' => 'required']);
        if ($p->status !== 'valid' || $p->bill_id !== null) {
            Fail::http(409, 'Only an unapplied advance can be applied to a bill.');
        }
        $bill = ctype_digit((string) $d['bill_id']) ? SupplierBill::with('supplier')->find((int) $d['bill_id']) : null;
        if (! $bill || (int) $bill->supplier_id !== (int) $p->supplier_id || $bill->status !== 'open') {
            Fail::unprocessable(['bill_id' => 'Choose an open bill of the same supplier']);
        }
        if ($p->amount > $bill->balance() + 0.005) {
            Fail::unprocessable(['bill_id' => sprintf('This advance (₹%s) is more than the bill balance (₹%s).', number_format($p->amount, 2), number_format($bill->balance(), 2))]);
        }
        DB::transaction(function () use ($p, $bill, $user) {
            $p->bill_id = $bill->id;
            $p->save();
            $this->settleBill($bill);
            PayablesPosting::advanceApplied($p, $bill, $user);
            ActivityLogger::log($user, 'apply_supplier_advance', 'supplier_payment', $p->id, null, ['payment_no' => $p->payment_no, 'bill_id' => $bill->id]);
        });

        return $this->findPayment($id)->toApi();
    }

    public function cancelPayment(Request $request, string $id)
    {
        $user = $request->user();
        $p = $this->findPayment($id);
        $d = Fail::validate(Fail::body($request), ['reason' => 'required|string|max:300']);
        if ($p->status !== 'valid') {
            Fail::http(409, 'This payment is already cancelled.');
        }
        DB::transaction(function () use ($p, $d, $user) {
            $p->status = 'cancelled';
            $p->cancelled_at = now();
            $p->cancel_reason = trim($d['reason']);
            $p->save();
            if ($p->bill_id && ($b = SupplierBill::find($p->bill_id))) {
                $this->settleBill($b);
            }
            PayablesPosting::paymentCancelled($p, $user);
            ActivityLogger::log($user, 'cancel_supplier_payment', 'supplier_payment', $p->id, ['status' => 'valid'], ['status' => 'cancelled', 'payment_no' => $p->payment_no]);
        });

        return $this->findPayment($id)->toApi();
    }

    // ------------------------------------------------------------------ other money in / out

    public function money(Request $request)
    {
        $q = MoneyEntry::query()->with('account:id,code,name');
        foreach (['kind', 'status'] as $f) {
            if (($v = (string) $request->query($f, '')) !== '') {
                $q->where($f, $v);
            }
        }
        if (($v = (string) $request->query('account_id', '')) !== '') {
            $q->where('account_id', ctype_digit($v) ? (int) $v : -1);
        }
        if (($v = (string) $request->query('from', '')) !== '') {
            $q->where('entry_date', '>=', $this->date($v, 'from', true));
        }
        if (($v = (string) $request->query('to', '')) !== '') {
            $q->where('entry_date', '<=', $this->date($v, 'to', true));
        }
        if (($s = trim((string) $request->query('q', ''))) !== '') {
            $like = '%'.addcslashes($s, '\\%_').'%';
            $op = Fail::ilike();
            $q->where(fn ($w) => $w->where('entry_no', $op, $like)->orWhere('payee', $op, $like)->orWhere('reference', $op, $like)->orWhere('notes', $op, $like));
        }
        $q->orderByDesc('entry_date')->orderByDesc('id');

        if (Paging::requested($request)) {
            $res = Paging::paginate($request, $q, fn (MoneyEntry $m) => $m->toApi());
            $base = MoneyEntry::where('status', 'valid');
            $res['totals'] = ['expense' => round((float) (clone $base)->where('kind', 'expense')->sum('amount'), 2), 'income' => round((float) (clone $base)->where('kind', 'income')->sum('amount'), 2)];

            return $res;
        }

        return $q->limit(max(1, (int) $request->query('limit', 500)))->get()->map(fn (MoneyEntry $m) => $m->toApi())->values();
    }

    public function storeMoney(Request $request)
    {
        $user = $request->user();
        $d = Fail::validate(Fail::body($request), [
            'kind' => 'required|in:expense,income', 'entry_date' => 'nullable|string', 'account_id' => 'required',
            'amount' => 'required|numeric|min:0.01|max:100000000', 'mode' => 'nullable|in:'.implode(',', Receipt::MODES),
            'payee' => 'nullable|string|max:150', 'reference' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:1000', 'bank_account_id' => 'nullable',
        ]);
        $type = $d['kind'] === 'expense' ? 'expense' : 'income';
        $acc = ctype_digit((string) $d['account_id']) ? Account::where('id', (int) $d['account_id'])->where('active', true)->where('type', $type)->first() : null;
        if (! $acc) {
            Fail::unprocessable(['account_id' => $type === 'expense' ? 'Choose an active expense account' : 'Choose an active income account']);
        }
        $date = $this->date($d['entry_date'] ?? null, 'entry_date') ?? $this->today();
        $bank = $this->bank($d['bank_account_id'] ?? null);

        $m = DB::transaction(function () use ($d, $acc, $date, $bank, $user) {
            $m = MoneyEntry::create([
                'entry_no' => Billing::nextNumber($d['kind'] === 'expense' ? 'MO' : 'MI', Carbon::parse($date, 'Asia/Kolkata')), 'kind' => $d['kind'], 'status' => 'valid',
                'entry_date' => $date, 'account_id' => $acc->id, 'amount' => round((float) $d['amount'], 2), 'bank_account_id' => $bank, 'mode' => $d['mode'] ?? 'bank_transfer',
                'payee' => $this->txt($d['payee'] ?? null, 150), 'reference' => $this->txt($d['reference'] ?? null, 120), 'notes' => $this->txt($d['notes'] ?? null, 1000), 'created_by' => $user->id,
            ]);
            PayablesPosting::moneyEntry($m, $user);

            return $m;
        });
        ActivityLogger::log($user, 'create_money_entry', 'money_entry', $m->id, null, ['entry_no' => $m->entry_no, 'kind' => $m->kind, 'amount' => $m->amount]);

        return $m->load('account')->toApi();
    }

    public function cancelMoney(Request $request, string $id)
    {
        $user = $request->user();
        $m = ctype_digit($id) ? MoneyEntry::with('account')->find((int) $id) : null;
        if (! $m) {
            Fail::notFound('Entry not found');
        }
        $d = Fail::validate(Fail::body($request), ['reason' => 'required|string|max:300']);
        if ($m->status !== 'valid') {
            Fail::http(409, 'This entry is already cancelled.');
        }
        DB::transaction(function () use ($m, $d, $user) {
            $m->status = 'cancelled';
            $m->cancelled_at = now();
            $m->cancel_reason = trim($d['reason']);
            $m->save();
            PayablesPosting::moneyEntryCancelled($m, $user);
        });
        ActivityLogger::log($user, 'cancel_money_entry', 'money_entry', $m->id, ['status' => 'valid'], ['status' => 'cancelled', 'entry_no' => $m->entry_no]);

        return $m->fresh('account')->toApi();
    }
}
