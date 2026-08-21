<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('jobs:dispatch-due')
    ->everyMinute()
    ->name('opsifin:dispatch-due')
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('jobs:reconcile-queued')
    ->everyMinute()
    ->name('opsifin:reconcile-queued')
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('cron:purge-runs')
    ->dailyAt('03:00')
    ->name('opsifin:purge-runs')
    ->withoutOverlapping(60);

Schedule::command('telescope:prune --hours=168')
    ->dailyAt('02:30')
    ->name('opsifin:telescope-prune')
    ->withoutOverlapping(60);

Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->name('opsifin:horizon-snapshot')
    ->withoutOverlapping(10);
