<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Supplier replies from the shared Microsoft 365 mailbox. Needs the scheduler: `* * * * * php artisan schedule:run`
Schedule::command('mail:poll-replies')->everyMinute()->withoutOverlapping(10);
