<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Deal;
use App\Models\Lead;
use App\Support\ActivityLogger;
use Illuminate\Support\Carbon;

/** The lead status changes that happen by themselves, so the team does not have to click through every step. */
class LeadStatusFlow
{
    /** Statuses a lead may still be moved forward from automatically. */
    public const OPEN = ['New Lead', 'In Progress', 'Quote Sent to Customer'];

    /** Extra fields that follow a status: Booked / Completed leads count as booked on the dashboards. */
    public static function sideEffects(string $to, ?string $currentBooking, ?string $currentStatus): array
    {
        $out = [];
        if (in_array($to, ['Booked', 'Completed'], true)) {
            $out['booking_status'] = 'Booked';
        } elseif ($currentBooking === 'Booked') {
            $out['booking_status'] = '';
        }
        if ($currentStatus === 'Lost' && $to !== 'Lost') {
            $out['lost_reason'] = null;                  // reopened
        }

        return $out;
    }

    /** A lost lead has nothing left to chase: remove its scheduled (not yet completed) follow-up activities. Returns how many were removed. */
    public static function clearOpenFollowUps(string $leadId): int
    {
        return Activity::where('lead_id', $leadId)->where('type', 'follow_up')->whereNull('completed_at')->delete();
    }

    /**
     * Move the leads (of a deal, or one lead id) to $to, but only those currently in one of $from. Returns how many moved.
     *
     * @param  list<string>  $from
     */
    public static function advance(Deal|string $of, string $to, array $from = self::OPEN, string $why = '', ?string $lostReason = null): int
    {
        $q = $of instanceof Deal ? Lead::where('deal_id', $of->id) : Lead::where('lead_id', $of);
        $n = 0;
        foreach ($q->whereIn('lead_status', $from)->get() as $lead) {
            $fields = ['lead_status' => $to, 'updated_by' => 'system'] + self::sideEffects($to, $lead->booking_status, $lead->lead_status);
            if ($to === 'Lost') {
                $fields['lost_reason'] = $lostReason ?: 'Lost with the deal';
            }
            $before = $lead->lead_status;
            $lead->forceFill($fields)->save();
            if ($to === 'Lost') {
                self::clearOpenFollowUps($lead->lead_id);
            }
            ActivityLogger::log(null, 'auto_lead_status', 'lead', $lead->lead_id, ['lead_status' => $before], ['lead_status' => $to, 'why' => $why]);
            $n++;
        }

        return $n;
    }

    /** Booked leads whose trip has ended (the trip's end date, else its travel date, is before today in India) become Completed. */
    public static function completeFinishedTrips(): int
    {
        $today = Carbon::now('Asia/Kolkata')->startOfDay();
        $n = 0;
        foreach (Lead::where('lead_status', 'Booked')->get(['lead_id', 'travel_date', 'trip_end_date']) as $l) {
            $end = trim((string) ($l->trip_end_date ?: $l->travel_date));
            if ($end === '') {
                continue;
            }
            try {
                $d = Carbon::parse(substr($end, 0, 10), 'Asia/Kolkata')->startOfDay();
            } catch (\Throwable) {
                continue;
            }
            if ($d->lt($today)) {
                $n += self::advance($l->lead_id, 'Completed', ['Booked'], 'trip date passed');
            }
        }

        return $n;
    }
}
