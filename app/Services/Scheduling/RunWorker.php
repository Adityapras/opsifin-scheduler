<?php

namespace App\Services\Scheduling;

use App\Services\Execution\ExecutorManager;
use Throwable;

class RunWorker
{
    public const DONE = 'done';

    public function __construct(
        private readonly RunExecutionLifecycle $lifecycle,
        private readonly ExecutorManager $executors,
    ) {}

    public function process(int $runId): string
    {
        $execution = $this->lifecycle->claim($runId);
        if ($execution === null) {
            return self::DONE;
        }
        try {
            $result = $this->executors->for($execution->run->schedule->taskTemplate)->execute($execution->request);
            $this->lifecycle->complete($execution, $result);
        } catch (Throwable $exception) {
            $this->lifecycle->fail($execution, $exception->getMessage());
        }

        return self::DONE;
    }
}
