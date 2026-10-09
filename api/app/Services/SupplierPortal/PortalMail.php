<?php

namespace App\Services\SupplierPortal;

use App\Models\Lead;
use App\Models\Supplier;
use App\Models\SupplierUser;
use Illuminate\Support\Carbon;

/** The short e-mail a portal supplier receives instead of the full enquiry, and the sign-in account behind it. */
class PortalMail
{
    /** Make sure the supplier's main address can sign in. Returns null when that address belongs to another supplier's account. */
    public static function ensureUser(Supplier $s): ?SupplierUser
    {
        $email = PortalAuth::normalise((string) $s->email);
        if ($email === '') {
            return null;
        }
        $u = SupplierUser::whereRaw('lower(email) = ?', [$email])->first();
        if ($u) {
            return (string) $u->supplier_id === (string) $s->id ? $u : null;
        }

        return SupplierUser::create(['supplier_id' => $s->id, 'name' => $s->name, 'email' => $email, 'active' => true]);
    }

    public static function render(Lead $lead, Supplier $s): string
    {
        $date = '';
        if ($lead->travel_date) {
            try {
                $date = Carbon::parse($lead->travel_date)->format('Y-m-d');
            } catch (\Throwable) {
                $date = substr((string) $lead->travel_date, 0, 10);
            }
        }
        $company = (string) config('crm.company.name', 'Indian Rounds');
        $phone = trim((string) config('crm.company.phone', ''));

        return implode("\n", array_filter([
            'Dear Team,', '', "Greetings from $company!", '',
            'We have a new travel enquiry for you.', '',
            'Reference   : '.$lead->lead_id,
            'Destination : '.($lead->destination ?: 'N/A'),
            $date !== '' ? 'Travel date : '.$date : null, '',
            'Please open our supplier portal to see the full requirement and send your quotation there:', '',
            PortalAuth::url('/portal/requests/'.rawurlencode($lead->lead_id)), '',
            'Sign in with this e-mail address ('.$s->email.'). We will send a one-time code to it. You can upload your quotation as a file or type the details in.', '',
            'Please send the quotation through the portal rather than replying to this e-mail or forwarding it inside your company: it then reaches the right person at once. If a colleague needs access, tell us and we will add them.', '',
            'Regards,', 'Sales Operations Team', $company, $phone !== '' ? $phone : null,
        ], fn ($l) => $l !== null));
    }
}
