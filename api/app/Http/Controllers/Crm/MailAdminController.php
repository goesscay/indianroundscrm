<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\MailSyncState;
use App\Models\SupplierReply;
use App\Services\Mail\MailTransport;
use App\Services\Mail\ReplyPoller;
use Carbon\Carbon;

class MailAdminController extends Controller
{
    /** GET /mail/status */
    public function status(MailTransport $transport)
    {
        $iso = function (?string $v) {
            if (! $v) {
                return null;
            }
            try {
                return Carbon::parse($v)->utc()->toIso8601String();
            } catch (\Throwable) {
                return null;
            }
        };

        return [
            'transport' => $transport->name(),
            'configured' => $transport->isConfigured(),
            'mailbox' => (string) config('services.microsoft.mailbox'),
            'last_poll_at' => $iso(MailSyncState::get('last_poll_at')),
            'last_success_at' => $iso(MailSyncState::get('last_success_at')),
            'last_error' => MailSyncState::get('last_error') ?: null,
            'unprocessed_count' => (int) ($transport->countUnprocessed() ?? 0),
            'replies_total' => SupplierReply::count(),
            'unmatched_count' => SupplierReply::where('status', 'unmatched')->count(),
        ];
    }

    /** POST /mail/poll: one synchronous poll (time-limited) */
    public function poll(ReplyPoller $poller)
    {
        @set_time_limit(90);

        return $poller->run(45);
    }
}
