<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\JournalEntry;
use App\Models\MoneyEntry;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Money-out side of the books.
 *   Supplier bill        Dr cost / expense accounts (per line, per trip)   Cr Accounts payable
 *   Supplier payment     Dr Accounts payable                               Cr Bank / cash
 *   Supplier advance     Dr Supplier advances                              Cr Bank / cash   (applied later: Dr Accounts payable, Cr Supplier advances)
 *   Other expense        Dr expense account                                Cr Bank / cash
 *   Other income         Dr Bank / cash                                    Cr income account
 * Cancelling a document posts a mirror entry.
 */
class PayablesPosting
{
    private static function live(string $type, int $id): ?JournalEntry
    {
        return JournalEntry::where('source_type', $type)->where('source_id', $id)->whereNull('reversed_at')->whereNull('reverses_id')->first();
    }

    public static function bill(SupplierBill $b, ?User $user): ?JournalEntry
    {
        if (self::live('bill', $b->id)) {
            return null;
        }
        $b->loadMissing('lines', 'supplier');
        $lines = [];
        foreach ($b->lines as $l) {
            $lines[] = ['account_id' => $l->account_id, 'debit' => $l->amount, 'memo' => $l->description, 'deal_id' => $l->deal_id ?: $b->deal_id];
        }
        $lines[] = ['account_id' => Ledger::id('2000'), 'credit' => $b->total, 'memo' => $b->supplier_ref ?: $b->bill_no, 'supplier_id' => $b->supplier_id, 'deal_id' => $b->deal_id];

        return Ledger::post($b->bill_date->format('Y-m-d'), "Bill {$b->bill_no} — ".($b->supplier?->name ?? 'supplier'), $lines, 'bill', $b->id, $user);
    }

    public static function billCancelled(SupplierBill $b, ?User $user): void
    {
        if ($e = self::live('bill', $b->id)) {
            Ledger::reverse($e, $user, null, "Cancellation of bill {$b->bill_no}");
        }
    }

    public static function payment(SupplierPayment $p, ?SupplierBill $bill, ?User $user): ?JournalEntry
    {
        if (self::live('supplier_payment', $p->id)) {
            return null;
        }
        $bank = Ledger::moneyAccount($p->bank_account_id ? (int) $p->bank_account_id : null, $p->mode);
        $debit = $bill ? Ledger::id('2000') : Ledger::id('1300');
        $text = "Payment {$p->payment_no}".($bill ? " against {$bill->bill_no}" : ' (advance to supplier)');

        return Ledger::post($p->paid_date->format('Y-m-d'), $text, [
            ['account_id' => $debit, 'debit' => $p->amount, 'memo' => $p->reference, 'supplier_id' => $p->supplier_id, 'deal_id' => $bill?->deal_id],
            ['account_id' => $bank, 'credit' => $p->amount, 'memo' => $p->reference],
        ], 'supplier_payment', $p->id, $user);
    }

    public static function paymentCancelled(SupplierPayment $p, ?User $user): void
    {
        foreach (['supplier_payment', 'supplier_adv_apply'] as $type) {
            if ($e = self::live($type, $p->id)) {
                Ledger::reverse($e, $user, null, "Cancellation of {$p->payment_no}");
            }
        }
    }

    public static function advanceApplied(SupplierPayment $p, SupplierBill $bill, ?User $user): ?JournalEntry
    {
        if (self::live('supplier_adv_apply', $p->id)) {
            return null;
        }

        return Ledger::post(now('Asia/Kolkata')->toDateString(), "Advance {$p->payment_no} applied to {$bill->bill_no}", [
            ['account_id' => Ledger::id('2000'), 'debit' => $p->amount, 'supplier_id' => $p->supplier_id, 'deal_id' => $bill->deal_id],
            ['account_id' => Ledger::id('1300'), 'credit' => $p->amount, 'supplier_id' => $p->supplier_id, 'deal_id' => $bill->deal_id],
        ], 'supplier_adv_apply', $p->id, $user);
    }

    public static function moneyEntry(MoneyEntry $m, ?User $user): ?JournalEntry
    {
        if (self::live('money_entry', $m->id)) {
            return null;
        }
        $bank = Ledger::moneyAccount($m->bank_account_id ? (int) $m->bank_account_id : null, $m->mode);
        $who = $m->payee ? " — {$m->payee}" : '';
        if ($m->kind === 'expense') {
            $lines = [['account_id' => $m->account_id, 'debit' => $m->amount, 'memo' => $m->reference ?: $m->payee], ['account_id' => $bank, 'credit' => $m->amount, 'memo' => $m->reference]];
            $text = "Expense {$m->entry_no}$who";
        } else {
            $lines = [['account_id' => $bank, 'debit' => $m->amount, 'memo' => $m->reference], ['account_id' => $m->account_id, 'credit' => $m->amount, 'memo' => $m->reference ?: $m->payee]];
            $text = "Income {$m->entry_no}$who";
        }

        return Ledger::post($m->entry_date->format('Y-m-d'), $text, $lines, 'money_entry', $m->id, $user);
    }

    public static function moneyEntryCancelled(MoneyEntry $m, ?User $user): void
    {
        if ($e = self::live('money_entry', $m->id)) {
            Ledger::reverse($e, $user, null, "Cancellation of {$m->entry_no}");
        }
    }

    /** A deal's supplier cost follows its supplier bills (when it has any), so margins and forecasts use real costs. */
    public static function syncDealCost(?int $dealId): void
    {
        if (! $dealId || ! ($deal = Deal::find($dealId))) {
            return;
        }
        $q = DB::table('supplier_bill_lines as l')->join('supplier_bills as b', 'b.id', '=', 'l.bill_id')->where('b.status', 'open')
            ->whereRaw('COALESCE(l.deal_id, b.deal_id) = ?', [$dealId]);
        if ((clone $q)->count() > 0) {
            $deal->supplier_cost = round((float) $q->sum('l.amount'), 2);
            $deal->save();
        }
    }
}
