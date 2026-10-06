<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\Reminders;
use App\Support\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Daily: e-mails payment reminders for overdue invoices (when CRM_AUTO_REMINDERS is on). */
class InvoicesRemind extends Command
{
    protected $signature = 'invoices:remind {--dry-run : list who would be reminded without sending} {--force : run even if automatic reminders are off}';

    protected $description = 'Send payment reminders for overdue invoices';

    public function handle(): int
    {
        if (! config('crm.reminders.auto') && ! $this->option('force')) {
            $this->line('Automatic reminders are off (CRM_AUTO_REMINDERS).');

            return self::SUCCESS;
        }
        $now = Carbon::now('Asia/Kolkata');
        $sent = $failed = 0;
        Invoice::query()->with(['customer', 'owner', 'installments', 'lines', 'receipts', 'creditNotes'])->where('status', 'issued')
            ->whereRaw('total - credited_total - paid_total > 0.005')->orderBy('id')->each(function (Invoice $inv) use ($now, &$sent, &$failed) {
                if (! Reminders::due($inv, $now)) {
                    return;
                }
                $m = Reminders::compose($inv);
                if ($this->option('dry-run')) {
                    $this->line("{$inv->invoice_no} → {$inv->customer->email}: {$m['subject']}");
                    $sent++;

                    return;
                }
                $cc = array_values(array_filter([$inv->owner?->email]));
                $res = Reminders::send($inv, [$inv->customer->email], $cc, $m['subject'], $m['message'], true, null, 'auto');
                if (($res['status'] ?? '') === 'failed') {
                    $failed++;
                    $this->warn("{$inv->invoice_no}: ".($res['error'] ?? 'failed'));
                } else {
                    $sent++;
                    ActivityLogger::log(null, 'invoice_reminder_sent', 'invoice', $inv->id, null, ['to' => $inv->customer->email, 'auto' => true]);
                }
            });
        $this->info(($this->option('dry-run') ? 'Would send ' : 'Sent ')."$sent reminder(s)".($failed ? ", $failed failed" : '').'.');

        return self::SUCCESS;
    }
}
