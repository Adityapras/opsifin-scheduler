<?php

namespace App\Console\Commands;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Services\Scheduling\RunDispatcher;
use Illuminate\Console\Command;
use Throwable;

class ReconcileQueuedRunsCommand extends Command
{
    protected $signature = 'jobs:reconcile-queued {--limit=500 : Maximum queued runs to inspect}';

    protected $description = 'Publish queued runs that were committed before their Redis payload was created';

    public function handle(RunDispatcher $dispatcher): int
    {
        $ids = Run::query()
            ->where('status', RunStatus::Queued->value)
            ->whereNull('queue_job_id')
            ->where('queued_at', '<=', now()->subMinute())
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id');

        $published = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $run = Run::query()->find($id);

                if ($run === null || $run->status !== RunStatus::Queued || $run->queue_job_id !== null) {
                    continue;
                }

                $dispatcher->enqueue($run);
                $published++;
            } catch (Throwable $exception) {
                $errors[] = 'run '.$id.': '.$exception->getMessage();
                report($exception);
            }
        }

        $this->components->info("Inspected {$ids->count()} queued run(s); published {$published}.");

        foreach ($errors as $error) {
            $this->components->error($error);
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
