<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Safety net for lost webhooks. Must run on every environment that takes generations.
Schedule::command('generations:reconcile')->everyMinute()->withoutOverlapping();

// Hosts without a persistent worker (shared hosting) only need one cron line, `schedule:run`: it
// drains the queue each minute and exits. The lock expires after 2 minutes so a killed run cannot
// block the queue for a day. Harmless next to a real `queue:work` process.
Schedule::command('queue:work --stop-when-empty --tries=0 --max-time=50')
    ->everyMinute()
    ->withoutOverlapping(2);
