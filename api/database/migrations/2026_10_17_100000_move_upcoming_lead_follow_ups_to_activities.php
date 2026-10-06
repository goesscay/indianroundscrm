<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The lead page no longer has its own follow-up date: follow-ups are Activities (which can be marked done).
 * Upcoming dates on open leads become follow-up activities so nothing is lost; dates already in the past are
 * dropped, they were the "aged" ones nobody could mark as done. The old column is left in place.
 */
return new class extends Migration
{
    private const CLOSED = ['Booked', 'Payment Received', 'Travel Completed', 'Closed', 'Cancelled'];

    public function up(): void
    {
        $today = Carbon::now('Asia/Kolkata')->startOfDay();
        $rows = DB::table('leads')->whereNotNull('follow_up_date')->where('follow_up_date', '<>', '')->whereNotIn('lead_status', self::CLOSED)
            ->get(['lead_id', 'customer_name', 'follow_up_date', 'staff_id']);
        foreach ($rows as $l) {
            try {
                $due = str_contains($l->follow_up_date, 'T') ? Carbon::parse($l->follow_up_date) : Carbon::parse(substr($l->follow_up_date, 0, 10).' 09:00:00', 'Asia/Kolkata');
            } catch (\Throwable) {
                continue;
            }
            if ($due->copy()->timezone('Asia/Kolkata')->lt($today)) {
                continue;
            }
            $has = DB::table('activities')->where('lead_id', $l->lead_id)->where('type', 'follow_up')->whereNull('completed_at')->exists();
            if ($has) {
                continue;
            }
            $assignee = $l->staff_id && DB::table('users')->where('id', $l->staff_id)->where('active', true)->exists() ? $l->staff_id : null;
            DB::table('activities')->insert([
                'type' => 'follow_up', 'title' => mb_substr('Follow up with '.$l->customer_name, 0, 200), 'priority' => 'medium',
                'due_at' => $due->utc(), 'assigned_to' => $assignee, 'lead_id' => $l->lead_id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // not reversible: the follow-up activities are ordinary activities now
    }
};
