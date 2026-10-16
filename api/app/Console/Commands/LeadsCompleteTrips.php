<?php

namespace App\Console\Commands;

use App\Services\LeadStatusFlow;
use Illuminate\Console\Command;

/** Booked leads whose trip date has passed become Completed. Runs daily. */
class LeadsCompleteTrips extends Command
{
    protected $signature = 'leads:complete-trips';

    protected $description = 'Mark Booked leads Completed once their trip has ended';

    public function handle(): int
    {
        $this->info(LeadStatusFlow::completeFinishedTrips().' lead(s) marked Completed.');

        return self::SUCCESS;
    }
}
