<?php

namespace App\Services;

use App\Crm\MailSender;
use App\Models\CustomerTraveller;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** The e-mail that asks a customer for their trip documents, with a secure upload link. */
class TripDocRequests
{
    /** Mandatory documents still missing, as "Traveller: document" lines. @return list<string> */
    public static function missing(Deal $deal): array
    {
        $travellers = CustomerTraveller::whereIn('id', TripDocuments::travellerIds($deal))->get()->keyBy('id');
        $out = [];
        foreach (DB::table('trip_documents')->where('deal_id', $deal->id)->whereIn('status', ['pending', 'rejected'])->orderBy('id')->get() as $r) {
            $t = $r->traveller_id ? $travellers->get($r->traveller_id) : null;
            $req = TripDocuments::requirement($r->doc_type, $t, $deal);
            if ($req && $req['mandatory']) {
                $out[] = ($t ? $t->name.': ' : '').$req['label'];
            }
        }

        return $out;
    }

    public static function defaultMessage(Deal $deal): string
    {
        $co = config('crm.company.name');
        $name = $deal->customer?->name ?? 'there';
        $trip = $deal->destination ?: $deal->name;
        $date = $deal->travel_date ? ' on '.$deal->travel_date->format('d M Y') : '';

        return "Dear $name,\n\nThank you for booking your $trip trip$date with $co. To get everything ready for you, we need a few documents. "
            ."You can upload them yourself from your phone using the secure link below. It takes a couple of minutes.\n\nIf you have any questions, just reply to this e-mail.\n\nWarm regards,\n$co";
    }

    /** Create a link and e-mail it. Returns [MailSender result, link url]. */
    public static function send(Deal $deal, array $to, ?string $message, int $days, ?User $user, string $trigger, ?int $daysBefore = null): array
    {
        $link = TripUploadLinks::create($deal, $user, $days);
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $missing = self::missing($deal);
        $list = $missing ? '<p style="margin:16px 0 6px"><strong>Still needed</strong></p><ul style="margin:0;padding-left:18px">'.implode('', array_map(fn ($m) => '<li>'.$e($m).'</li>', $missing)).'</ul>' : '';
        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#1a1a1a;max-width:560px">'
            .'<div style="white-space:pre-line">'.$e($message ?: self::defaultMessage($deal)).'</div>'.$list
            .'<p style="margin:22px 0"><a href="'.$e($link['url']).'" style="background:#0a0a0a;color:#fff;padding:12px 22px;border-radius:6px;text-decoration:none;font-weight:600">Upload my documents</a></p>'
            .'<p style="color:#777;font-size:12px">This link is private to you and works until '.$e(Carbon::parse($link['expires_at'])->timezone('Asia/Kolkata')->format('d M Y')).'. Please do not share it.<br>'.$e($link['url']).'</p>'
            .'<p style="color:#777;font-size:12px">'.$e(config('crm.company.name')).'</p></div>';
        $subject = 'Documents needed for your '.($deal->destination ?: 'trip').' trip';
        $res = MailSender::send($to, $subject, $html, [], [], true);
        DB::table('trip_doc_reminders')->insert(['deal_id' => $deal->id, 'to_list' => implode(', ', $to), 'status' => $res['status'] ?? 'failed', 'trigger' => $trigger,
            'days_before' => $daysBefore, 'sent_by' => $user?->id, 'created_at' => now()]);

        return [$res, $link['url']];
    }
}
