<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Twelve lead statuses become six (New Lead, In Progress, Quote Sent to Customer, Booked, Completed, Lost).
 * Follow-ups are Activities (already), so the old Follow-up status just becomes In Progress.
 */
return new class extends Migration
{
    private const MAP = [
        'Supplier Mail Sent' => 'In Progress', 'Quotation Awaiting' => 'In Progress', 'Quote Received' => 'In Progress', 'Follow-up' => 'In Progress',
        'Payment Pending' => 'Booked', 'Payment Received' => 'Booked',
        'Travel Completed' => 'Completed', 'Closed' => 'Completed', 'Cancelled' => 'Lost',
    ];

    public function up(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->string('lost_reason', 120)->nullable();      // why a lead was lost (required when someone marks it Lost)
        });

        foreach (self::MAP as $old => $new) {
            DB::table('leads')->where('lead_status', $old)->update(['lead_status' => $new]);
        }
        // the dashboards count bookings through booking_status, so it follows the status
        DB::table('leads')->whereIn('lead_status', ['Booked', 'Completed'])->update(['booking_status' => 'Booked']);
    }

    public function down(): void
    {
        // the old names are not restored (several became one); the column goes
        Schema::table('leads', fn (Blueprint $t) => $t->dropColumn('lost_reason'));
    }
};
