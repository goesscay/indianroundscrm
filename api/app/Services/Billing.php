<?php

namespace App\Services;

use App\Http\Controllers\Crm\ForecastController;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Numbers;
use Illuminate\Support\Carbon;

/** Shared money logic for invoices, receipts and credit notes. */
class Billing
{
    /** "26-27" for FY 2026-27 (Apr-Mar, IST). */
    public static function fyLabel(?Carbon $on = null): string
    {
        $fy = ForecastController::fyOf(($on ?? Carbon::now('Asia/Kolkata'))->copy()->timezone('Asia/Kolkata'));

        return sprintf('%02d-%02d', $fy % 100, ($fy + 1) % 100);
    }

    public static function nextNumber(string $prefix, ?Carbon $on = null): string
    {
        $fy = self::fyLabel($on);

        return Numbers::next(strtolower($prefix)."_seq_$fy", "$prefix/$fy/", 4);
    }

    /** Recompute paid (receipts less refunds) and credited totals from the source rows. */
    public static function settle(Invoice $inv): Invoice
    {
        $rows = Receipt::where('invoice_id', $inv->id)->where('status', 'valid')->get(['kind', 'amount']);
        $inv->paid_total = round($rows->where('kind', 'receipt')->sum('amount') - $rows->where('kind', 'refund')->sum('amount'), 2);
        $inv->credited_total = round((float) $inv->creditNotes()->sum('amount'), 2);
        $inv->save();

        return $inv;
    }

    /** The first payment on an invoice that belongs to an open deal books the trip: the deal becomes Won. */
    public static function dealBooked(Invoice $inv, User $user): void
    {
        $deal = $inv->deal_id ? Deal::find($inv->deal_id) : null;
        if (! $deal || in_array($deal->stage, ['won', 'lost'], true)) {
            return;
        }
        $before = ['stage' => $deal->stage, 'selling_amount' => $deal->selling_amount];
        $deal->stage = 'won';
        $deal->probability = 100;
        $deal->closed_at = $deal->closed_at ?: now();
        $deal->trip_status = $deal->trip_status ?: 'confirmed';
        $deal->lost_reason = null;
        $deal->selling_amount = $inv->total;
        $deal->save();
        ActivityLogger::log($user, 'update_deal', 'deal', $deal->id, $before, ['stage' => 'won', 'selling_amount' => $deal->selling_amount, 'via_invoice' => $inv->invoice_no]);
    }

    /**
     * Split an advance in two: it keeps $keep, and the rest becomes a new advance of its own. The books stay square: the original
     * entry is reversed and posted again for the smaller amount, and the remainder gets its own entry.
     */
    public static function splitAdvance(Receipt $r, float $keep, User $user): Receipt
    {
        $keep = round($keep, 2);
        $rest = round($r->amount - $keep, 2);

        return DB::transaction(function () use ($r, $keep, $rest, $user) {
            Posting::receiptResized($r, $keep, $user);
            $new = Receipt::create([
                'receipt_no' => self::nextNumber('RCT', Carbon::parse($r->received_date->format('Y-m-d'), 'Asia/Kolkata')), 'kind' => 'receipt', 'status' => 'valid',
                'customer_id' => $r->customer_id, 'invoice_id' => null, 'deal_id' => null, 'amount' => $rest, 'received_date' => $r->received_date->format('Y-m-d'),
                'mode' => $r->mode, 'reference' => $r->reference, 'bank_account_id' => $r->bank_account_id, 'deposited_to' => $r->deposited_to,
                'notes' => mb_substr("Balance of {$r->receipt_no}, left over after part of it was applied to an invoice.", 0, 1000), 'created_by' => $user->id,
            ]);
            Posting::receipt($new, null, $user);
            ActivityLogger::log($user, 'split_advance', 'receipt', $r->id, ['amount' => $keep + $rest], ['amount' => $keep, 'remainder_receipt' => $new->receipt_no, 'remainder' => $rest]);

            return $new;
        });
    }

    /** Apply an advance to an issued invoice of the same customer. If it is more than the invoice balance, only what fits is applied and the rest stays an advance. */
    public static function applyAdvance(Receipt $r, Invoice $inv, User $user): Receipt
    {
        $inv->refresh();
        $balance = round(max(0.0, $inv->balance()), 2);
        if ($r->amount > $balance + 0.005) {
            self::splitAdvance($r, $balance, $user);
            $r->refresh();
        }
        DB::transaction(function () use ($r, $inv, $user) {
            $r->invoice_id = $inv->id;
            $r->deal_id = $inv->deal_id;
            $r->save();
            self::settle($inv);
            self::dealBooked($inv, $user);
            Posting::advanceApplied($r, $inv, $user);
            ActivityLogger::log($user, 'apply_advance', 'receipt', $r->id, null, ['receipt_no' => $r->receipt_no, 'invoice_id' => $inv->id]);
        });
        if ($inv->deal_id && ($deal = Deal::find($inv->deal_id))) {
            TripPlan::autoConfirm($deal, $user);
        }

        return $r->fresh();
    }

    /**
     * When an invoice is issued, the customer's unapplied advances go onto it, oldest first. Only when it is clear which invoice they are for:
     * if the customer has another open invoice, the advances are left for someone to apply by hand.
     *
     * @return array{count:int,amount:float}
     */
    public static function applyAdvancesAtIssue(Invoice $inv, User $user): array
    {
        $out = ['count' => 0, 'amount' => 0.0];
        $others = Invoice::where('customer_id', $inv->customer_id)->where('status', 'issued')->where('id', '<>', $inv->id)->get()->filter(fn (Invoice $i) => $i->balance() > 0.005);
        if ($others->isNotEmpty()) {
            return $out;
        }
        $advances = Receipt::where('customer_id', $inv->customer_id)->where('kind', 'receipt')->where('status', 'valid')->whereNull('invoice_id')
            ->where(fn ($w) => $w->whereNull('deal_id')->orWhere('deal_id', $inv->deal_id))->orderBy('received_date')->orderBy('id')->get();
        foreach ($advances as $r) {
            $inv->refresh();
            if ($inv->balance() <= 0.005) {
                break;
            }
            $applied = self::applyAdvance($r, $inv, $user);
            $out['count']++;
            $out['amount'] = round($out['amount'] + (float) $applied->amount, 2);
        }

        return $out;
    }

    /** A payment of the same amount and mode for the same customer in the last 24 hours: probably entered twice. */
    public static function possibleDuplicate(int $customerId, float $amount, string $mode): ?Receipt
    {
        return Receipt::where('customer_id', $customerId)->where('kind', 'receipt')->where('status', 'valid')
            ->where('amount', round($amount, 2))->where('mode', $mode)->where('created_at', '>=', now()->subDay())->orderByDesc('id')->first();
    }

    /** Stops a payment that looks like a repeat, unless the person confirms it really is another one. */
    public static function refuseDuplicate(int $customerId, array $d, bool $confirmed): void
    {
        if ($confirmed) {
            return;
        }
        $dup = self::possibleDuplicate($customerId, (float) $d['amount'], $d['mode'] ?? 'bank_transfer');
        if ($dup) {
            \App\Crm\Fail::http(409, ['code' => 'possible_duplicate', 'receipt_no' => $dup->receipt_no, 'message' => sprintf('This looks like %s: the same amount (₹%s) by the same mode for this customer, recorded %s. Is it really another payment?', $dup->receipt_no, number_format($dup->amount, 2), $dup->created_at->diffForHumans())]);
        }
    }

    public static function record(User $user, int $customerId, ?Invoice $inv, string $kind, array $d): Receipt
    {
        $date = $d['received_date'] ?? now('Asia/Kolkata')->toDateString();
        $bank = ! empty($d['bank_account_id']) && ctype_digit((string) $d['bank_account_id']) ? \App\Models\BankAccount::where('id', (int) $d['bank_account_id'])->where('active', true)->first() : null;
        if (! empty($d['bank_account_id']) && ! $bank) {
            \App\Crm\Fail::unprocessable(['bank_account_id' => 'Bank account not found or inactive']);
        }
        $r = Receipt::create([
            'receipt_no' => self::nextNumber($kind === 'refund' ? 'RFD' : 'RCT', Carbon::parse($date, 'Asia/Kolkata')), 'kind' => $kind, 'status' => 'valid',
            'customer_id' => $customerId, 'invoice_id' => $inv?->id, 'deal_id' => $inv?->deal_id,
            'amount' => round((float) $d['amount'], 2), 'received_date' => $date, 'mode' => $d['mode'] ?? 'bank_transfer',
            'reference' => ($v = trim((string) ($d['reference'] ?? ''))) !== '' ? mb_substr($v, 0, 120) : null,
            'bank_account_id' => $bank?->id,
            'deposited_to' => $bank?->name ?? (($v = trim((string) ($d['deposited_to'] ?? ''))) !== '' ? mb_substr($v, 0, 120) : null),
            'notes' => ($v = trim((string) ($d['notes'] ?? ''))) !== '' ? mb_substr($v, 0, 1000) : null,
            'created_by' => $user->id,
        ]);
        if ($inv) {
            self::settle($inv);
            if ($kind === 'receipt') {
                self::dealBooked($inv, $user);
                if ($inv->deal_id && ($deal = Deal::find($inv->deal_id))) {
                    TripPlan::autoConfirm($deal, $user);
                }
            }
        }
        Posting::receipt($r, $inv, $user);
        ActivityLogger::log($user, $kind === 'refund' ? 'record_refund' : 'record_receipt', 'receipt', $r->id, null,
            ['receipt_no' => $r->receipt_no, 'amount' => $r->amount, 'invoice_id' => $r->invoice_id, 'customer_id' => $customerId]);

        return $r;
    }
}
