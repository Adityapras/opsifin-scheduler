<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Pekerjaan internal aplikasi
|--------------------------------------------------------------------------
|
| Sengaja lewat penjadwal Laravel, bukan lewat crontab yang di-generate
| aplikasi. Pemeriksaan missed run harus tetap hidup justru ketika crontab
| hasil render bermasalah — kalau keduanya dijadwalkan di tempat yang sama,
| satu kegagalan akan mematikan job sekaligus alarmnya.
|
| Butuh satu baris di crontab server:
|   * * * * * cd /opt/opsifin-cron && php artisan schedule:run >> /dev/null 2>&1
|
*/

Schedule::command('cron:check-missed')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('cron:purge-runs')
    ->dailyAt('03:15')
    ->withoutOverlapping();
