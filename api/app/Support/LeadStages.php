<?php

namespace App\Support;

/**
 * The few stages the Leads page groups the lead statuses into. A stage is only a grouping:
 * every lead keeps its precise status, and each stage holds one or more of them.
 */
class LeadStages
{
    public const MAP = [
        'new' => ['New Lead'],
        'progress' => ['Supplier Mail Sent', 'Quotation Awaiting', 'Quote Received', 'Follow-up'],
        'quoted' => ['Quote Sent to Customer'],
        'booked' => ['Booked', 'Payment Pending', 'Payment Received'],
        'completed' => ['Travel Completed', 'Closed'],
        'lost' => ['Cancelled'],
    ];

    /** @return list<string>|null the statuses of a stage, or null when the stage is unknown */
    public static function statuses(string $stage): ?array
    {
        return self::MAP[$stage] ?? null;
    }

    public static function of(?string $status): ?string
    {
        foreach (self::MAP as $stage => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $stage;
            }
        }

        return null;
    }
}
