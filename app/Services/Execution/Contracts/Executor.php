<?php

namespace App\Services\Execution\Contracts;

use App\Models\Client;
use App\Models\Run;
use App\Models\TaskTemplate;
use App\Services\Execution\Dto\ExecutionResult;
use App\Services\Execution\Dto\ResolvedRequest;

interface Executor
{
    public function resolve(
        TaskTemplate $template,
        Client $client,
        ?Run $run = null,
    ): ResolvedRequest;

    public function execute(ResolvedRequest $request): ExecutionResult;

    public function describe(ResolvedRequest $request, bool $revealSecrets = false): string;
}
