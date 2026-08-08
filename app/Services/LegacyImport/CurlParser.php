<?php

namespace App\Services\LegacyImport;

use App\Services\LegacyImport\Dto\ParsedCurl;

/**
 * Membaca satu file .sh legacy dan mengekstrak perintah curl di dalamnya.
 *
 * Seluruh 476 script client berbentuk satu invocation curl, tapi ada dua
 * variasi yang harus ditangani: satu baris panjang, dan multi-baris dengan
 * continuation backslash (dipakai di jobs/*.sh).
 */
class CurlParser
{
    /**
     * Flag curl yang membawa argumen terpisah. Tanpa daftar ini, nilai seperti
     * user-agent pada `-A "Mozilla/5.0 ..."` akan salah dibaca sebagai URL.
     */
    private const FLAGS_WITH_VALUE = [
        '-A', '--user-agent', '-b', '--cookie', '-c', '--cookie-jar', '-e', '--referer',
        '-o', '--output', '-F', '--form', '--form-string', '-x', '--proxy', '-T',
        '--upload-file', '--cacert', '--capath', '--cert', '--key', '--retry',
        '--retry-delay', '--retry-max-time', '-w', '--write-out', '-K', '--config',
        '--interface', '--limit-rate', '--resolve', '--proto', '-y', '--speed-time',
        '-Y', '--speed-limit', '--data-urlencode', '-E',
    ];

    /**
     * @param  array<string, string>  $variables  substitusi ${VAR} (dari opsifin_env.sh / *.conf)
     */
    public function parseFile(string $contents, array $variables = []): ?ParsedCurl
    {
        $command = $this->extractCurlCommand($contents);

        if ($command === null) {
            return null;
        }

        $parsed = $this->parseCommand($this->substitute($command, $variables));

        if ($parsed->rawUrl === null) {
            $parsed->danglingUrl = $this->findDanglingUrl($this->substitute($contents, $variables));
        }

        return $parsed;
    }

    /**
     * Beberapa script punya URL di baris berikutnya tanpa backslash continuation,
     * sehingga curl dijalankan tanpa URL sama sekali dan job selalu gagal.
     */
    private function findDanglingUrl(string $contents): ?string
    {
        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            $trimmed = trim($line);

            if (preg_match('#^["\']?https?://\S+["\']?$#', $trimmed)) {
                return trim($trimmed, '"\'');
            }
        }

        return null;
    }

    /**
     * Gabungkan continuation line, lalu ambil perintah curl-nya saja.
     */
    private function extractCurlCommand(string $contents): ?string
    {
        $joined = preg_replace('/\\\\\r?\n\s*/', ' ', $contents);
        $lines = preg_split('/\r?\n/', (string) $joined);

        foreach ($lines as $line) {
            $trimmed = ltrim($line);

            if (str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/(^|[;&|]\s*)curl\s/', $trimmed)) {
                $pos = strpos($trimmed, 'curl ');

                return substr($trimmed, $pos);
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function substitute(string $text, array $variables): string
    {
        foreach ($variables as $name => $value) {
            $text = str_replace(['${'.$name.'}', '$'.$name], $value, $text);
        }

        return $text;
    }

    public function parseCommand(string $command): ParsedCurl
    {
        $tokens = $this->tokenize($command);
        $problems = [];

        $method = null;
        $body = null;
        $headers = [];
        $url = null;
        $maxTime = null;
        $connectTimeout = null;

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            switch ($token) {
                case '-X':
                case '--request':
                    $method = strtoupper($tokens[++$i] ?? 'GET');
                    break;
                case '-d':
                case '--data':
                case '--data-raw':
                case '--data-binary':
                    $body = $tokens[++$i] ?? null;
                    break;
                case '-H':
                case '--header':
                    $raw = $tokens[++$i] ?? '';
                    [$name, $value] = array_pad(explode(':', $raw, 2), 2, '');
                    $name = trim($name);
                    if ($name !== '') {
                        $headers[$name] = trim($value);
                    }
                    break;
                case '-m':
                case '--max-time':
                    $maxTime = (int) ($tokens[++$i] ?? 0);
                    break;
                case '--connect-timeout':
                    $connectTimeout = (int) ($tokens[++$i] ?? 0);
                    break;
                case '--url':
                    $url = $tokens[++$i] ?? null;
                    break;
                case '-u':
                case '--user':
                    $i++; // Basic auth lewat -u tidak dipakai di repo ini; lewati nilainya.
                    break;
                default:
                    if (str_starts_with($token, '-')) {
                        if (in_array($token, self::FLAGS_WITH_VALUE, true)) {
                            $i++;
                        }

                        break; // flag tanpa argumen (-i, -s, --http1.1, ...)
                    }
                    if ($token !== 'curl' && $url === null && $this->looksLikeUrl($token)) {
                        $url = $token;
                    }
                    break;
            }
        }

        if ($url === null) {
            $problems[] = 'URL tidak ditemukan pada perintah curl.';
        }

        if ($method === null) {
            $method = $body !== null ? 'POST' : 'GET';
            $problems[] = 'Method tidak eksplisit (-X), disimpulkan sebagai '.$method.'.';
        }

        if ($maxTime === null) {
            $problems[] = 'Tidak ada --max-time.';
        }

        if ($connectTimeout === null) {
            $problems[] = 'Tidak ada --connect-timeout.';
        }

        $parts = $url ? parse_url($url) : [];
        $path = $parts['path'] ?? null;

        if ($path !== null) {
            // Beberapa script punya double slash: https://host//apiv1/api_all
            $path = '/'.ltrim(preg_replace('#/{2,}#', '/', $path), '/');
        }

        if (isset($parts['query'])) {
            $path .= '?'.$parts['query'];
        }

        $auth = $this->parseAuthorization($headers);

        return new ParsedCurl(
            method: $method,
            rawUrl: $url,
            scheme: $parts['scheme'] ?? null,
            host: $parts['host'] ?? null,
            path: $path,
            body: $body,
            headers: $headers,
            authUsername: $auth['username'],
            authPassword: $auth['password'],
            authScheme: $auth['scheme'],
            secretKey: $this->headerValue($headers, 'SecretKey'),
            maxTime: $maxTime,
            connectTimeout: $connectTimeout,
            problems: $problems,
        );
    }

    private function looksLikeUrl(string $token): bool
    {
        return str_contains($token, '://') || str_starts_with($token, '${') || str_starts_with($token, '$');
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{scheme: ?string, username: ?string, password: ?string}
     */
    private function parseAuthorization(array $headers): array
    {
        $value = $this->headerValue($headers, 'Authorization');

        if ($value === null || $value === '') {
            return ['scheme' => null, 'username' => null, 'password' => null];
        }

        if (preg_match('/^Basic\s+(\S+)$/i', $value, $m)) {
            $decoded = base64_decode($m[1], true);

            if ($decoded === false || ! str_contains($decoded, ':')) {
                return ['scheme' => 'Basic', 'username' => null, 'password' => null];
            }

            [$user, $pass] = explode(':', $decoded, 2);

            return ['scheme' => 'Basic', 'username' => $user, 'password' => $pass];
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', $value, $m)) {
            return ['scheme' => 'Bearer', 'username' => null, 'password' => $m[1]];
        }

        return ['scheme' => null, 'username' => null, 'password' => null];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Pecah command shell menjadi token, menghormati kutip tunggal & ganda.
     *
     * @return array<int, string>
     */
    private function tokenize(string $command): array
    {
        $tokens = [];
        $current = '';
        $quote = null;
        $started = false;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;

                    continue;
                }
                if ($char === '\\' && $quote === '"' && $i + 1 < $length) {
                    $current .= $command[++$i];

                    continue;
                }
                $current .= $char;

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $started = true;

                continue;
            }

            if (preg_match('/\s/', $char)) {
                if ($started || $current !== '') {
                    $tokens[] = $current;
                    $current = '';
                    $started = false;
                }

                continue;
            }

            if ($char === '\\' && $i + 1 < $length) {
                $current .= $command[++$i];

                continue;
            }

            $current .= $char;
        }

        if ($started || $current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }
}
