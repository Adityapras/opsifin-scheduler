<?php

namespace App\Services\Execution\Dto;

use App\Models\Run;

final readonly class RunExecution
{
    public function __construct(
        public Run $run,
        public ResolvedRequest $request,
        public float $started,
    ) {}
}
