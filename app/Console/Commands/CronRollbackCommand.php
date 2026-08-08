<?php

namespace App\Console\Commands;

use App\Services\Crontab\CrontabDeployer;
use Illuminate\Console\Command;
use Throwable;

class CronRollbackCommand extends Command
{
    protected $signature = 'cron:rollback
        {--backup= : Path file backup tertentu (default: yang terbaru)}
        {--output= : Kembalikan ke path ini alih-alih file cron.d sebenarnya}
        {--list : Tampilkan daftar backup yang tersedia}';

    protected $description = 'Kembalikan file cron.d ke versi backup sebelumnya';

    public function handle(CrontabDeployer $deployer): int
    {
        $backups = $deployer->backups();

        if ($this->option('list')) {
            if ($backups === []) {
                $this->warn('Belum ada backup.');

                return self::SUCCESS;
            }

            $this->table(['Backup', 'Ukuran', 'Waktu'], array_map(fn ($f) => [
                basename($f),
                filesize($f).' B',
                date('Y-m-d H:i:s', filemtime($f)),
            ], $backups));

            return self::SUCCESS;
        }

        try {
            $result = $deployer->rollback(
                $this->option('backup'),
                $this->option('output') ?: null,
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Dikembalikan: '.$result['path']);
        $this->line('Dari backup : '.$result['restored_from']);

        return self::SUCCESS;
    }
}
