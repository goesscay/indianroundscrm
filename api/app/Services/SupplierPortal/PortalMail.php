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

    /** The portal invitation as lines, added under the full enquiry when the sender wants both. @return list<string> */
    public static function linkLines(Lead $lead, Supplier $s): array
    {
        return [
            'You can send your quotation through our supplier portal, where the full requirement is also available:', '',
            PortalAuth::url('/portal/requests/'.rawurlencode($lead->lead_id)), '',
            'Sign in with this e-mail address ('.$s->email.'). We will send a one-time code to it. You can upload your quotation as a file or type the details in.',
        ];
    }

    public static function render(Lead $lead, Supplier $s, ?string $respondBy = null): string
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

        return implode("\n", array_filter(array_merge([
            'Dear Team,', '', "Greetings from $company!", '',
            'We have a new travel enquiry for you.', '',
            'Reference   : '.$lead->lead_id,
            'Destination : '.($lead->destination ?: 'N/A'),
            $date !== '' ? 'Travel date : '.$date : null,
            $respondBy ? 'Quote needed by : '.Carbon::parse($respondBy)->format('d M Y') : null, '',
            'Please open our supplier portal to see the full requirement and send your quotation there:', '',
            PortalAuth::url('/portal/requests/'.rawurlencode($lead->lead_id)), '',
            'Sign in with this e-mail address ('.$s->email.'). We will send a one-time code to it. You can upload your quotation as a file or type the details in.', '',
            'Please send the quotation through the portal rather than replying to this e-mail or forwarding it inside your company: it then reaches the right person at once. If a colleague needs access, tell us and we will add them.', '',
        ], \App\Support\MailSignature::lines()), fn ($l) => $l !== null));
    }
}
