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
        {--dry-run : Parse and report only, without writing to the database}
        {--fresh : Empty clients/task_templates/overrides/schedules before importing}
        {--report= : Write the markdown reconciliation report to this path}';

    protected $description = 'Import the crontab, configs/*.conf and every legacy .sh script into the database';

    public function handle(LegacyImporter $importer, ReconciliationReport $report): int
    {
        $source = $this->option('source') ?: config('opsifin_cron.source_path');

        if (! $source) {
            $this->error('Source path is not set. Fill in CRON_SOURCE_PATH in .env or pass --source=');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info('Source   : '.$source);
        $this->info('Mode     : '.($dryRun ? 'DRY RUN (no database writes)' : 'APPLY'));

        DB::beginTransaction();

        try {
            if ($this->option('fresh')) {
                $this->truncateDomainTables();
                $this->warn('Domain tables emptied (--fresh).');
            }

            $importRun = $importer->import($source, $dryRun);

            $this->renderSummary($importRun);

            $path = $this->option('report') ?: storage_path('app/import-reports/rekonsiliasi-'.now()->format('Ymd-His').'.md');
            $report->write($importRun, $path);
            $this->newLine();
            $this->info('Reconciliation report: '.$path);

            if ($dryRun) {
                DB::rollBack();
                $this->warn('Dry run — every database change was rolled back.');
            } else {
                DB::commit();
                $this->info('Import saved.');
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Import failed: '.$e->getMessage());
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
        $this->line('<options=bold>Import summary</>');
        $this->table(['Metric', 'Value'], [
            ['Client folders scanned', $stats['client_folders'] ?? 0],
            ['.sh scripts parsed', $stats['client_scripts'] ?? 0],
            ['Scripts without --max-time', $stats['scripts_without_max_time'] ?? 0],
            ['Config files (.conf)', $stats['config_files'] ?? 0],
            ['Gateway jobs', $stats['gateway_jobs'] ?? 0],
            ['Gateway routes', $stats['gateway_routes'] ?? 0],
            ['—', '—'],
            ['Clients', Client::count()],
            ['Task templates', TaskTemplate::count()],
            ['Client task overrides', ClientTaskOverride::count()],
            ['Schedules', Schedule::count()],
            ['— enabled', Schedule::where('is_enabled', true)->count()],
            ['— disabled (commented out)', Schedule::where('is_enabled', false)->count()],
            ['—', '—'],
            ['Crontab entries read', $stats['crontab_entries'] ?? 0],
            ['— could not be mapped', $stats['entries_skipped'] ?? 0],
            ['flock coverage in the old crontab', ($stats['legacy_active_with_flock'] ?? 0).' of '.($stats['entries_active'] ?? 0)],
        ]);

        $bySeverity = $importRun->findings->countBy(fn ($f) => $f->severity->value);

        $this->newLine();
        $this->line('<options=bold>Findings</>');
        $this->table(['Severity', 'Count'], [
            ['error (parse failure / must be fixed)', $bySeverity['error'] ?? 0],
            ['warning (needs manual review)', $bySeverity['warning'] ?? 0],
            ['info', $bySeverity['info'] ?? 0],
        ]);
    }
}
