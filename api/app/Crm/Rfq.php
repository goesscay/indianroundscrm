<?php

namespace App\Crm;

use App\Models\Lead;
use Illuminate\Support\Carbon;

/** Port of email_service.render_supplier_rfq_text. */
class Rfq
{
    private static function fmtDate(?string $iso): string
    {
        if (! $iso) {
            return 'N/A';
        }
        try {
            return Carbon::parse($iso)->format('Y-m-d');
        } catch (\Throwable) {
            return substr($iso, 0, 10) ?: 'N/A';
        }
    }

    private static function v($val): string
    {
        if ($val === null) {
            return 'N/A';
        }
        $s = trim((string) $val);
        return $s !== '' ? $s : 'N/A';
    }

    public static function render(Lead $lead, string $intro = ''): string
    {
        $stay = [];
        foreach ($lead->stayCitiesArray() as $c) {
            $name = trim($c['city'] ?? '');
            if ($name === '') {
                continue;
            }
            $n = $c['nights'] ?? null;
            $stay[] = $n
                ? str_pad($name, 14).': '.$n.' Night'.($n != 1 ? 's' : '')
                : str_pad($name, 14).': —';
        }

        $pp = $lead->passport_available;
        if ($pp === true) {
            [$ppVal, $ppReason] = ['Yes', ''];
        } elseif ($pp === false) {
            $ppVal = 'No';
            $ppReason = trim((string) $lead->passport_reason) ?: 'N/A';
        } else {
            [$ppVal, $ppReason] = ['N/A', ''];
        }

        $intro = trim($intro) ?: 'Please share your best quotation for the travel requirement below.';
        $childAge = trim((string) $lead->child_ages) ?: 'Nil';
        $special = trim((string) $lead->special_requests) ?: 'Nil';

        $lines = [
            'Dear Team,', '', 'Greetings from Indian Rounds!', '', $intro, '',
            'TRIP DETAILS',
            'Lead ID       : '.self::v($lead->lead_id),
            'Destination   : '.self::v($lead->destination),
            'Travel Date   : '.self::fmtDate($lead->travel_date),
            'Trip End Date : '.self::fmtDate($lead->trip_end_date),
            'Duration      : '.self::v($lead->duration_label),
            'Package Type  : '.self::v($lead->package_type),
        ];
        if ($stay) {
            $lines = array_merge($lines, ['', 'STAY DETAILS'], $stay);
        }
        $lines = array_merge($lines, [
            '', 'ACCOMMODATION',
            'Hotel Category: '.self::v($lead->hotel_category),
            'Meal Plan     : '.self::v($lead->meal_plan),
            'Rooms         : '.self::v($lead->rooms),
            'Room Type     : '.self::v($lead->room_type),
            '', 'GUEST DETAILS',
            'Adults        : '.self::v($lead->adults),
            'Children      : '.self::v($lead->children),
            'Child Age     : '.$childAge,
            '', 'PASSPORT',
            'Passport      : '.$ppVal,
        ]);
        if ($ppReason !== '') {
            $lines[] = 'Reason        : '.$ppReason;
        }
        $lines = array_merge($lines, [
            '', 'SPECIAL REQUESTS', $special, '',
            'We look forward to receiving your best rates.', '',
            'Regards,', 'sri', '+91 7305669082', 'sales operation team', 'Indian Rounds',
        ]);
        return implode("\n", $lines);
    }
}
