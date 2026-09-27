<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('orders:remind-pickups')->dailyAt('08:00')->timezone('Europe/Rome')->withoutOverlapping();

Schedule::command('orders:dispatch-mail')->everyMinute()->withoutOverlapping();

Schedule::call(fn () => cache()->put('scheduler:last_seen_at', now()->timestamp, now()->addDays(2)))->name('scheduler-heartbeat')->everyMinute();

if (config('backup.enabled')) {
    $local = config('backup.mode') === 'local';
    Schedule::command($local ? 'database:backup --local-only' : 'database:backup')->dailyAt('02:00')->timezone('Europe/Rome')->withoutOverlapping(120);
    Schedule::command($local ? 'database:backup-verify --local' : 'database:backup-verify')->weeklyOn(0, '04:00')->timezone('Europe/Rome')->withoutOverlapping(120);
    Schedule::command($local ? 'database:backup --check --local-only' : 'database:backup --check')->dailyAt('06:00')->timezone('Europe/Rome')->withoutOverlapping();
}
