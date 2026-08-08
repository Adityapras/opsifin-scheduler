<?php

namespace App\Console\Commands;

use App\Services\Crontab\CrontabDeployer;
use Illuminate\Console\Command;
use Throwable;

class CronRollbackCommand extends Command
{
    protected $signature = 'cron:rollback
        {--backup= : Path of a specific backup file (default: the most recent)}
        {--output= : Restore to this path instead of the real cron.d file}
        {--list : List the available backups}';

    protected $description = 'Restore the cron.d file to a previous backup';

    public function handle(CrontabDeployer $deployer): int
    {
        $backups = $deployer->backups();

        if ($this->option('list')) {
            if ($backups === []) {
                $this->warn('No backups yet.');

                return self::SUCCESS;
            }

            $this->table(['Backup', 'Size', 'Time'], array_map(fn ($f) => [
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

        $this->info('Restored  : '.$result['path']);
        $this->line('From backup: '.$result['restored_from']);

        return self::SUCCESS;
    }
}
