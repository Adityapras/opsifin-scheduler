<?php

namespace App\Console\Commands;

use App\Services\Maintenance\RetentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Retensi tabel `runs`. Tanpa ini tabelnya tumbuh terus — 236 schedule aktif
 * dengan interval 6 menit saja sudah menghasilkan sekitar 1,7 juta baris setahun.
 */
class CronPurgeRunsCommand extends Command
{
    protected $signature = 'cron:purge-runs
        {--days= : Keep this many days (default: config opsifin_cron.runs_retention_days)}
        {--dry-run : Report what would be deleted without deleting it}
        {--chunk=1000 : How many rows to delete per statement}';

    protected $description = 'Delete terminal run history older than the retention window';

    public function handle(RetentionService $retention): int
    {
        // blank(), bukan ?: — dengan `?:` sebuah `--days=0` yang eksplisit akan
        // jatuh diam-diam ke nilai default alih-alih ditolak.
        $option = $this->option('days');
        $days = blank($option) ? (int) config('opsifin_cron.runs_retention_days') : (int) $option;

        if ($days < 1) {
            $this->error('Retention must be at least 1 day.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $this->line('Run cutoff      : '.$cutoff->toDateTimeString().' ('.$days.' days)');
        $this->line('Mode   : '.($dryRun ? 'DRY RUN (nothing is deleted)' : 'DELETE'));
        $this->newLine();

        $count = $retention->count($days);

        $this->table(['Table', 'Rows older than cutoff'], [
            ['runs', number_format($count)],
        ]);

        if ($dryRun || $count === 0) {
            return self::SUCCESS;
        }

        $chunk = max(100, (int) $this->option('chunk'));

        $deleted = $retention->purge($days, $chunk);

        $this->newLine();
        $this->info('Deleted '.number_format($deleted).' runs.');

        return self::SUCCESS;
    }
}
