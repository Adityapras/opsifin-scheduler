<?php

namespace App\Services\Execution;

use App\Enums\ExecutorType;
use App\Models\TaskTemplate;
use App\Services\Execution\Contracts\Executor;

class ExecutorManager
{
    public function for(TaskTemplate $template): Executor
    {
        return match ($template->executor) {
            ExecutorType::Http => app(HttpExecutor::class),
        };
    }
}
