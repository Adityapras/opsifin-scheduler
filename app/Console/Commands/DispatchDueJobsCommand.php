<?php

namespace App\Console\Commands;

use App\Services\Scheduling\DueScheduleDispatcher;
use Illuminate\Console\Command;

class DispatchDueJobsCommand extends Command
{
    protected $signature = 'jobs:dispatch-due';

    protected $description = 'Queue enabled HTTP jobs whose next run time is due';

    public function handle(DueScheduleDispatcher $dispatcher): int
    {
        $report = $dispatcher->dispatch();

        $this->info(
            "Scanned {$report['scanned']}; queued {$report['queued']}; "
            ."skipped {$report['skipped']}; recovered {$report['recovered']}."
        );

        foreach ($report['errors'] as $error) {
            $this->error($error);
        }

        return $report['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
