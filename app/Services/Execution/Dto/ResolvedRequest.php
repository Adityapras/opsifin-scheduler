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

        $secrets = $this->sensitiveValues;
        foreach ($this->headers as $name => $header) {
            if (in_array(strtolower($name), ['authorization', 'secretkey', 'x-api-key'], true)) {
                $secrets[] = $header;
                if (str_contains($header, ' ')) {
                    $secrets[] = substr($header, strpos($header, ' ') + 1);
                }
            }
        }
        foreach ($secrets as $secret) {
            $secrets[] = rawurlencode($secret);
            $secrets[] = urlencode($secret);
            foreach ([0, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES] as $flags) {
                $encoded = json_encode($secret, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
                $secrets[] = substr($encoded, 1, -1);
            }
        }
        $secrets = array_values(array_unique(array_filter($secrets, fn ($secret) => $secret !== '')));
        usort($secrets, fn ($a, $b) => strlen($b) <=> strlen($a));
        $value = strtr($value, array_fill_keys($secrets, '••••••••'));

        // A bounded response can end halfway through a credential.
        foreach ($secrets as $secret) {
            for ($length = min(strlen($value), strlen($secret) - 1); $length >= 3; $length--) {
                if (str_ends_with($value, substr($secret, 0, $length))) {
                    $value = substr($value, 0, -$length).'••••••••';
                    break;
                }
            }
        }

        return $value;
    }
}
