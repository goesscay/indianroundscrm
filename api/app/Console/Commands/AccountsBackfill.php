<?php

namespace App\Console\Commands;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Receipt;
use App\Services\Posting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Posts issued invoices, valid receipts and credit notes that predate the Accounts module. Safe to run again. */
class AccountsBackfill extends Command
{
    protected $signature = 'accounts:backfill {--dry-run : only count what would be posted}';

    protected $description = 'Post existing issued invoices, receipts and credit notes to the ledger (idempotent)';

    public function handle(): int
    {
        $has = fn (string $type, int $id) => JournalEntry::where('source_type', $type)->where('source_id', $id)->whereNull('reverses_id')->exists();
        $n = ['invoices' => 0, 'receipts' => 0, 'credit_notes' => 0];

        DB::transaction(function () use ($has, &$n) {
            foreach (Invoice::where('status', 'issued')->orderBy('issue_date')->orderBy('id')->get() as $inv) {
                if (! $has('invoice', $inv->id)) {
                    $n['invoices']++;
                    if (! $this->option('dry-run')) {
                        Posting::invoiceIssued($inv, null);
                    }
                }
            }
            foreach (Receipt::where('status', 'valid')->orderBy('received_date')->orderBy('id')->get() as $r) {
                if (! $has('receipt', $r->id)) {
                    $n['receipts']++;
                    if (! $this->option('dry-run')) {
                        Posting::receipt($r, $r->invoice_id ? Invoice::find($r->invoice_id) : null, null);
                    }
                }
            }
            foreach (CreditNote::orderBy('issue_date')->orderBy('id')->get() as $cn) {
                if (! $has('credit_note', $cn->id)) {
                    $n['credit_notes']++;
                    if (! $this->option('dry-run')) {
                        Posting::creditNote($cn, Invoice::findOrFail($cn->invoice_id), null);
                    }
                }
            }
        });

        $this->info(sprintf('%s: %d invoices, %d receipts, %d credit notes.', $this->option('dry-run') ? 'Would post' : 'Posted', $n['invoices'], $n['receipts'], $n['credit_notes']));

        return self::SUCCESS;
    }
}
