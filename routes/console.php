<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Weekly batch job. Enable a system cron/Task Scheduler for `php artisan schedule:run`.
// Skips training when there is not enough implicit evidence.
Schedule::command('search:retrain')->weeklyOn(1, '03:00')->withoutOverlapping();
