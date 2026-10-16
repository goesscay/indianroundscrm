<?php

namespace App\Support;

use App\Models\User;

/**
 * The lead statuses, and how the Leads page groups them into tabs. There are only six, and most of them move by themselves:
 * New Lead (created) -> In Progress (a supplier mail goes out) -> Quote Sent (a quote goes to the customer) -> Booked (the deal is won / the trip is confirmed)
 * -> Completed (the trip is done). Lost is the one the team sets by hand, with a reason. Follow-ups are Activities, not a status.
 */
class LeadStages
{
    public const MAP = [
        'new' => ['New Lead'],
        'progress' => ['In Progress'],
        'quoted' => ['Quote Sent to Customer'],
        'booked' => ['Booked'],
        'completed' => ['Completed'],
        'lost' => ['Lost'],
    ];

    /** What a salesperson may set by hand. Booked and Completed come from the deal and the trip, or from Operations. */
    public const SALES_MANUAL = ['In Progress', 'Quote Sent to Customer', 'Lost'];

    public const LOST_REASONS = [
        'Price too high', 'Chose another agent', 'Not responding', 'Plans changed or postponed', 'Not interested', 'Wrong number or duplicate', 'Other',
    ];

    /** The statuses of the old 12-status list, and where each one now lives. */
    public const LEGACY = [
        'Supplier Mail Sent' => 'In Progress', 'Quotation Awaiting' => 'In Progress', 'Quote Received' => 'In Progress', 'Follow-up' => 'In Progress',
        'Payment Pending' => 'Booked', 'Payment Received' => 'Booked',
        'Travel Completed' => 'Completed', 'Closed' => 'Completed', 'Cancelled' => 'Lost',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(...array_values(self::MAP));
    }

    /** An old status name becomes its new one; a current one is returned as it is. */
    public static function normalize(?string $status): ?string
    {
        return self::LEGACY[$status] ?? $status;
    }

    /** @return list<string> the statuses this user may choose by hand */
    public static function manualFor(?User $user): array
    {
        return $user && $user->hasPermission('leads.edit') ? self::all() : self::SALES_MANUAL;
    }

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
