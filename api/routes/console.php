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
