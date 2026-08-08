<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\Run;
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

    protected $description = 'Delete run history and resolved alerts older than the retention window';

    public function handle(): int
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

        $this->line('Cutoff : '.$cutoff->toDateTimeString().' ('.$days.' days)');
        $this->line('Mode   : '.($dryRun ? 'DRY RUN (nothing is deleted)' : 'DELETE'));
        $this->newLine();

        $runs = Run::where('started_at', '<', $cutoff)->count();

        // Alert yang sudah ditutup ikut dibersihkan; yang masih terbuka tidak
        // pernah dihapus, sekalipun sudah tua — justru itu yang perlu dilihat.
        $alerts = Alert::where('fired_at', '<', $cutoff)
            ->whereNot('status', 'open')
            ->count();

        $this->table(['Table', 'Rows older than cutoff'], [
            ['runs', number_format($runs)],
            ['alerts (resolved or acknowledged)', number_format($alerts)],
        ]);

        if ($dryRun || $runs + $alerts === 0) {
            return self::SUCCESS;
        }

        $chunk = max(100, (int) $this->option('chunk'));

        $deletedRuns = $this->deleteInChunks(
            fn () => Run::where('started_at', '<', $cutoff)->limit($chunk)->delete(),
        );

        $deletedAlerts = $this->deleteInChunks(
            fn () => Alert::where('fired_at', '<', $cutoff)->whereNot('status', 'open')->limit($chunk)->delete(),
        );

        $this->newLine();
        $this->info('Deleted '.number_format($deletedRuns).' runs and '.number_format($deletedAlerts).' alerts.');

        return self::SUCCESS;
    }

    /**
     * Dihapus bertahap supaya tidak mengunci tabel lama-lama saat jumlahnya besar.
     */
    private function deleteInChunks(callable $delete): int
    {
        $total = 0;

        do {
            $deleted = (int) $delete();
            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }
}
