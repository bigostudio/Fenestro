<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// PROJ STATUS COMM colour reset: a selected status turns from green to red
// 4 days after it was selected (the status value is never changed). Hourly;
// idempotent (each selection's reset is recorded once).
// Requires the Laravel scheduler cron on the server:
//   * * * * * cd /path/to/FenestroAPI && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('cns:reset-communication')
    ->hourlyAt(5)
    ->timezone(config('cns.comm_reset.timezone', 'Asia/Kolkata'))
    ->withoutOverlapping();
