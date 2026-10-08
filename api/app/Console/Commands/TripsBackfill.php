<?php

namespace App\Console\Commands;

use App\Models\Deal;
use App\Services\TripPlan;
use Illuminate\Console\Command;

/** Creates trip plans for won deals that were booked before trip plans existed (travel date today or later, advance received). Safe to run again. */
class TripsBackfill extends Command
{
    protected $signature = 'trips:backfill {--dry-run : only list what would be confirmed}';

    protected $description = 'Confirm existing won deals that have an advance and have not travelled yet, creating their trip plans';

    public function handle(): int
    {
        $today = now('Asia/Kolkata')->toDateString();
        $n = 0;
        Deal::query()->where('stage', 'won')->whereNull('trip_confirmed_at')->where(fn ($w) => $w->whereNull('travel_date')->orWhere('travel_date', '>=', $today))
            ->where(fn ($w) => $w->whereNull('trip_status')->orWhere('trip_status', '<>', 'completed'))->orderBy('id')->each(function (Deal $deal) use (&$n) {
                if (! TripPlan::payment($deal)['advance_received']) {
                    return;
                }
                $this->line("{$deal->deal_no}  {$deal->name}  ".($deal->travel_date?->format('Y-m-d') ?? 'no date'));
                if (! $this->option('dry-run')) {
                    TripPlan::confirm($deal, null, TripPlan::inferType($deal), 'backfill');
                }
                $n++;
            });
        $this->info(($this->option('dry-run') ? 'Would confirm ' : 'Confirmed ')."$n trip(s).");

        return self::SUCCESS;
    }
}
