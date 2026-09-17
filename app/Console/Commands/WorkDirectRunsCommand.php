<?php

namespace App\Console\Commands;

use App\Services\Scheduling\DirectPoolExecutor;
use Illuminate\Console\Command;
use Throwable;

class WorkDirectRunsCommand extends Command
{
    protected $signature = 'jobs:work-direct {--once : Drain up to batch_limit then exit} {--max-seconds=0 : Stop admission after this many seconds and drain in-flight requests}';

    protected $description = 'Execute pending HTTP runs with bounded concurrency and no retry';

    public function handle(DirectPoolExecutor $executor): int
    {
        $stop = false;
        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT], function () use ($executor, &$stop): void {
                $stop = true;
                $executor->stop();
            });
        }
        try {
            do {
                $result = $executor->work((bool) $this->option('once'), max(0, (int) $this->option('max-seconds')));
                if ($this->option('once') || $this->option('max-seconds') > 0 || $stop) {
                    $this->line(json_encode($result, JSON_THROW_ON_ERROR));

                    return ! empty($result['failed']) ? self::FAILURE : self::SUCCESS;
                }
                // Queue-mode standby or another live executor: retry without a restart storm.
                usleep(max(100, (int) config('opsifin_cron.direct.idle_delay_ms')) * 1000);
            } while (! $stop);
        } catch (Throwable $error) {
            $this->error('Direct executor stopped ('.class_basename($error).'). Check database availability and direct configuration; stale runs will not be resent.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
