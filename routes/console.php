<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('orders:dispatch-mail')->everyMinute()->withoutOverlapping();

if (config('backup.enabled')) {
    Schedule::command('database:backup')->dailyAt('02:00')->timezone('Europe/Rome')->withoutOverlapping(120);
    Schedule::command('database:backup-verify')->weeklyOn(0, '04:00')->timezone('Europe/Rome')->withoutOverlapping(120);
    Schedule::command('database:backup --check')->dailyAt('06:00')->timezone('Europe/Rome')->withoutOverlapping();
}
