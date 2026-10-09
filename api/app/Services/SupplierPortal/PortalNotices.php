<?php

namespace App\Services\SupplierPortal;

use App\Crm\MailSender;
use App\Models\Lead;
use App\Models\LeadSupplier;
use App\Models\SupplierUser;
use Illuminate\Support\Carbon;

/**
 * Short e-mails that pull a supplier back to the portal: a quote reminder, and "you have a message". Neither carries
 * the content of anything: the details stay behind the sign-in.
 */
class PortalNotices
{
    public const MAX_REMINDERS = 2;

    /** @return list<string> addresses of the active portal users of the supplier */
    private static function recipients(LeadSupplier $t): array
    {
        return SupplierUser::where('supplier_id', $t->supplier_id)->where('active', true)->pluck('email')->all();
    }

    private static function sign(): array
    {
        return ['', 'Regards,', 'Sales Operations Team', (string) config('crm.company.name', 'Indian Rounds')];
    }

    private static function link(LeadSupplier $t): string
    {
        return PortalAuth::url('/portal/requests/'.rawurlencode($t->lead_id));
    }

    /** @return bool true when a mail went out */
    public static function reminder(LeadSupplier $t): bool
    {
        $to = self::recipients($t);
        $lead = Lead::where('lead_id', $t->lead_id)->first();
        if (! $to || ! $lead) {
            return false;
        }
        $by = $t->respond_by ? Carbon::parse($t->respond_by)->format('d M Y') : null;
        $res = MailSender::send($to, 'Reminder: quote needed - '.$lead->destination.' - '.$t->lead_id, implode("\n", array_merge([
            'Dear Team,', '',
            'This is a gentle reminder that we are still waiting for your quotation for enquiry '.$t->lead_id.($lead->destination ? ' ('.$lead->destination.')' : '').'.',
            $by ? 'We would be grateful to have it by '.$by.'.' : null, '',
            'Please send it through the supplier portal:', self::link($t), '',
            'If you cannot quote this one, a one-line "we cannot quote" in the portal helps us plan.',
        ], self::sign())));
        if (($res['status'] ?? '') === 'failed') {
            return false;
        }
        $t->forceFill(['portal_reminders' => (int) $t->portal_reminders + 1, 'portal_reminded_at' => now()])->save();

        return true;
    }

    public static function newMessage(LeadSupplier $t): bool
    {
        $to = self::recipients($t);
        if (! $to) {
            return false;
        }
        $res = MailSender::send($to, 'New message about enquiry '.$t->lead_id.' - Indian Rounds', implode("\n", array_merge([
            'Dear Team,', '',
            'You have a new message from us about enquiry '.$t->lead_id.'.', '',
            'Please read and answer it in the supplier portal:', self::link($t),
        ], self::sign())));

        return ($res['status'] ?? '') !== 'failed';
    }
}
