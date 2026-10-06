<?php

namespace App\Services;

use App\Http\Controllers\Crm\ForecastController;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Receipt;
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

    public static function record(User $user, int $customerId, ?Invoice $inv, string $kind, array $d): Receipt
    {
        $date = $d['received_date'] ?? now('Asia/Kolkata')->toDateString();
        $r = Receipt::create([
            'receipt_no' => self::nextNumber($kind === 'refund' ? 'RFD' : 'RCT', Carbon::parse($date, 'Asia/Kolkata')), 'kind' => $kind, 'status' => 'valid',
            'customer_id' => $customerId, 'invoice_id' => $inv?->id, 'deal_id' => $inv?->deal_id,
            'amount' => round((float) $d['amount'], 2), 'received_date' => $date, 'mode' => $d['mode'] ?? 'bank_transfer',
            'reference' => ($v = trim((string) ($d['reference'] ?? ''))) !== '' ? mb_substr($v, 0, 120) : null,
            'deposited_to' => ($v = trim((string) ($d['deposited_to'] ?? ''))) !== '' ? mb_substr($v, 0, 120) : null,
            'notes' => ($v = trim((string) ($d['notes'] ?? ''))) !== '' ? mb_substr($v, 0, 1000) : null,
            'created_by' => $user->id,
        ]);
        if ($inv) {
            self::settle($inv);
            if ($kind === 'receipt') {
                self::dealBooked($inv, $user);
            }
        }
        ActivityLogger::log($user, $kind === 'refund' ? 'record_refund' : 'record_receipt', 'receipt', $r->id, null,
            ['receipt_no' => $r->receipt_no, 'amount' => $r->amount, 'invoice_id' => $r->invoice_id, 'customer_id' => $customerId]);

        return $r;
    }
}
