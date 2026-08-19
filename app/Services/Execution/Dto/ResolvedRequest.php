<?php

namespace App\Services\Execution\Dto;

final readonly class ResolvedRequest
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<int, string>  $sensitiveValues
     */
    public function __construct(
        public string $method,
        public string $url,
        public ?string $body,
        public array $headers,
        public int $timeoutSec,
        public int $connectTimeoutSec,
        public array $sensitiveValues = [],
    ) {}

    public function redact(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        foreach ($this->sensitiveValues as $secret) {
            if ($secret !== '') {
                $value = str_replace($secret, '••••••••', $value);
            }
        }

        return $value;
    }
}
