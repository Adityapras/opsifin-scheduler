<?php

namespace App\Services\Execution\Dto;

final readonly class ExecutionResult
{
    public function __construct(
        public bool $success,
        public ?int $statusCode,
        public ?string $outputExcerpt,
        public ?string $errorMessage,
        public int $durationMs,
    ) {}
}
