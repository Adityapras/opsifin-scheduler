<?php

namespace App\Console\Commands;

use App\Services\Alerting\AlertEvaluator;
use Illuminate\Console\Command;

/**
 * Dead man's switch di dalam aplikasi.
 *
 * Kegagalan job terdeteksi sendiri lewat tabel `runs`, tapi cron yang mati
 * tidak meninggalkan jejak apa pun — tidak ada baris yang gagal, hanya
 * kekosongan. Perintah inilah yang mencari kekosongan itu, dan karena dirinya
 * sendiri dijalankan penjadwal Laravel, ia berjalan di luar crontab yang
 * di-generate aplikasi.
 */
class CronCheckMissedCommand extends Command
{
    protected $signature = 'cron:check-missed';

    protected $description = 'Look for enabled schedules that should have run but did not, and raise alerts';

    public function handle(AlertEvaluator $evaluator): int
    {
        $alerts = $evaluator->evaluateMissedRuns();

        if ($alerts === []) {
            $this->info('No missed runs.');

            return self::SUCCESS;
        }

        $this->warn(count($alerts).' missed run(s):');

        $this->table(['Client', 'Task', 'Alert'], array_map(fn ($alert) => [
            $alert->client?->code ?? '—',
            $alert->taskTemplate?->key ?? '—',
            $alert->title,
        ], $alerts));

        return self::SUCCESS;
    }
}
