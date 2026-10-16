<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Supplier replies from the shared Microsoft 365 mailbox. Needs the scheduler: `* * * * * php artisan schedule:run`
Schedule::command('mail:poll-replies')->everyMinute()->withoutOverlapping(10);

// Payment reminders for overdue invoices, 10:00 IST daily. Does nothing unless CRM_AUTO_REMINDERS=true.
Schedule::command("invoices:remind")->dailyAt("10:00")->timezone("Asia/Kolkata")->withoutOverlapping(30);

// Trip preparation: overdue checklist items as tasks for the team, and (when enabled) document reminders to customers. 09:00 IST daily.
Schedule::command("trips:remind")->dailyAt("09:00")->timezone("Asia/Kolkata")->withoutOverlapping(30);

// Reminds portal suppliers who have not quoted an enquiry that is due. 11:00 IST daily. Does nothing unless CRM_AUTO_PORTAL_REMINDERS=true.
Schedule::command("portal:remind")->dailyAt("11:00")->timezone("Asia/Kolkata")->withoutOverlapping(30);

// Brings the tours of the public website in as packages, 03:30 IST daily. Paused with CRM_WEBSITE_SYNC=false.
Schedule::command("packages:sync-website")->dailyAt("03:30")->timezone("Asia/Kolkata")->withoutOverlapping(30);

// Booked leads whose trip date has passed become Completed. 02:00 IST daily.
Schedule::command("leads:complete-trips")->dailyAt("02:00")->timezone("Asia/Kolkata")->withoutOverlapping(30);
