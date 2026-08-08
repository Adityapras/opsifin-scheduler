<?php

namespace App\Services\LegacyImport\Dto;

/**
 * Hasil parsing satu invocation `curl` dari sebuah shell script legacy.
 */
class ParsedCurl
{
    public function __construct(
        public string $method,
        public ?string $rawUrl,          // URL apa adanya (bisa masih mengandung ${VAR})
        public ?string $scheme,
        public ?string $host,
        public ?string $path,
        public ?string $body,
        /** @var array<string, string> */
        public array $headers = [],
        public ?string $authUsername = null,
        public ?string $authPassword = null,
        public ?string $authScheme = null,   // Basic | Bearer | null
        public ?string $secretKey = null,
        public ?int $maxTime = null,
        public ?int $connectTimeout = null,
        /** @var array<int, string> */
        public array $problems = [],
        /** URL yang ditemukan di baris terpisah — curl sendiri dijalankan tanpa URL. */
        public ?string $danglingUrl = null,
    ) {}

    public function baseUrl(): ?string
    {
        return $this->host ? $this->scheme.'://'.$this->host : null;
    }

    /**
     * Header yang perlu disimpan eksplisit di template. Authorization dibuang
     * karena selalu diturunkan dari kredensial client; Content-Type dan Accept
     * dibuang karena selalu application/json di seluruh 476 script.
     *
     * SecretKey tetap ikut — nilainya per-client, jadi disimpan sebagai
     * placeholder di template (lihat LegacyImporter::normalizeTemplateHeaders).
     *
     * @return array<string, string>
     */
    public function extraHeaders(): array
    {
        $ignored = ['authorization', 'content-type', 'accept'];

        return array_filter(
            $this->headers,
            fn (string $k) => ! in_array(strtolower($k), $ignored, true),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
