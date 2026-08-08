<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientTaskOverride;
use App\Models\ImportRun;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use App\Services\LegacyImport\LegacyImporter;
use App\Services\LegacyImport\ReconciliationReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CronImportCommand extends Command
{
    protected $signature = 'cron:import
        {--source= : Folder repo cron legacy (default: config opsifin_cron.source_path)}
        {--dry-run : Parse dan laporkan saja, tanpa menulis ke database}
        {--fresh : Kosongkan clients/task_templates/overrides/schedules sebelum impor}
        {--report= : Tulis laporan rekonsiliasi markdown ke path ini}';

    protected $description = 'Impor crontab, configs/*.conf, dan seluruh script .sh legacy ke database';

    public function handle(LegacyImporter $importer, ReconciliationReport $report): int
    {
        $source = $this->option('source') ?: config('opsifin_cron.source_path');

        if (! $source) {
            $this->error('Source path belum diset. Isi CRON_SOURCE_PATH di .env atau pakai --source=');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info('Sumber   : '.$source);
        $this->info('Mode     : '.($dryRun ? 'DRY RUN (tidak menulis DB)' : 'APPLY'));

        DB::beginTransaction();

        try {
            if ($this->option('fresh')) {
                $this->truncateDomainTables();
                $this->warn('Tabel domain dikosongkan (--fresh).');
            }

            $importRun = $importer->import($source, $dryRun);

            $this->renderSummary($importRun);

            $path = $this->option('report') ?: storage_path('app/import-reports/rekonsiliasi-'.now()->format('Ymd-His').'.md');
            $report->write($importRun, $path);
            $this->newLine();
            $this->info('Laporan rekonsiliasi: '.$path);

            if ($dryRun) {
                DB::rollBack();
                $this->warn('Dry run — semua perubahan database dibatalkan.');
            } else {
                DB::commit();
                $this->info('Impor tersimpan.');
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Impor gagal: '.$e->getMessage());
            $this->line($e->getTraceAsString());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * DELETE, bukan TRUNCATE: TRUNCATE memicu implicit commit di MySQL sehingga
     * transaksi impor (dan mode --dry-run) jadi tidak berlaku.
     */
    private function truncateDomainTables(): void
    {
        foreach (['runs', 'schedules', 'client_task_overrides', 'task_templates', 'clients'] as $table) {
            DB::table($table)->delete();
        }
    }

    private function renderSummary(ImportRun $importRun): void
    {
        $stats = $importRun->stats ?? [];

        $this->newLine();
        $this->line('<options=bold>Ringkasan impor</>');
        $this->table(['Metrik', 'Nilai'], [
            ['Folder client dipindai', $stats['client_folders'] ?? 0],
            ['Script .sh diparse', $stats['client_scripts'] ?? 0],
            ['Script tanpa --max-time', $stats['scripts_without_max_time'] ?? 0],
            ['File config (.conf)', $stats['config_files'] ?? 0],
            ['Job gateway', $stats['gateway_jobs'] ?? 0],
            ['Routing gateway', $stats['gateway_routes'] ?? 0],
            ['—', '—'],
            ['Clients', Client::count()],
            ['Task templates', TaskTemplate::count()],
            ['Client task overrides', ClientTaskOverride::count()],
            ['Schedules', Schedule::count()],
            ['— aktif', Schedule::where('is_enabled', true)->count()],
            ['— nonaktif (di-comment)', Schedule::where('is_enabled', false)->count()],
            ['—', '—'],
            ['Entry crontab terbaca', $stats['crontab_entries'] ?? 0],
            ['— tidak bisa dipetakan', $stats['entries_skipped'] ?? 0],
            ['Coverage flock di crontab lama', ($stats['legacy_active_with_flock'] ?? 0).' dari '.($stats['entries_active'] ?? 0)],
        ]);

        $bySeverity = $importRun->findings->countBy(fn ($f) => $f->severity->value);

        $this->newLine();
        $this->line('<options=bold>Temuan</>');
        $this->table(['Severity', 'Jumlah'], [
            ['error (gagal parse / wajib diperbaiki)', $bySeverity['error'] ?? 0],
            ['warning (perlu review manual)', $bySeverity['warning'] ?? 0],
            ['info', $bySeverity['info'] ?? 0],
        ]);
    }
}
