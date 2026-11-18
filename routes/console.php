<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Safety net for lost webhooks. Must run on every environment that takes generations.
Schedule::command('generations:reconcile')->everyMinute()->withoutOverlapping();
