<?php

namespace App\Jobs;

use App\Services\Scheduling\RunWorker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecuteRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $runId) {}

    public function handle(RunWorker $worker): void
    {
        $worker->process($this->runId);
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ['run:'.$this->runId];
    }
}
