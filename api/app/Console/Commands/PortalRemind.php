<?php

namespace App\Console\Commands;

use App\Models\LeadSupplier;
use App\Services\SupplierPortal\PortalNotices;
use App\Services\SupplierPortal\PortalRequests;
use Illuminate\Console\Command;

/**
 * Daily: e-mail portal suppliers who have not answered an enquiry that is due tomorrow, due today or overdue.
 * At most two reminders per supplier per enquiry, a day apart. Does nothing unless CRM_AUTO_PORTAL_REMINDERS=true.
 */
class PortalRemind extends Command
{
    protected $signature = 'portal:remind {--dry-run : list who would be reminded without sending}';

    protected $description = 'Remind portal suppliers who have not yet quoted';

    public function handle(): int
    {
        if (! config('crm.portal.auto_reminders') && ! $this->option('dry-run')) {
            $this->line('Automatic portal reminders are off (CRM_AUTO_PORTAL_REMINDERS).');

            return self::SUCCESS;
        }
        $horizon = now('Asia/Kolkata')->addDay()->toDateString();
        $sent = 0;
        $rows = LeadSupplier::whereNotNull('portal_invited_at')->whereNotNull('respond_by')->whereDate('respond_by', '<=', $horizon)
            ->where('portal_reminders', '<', PortalNotices::MAX_REMINDERS)
            ->where(fn ($w) => $w->whereNull('portal_reminded_at')->orWhere('portal_reminded_at', '<', now()->subHours(20)))
            ->orderBy('id')->get();
        foreach ($rows as $t) {
            if (PortalRequests::stateFor($t) !== 'open') {
                continue;
            }
            $this->line("{$t->lead_id} · {$t->supplier_name} (due {$t->respond_by})");
            if (! $this->option('dry-run') && PortalNotices::reminder($t)) {
                $sent++;
            }
        }
        $this->info($this->option('dry-run') ? 'Dry run: nothing sent.' : "Reminders sent: $sent");

        return self::SUCCESS;
    }
}
