<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Receipt;
use App\Models\User;

/**
 * Turns sales documents into journal entries.
 *   Invoice issued    Dr Accounts receivable        Cr Tour sales, GST/TCS payable
 *   Payment received  Dr Bank / cash                Cr Accounts receivable (or Customer advances when there is no invoice yet)
 *   Advance applied   Dr Customer advances          Cr Accounts receivable
 *   Credit note       Dr Sales returns, GST, TCS    Cr Accounts receivable
 *   Refund paid       Dr Accounts receivable        Cr Bank / cash
 * Cancelling a document posts a mirror entry. Every call is idempotent per document.
 */
class Posting
{
    private static function live(string $type, int $id): ?JournalEntry
    {
        return JournalEntry::where('source_type', $type)->where('source_id', $id)->whereNull('reversed_at')->whereNull('reverses_id')->first();
    }

    private static function gstAccount(Invoice $inv): array
    {
        return match ($inv->gst_mode) {
            'cgst_sgst' => [['2210', $inv->cgst_total], ['2220', $inv->sgst_total]],
            'igst' => [['2230', $inv->igst_total]],
            default => [['2200', $inv->tax_total]],
        };
    }

    public static function invoiceIssued(Invoice $inv, ?User $user): ?JournalEntry
    {
        if (self::live('invoice', $inv->id)) {
            return null;
        }
        $date = ($inv->issue_date ?? now('Asia/Kolkata'))->format('Y-m-d');
        $lines = [['account_id' => Ledger::id('1100'), 'debit' => $inv->total, 'memo' => $inv->invoice_no, 'customer_id' => $inv->customer_id, 'deal_id' => $inv->deal_id]];
        $lines[] = ['account_id' => Ledger::id('4000'), 'credit' => round($inv->subtotal - $inv->discount_total, 2), 'memo' => $inv->title, 'deal_id' => $inv->deal_id];
        foreach (self::gstAccount($inv) as [$code, $amt]) {
            $lines[] = ['account_id' => Ledger::id($code), 'credit' => $amt, 'memo' => 'GST on '.$inv->invoice_no];
        }
        $lines[] = ['account_id' => Ledger::id('2240'), 'credit' => $inv->tcs_total, 'memo' => 'TCS on '.$inv->invoice_no];

        return Ledger::post($date, "Invoice {$inv->invoice_no} — {$inv->title}", $lines, 'invoice', $inv->id, $user);
    }

    public static function invoiceCancelled(Invoice $inv, ?User $user): void
    {
        if ($e = self::live('invoice', $inv->id)) {
            Ledger::reverse($e, $user, null, "Cancellation of invoice {$inv->invoice_no}");
        }
    }

    public static function receipt(Receipt $r, ?Invoice $inv, ?User $user): ?JournalEntry
    {
        if (self::live('receipt', $r->id)) {
            return null;
        }
        $bank = Ledger::moneyAccount($r->bank_account_id ? (int) $r->bank_account_id : null, $r->mode);
        $date = $r->received_date->format('Y-m-d');
        $party = ['customer_id' => $r->customer_id, 'deal_id' => $r->deal_id];
        if ($r->kind === 'refund') {
            $lines = [['account_id' => Ledger::id('1100'), 'debit' => $r->amount, 'memo' => $r->reference, ...$party], ['account_id' => $bank, 'credit' => $r->amount, 'memo' => $r->reference]];
            $text = "Refund {$r->receipt_no}".($inv ? " on {$inv->invoice_no}" : '');
        } else {
            $other = $inv ? Ledger::id('1100') : Ledger::id('2100');
            $lines = [['account_id' => $bank, 'debit' => $r->amount, 'memo' => $r->reference], ['account_id' => $other, 'credit' => $r->amount, 'memo' => $r->reference, ...$party]];
            $text = "Receipt {$r->receipt_no}".($inv ? " against {$inv->invoice_no}" : ' (advance)');
        }

        return Ledger::post($date, $text, $lines, 'receipt', $r->id, $user);
    }

    public static function receiptCancelled(Receipt $r, ?User $user): void
    {
        foreach (['receipt', 'advance_apply'] as $type) {
            if ($e = self::live($type, $r->id)) {
                Ledger::reverse($e, $user, null, "Cancellation of {$r->receipt_no}");
            }
        }
    }

    /** The amount of an unapplied advance changes (it was split): reverse its entry and post it again for the new amount. */
    public static function receiptResized(Receipt $r, float $newAmount, ?User $user): void
    {
        if ($e = self::live('receipt', $r->id)) {
            Ledger::reverse($e, $user, null, "Split of {$r->receipt_no}");
        }
        $r->amount = round($newAmount, 2);
        $r->save();
        self::receipt($r, null, $user);
    }

    /** An advance gets applied to an invoice: move it from Customer advances to Accounts receivable. */
    public static function advanceApplied(Receipt $r, Invoice $inv, ?User $user): ?JournalEntry
    {
        if (self::live('advance_apply', $r->id)) {
            return null;
        }
        $party = ['customer_id' => $r->customer_id, 'deal_id' => $inv->deal_id];

        return Ledger::post(now('Asia/Kolkata')->toDateString(), "Advance {$r->receipt_no} applied to {$inv->invoice_no}", [
            ['account_id' => Ledger::id('2100'), 'debit' => $r->amount, ...$party],
            ['account_id' => Ledger::id('1100'), 'credit' => $r->amount, ...$party],
        ], 'advance_apply', $r->id, $user);
    }

    public static function creditNote(CreditNote $cn, Invoice $inv, ?User $user): ?JournalEntry
    {
        if (self::live('credit_note', $cn->id)) {
            return null;
        }
        $total = max(0.01, (float) $inv->total);
        $tcs = round($cn->amount * $inv->tcs_total / $total, 2);
        $tax = $cn->tax_part;
        $returns = round($cn->amount - $tax - $tcs, 2);
        $lines = [['account_id' => Ledger::id('4090'), 'debit' => $returns, 'memo' => $cn->reason, 'deal_id' => $inv->deal_id]];

        // GST in proportion to how the invoice split it
        $parts = self::gstAccount($inv);
        $given = 0.0;
        foreach ($parts as $i => [$code, $amt]) {
            $share = $i === count($parts) - 1 ? round($tax - $given, 2) : round($tax * ($inv->tax_total > 0 ? $amt / $inv->tax_total : 0), 2);
            $given += $share;
            $lines[] = ['account_id' => Ledger::id($code), 'debit' => $share, 'memo' => 'GST on '.$cn->credit_note_no];
        }
        $lines[] = ['account_id' => Ledger::id('2240'), 'debit' => $tcs, 'memo' => 'TCS on '.$cn->credit_note_no];
        $lines[] = ['account_id' => Ledger::id('1100'), 'credit' => $cn->amount, 'memo' => $cn->credit_note_no, 'customer_id' => $inv->customer_id, 'deal_id' => $inv->deal_id];

        return Ledger::post($cn->issue_date->format('Y-m-d'), "Credit note {$cn->credit_note_no} on {$inv->invoice_no} — {$cn->reason}", $lines, 'credit_note', $cn->id, $user);
    }
}
