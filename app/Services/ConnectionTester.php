<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Menguji credential Basic Auth sebuah client tanpa efek samping.
 *
 * Seluruh controller REST Opsifin memvalidasi Basic Auth di constructor, sebelum
 * method dipilih: credential salah selalu 401. Probe default (GET
 * /api/remittanceApi) membalas 200 "Connection Success" setelah auth lolos dan
 * hanya membaca data. 405 juga berarti auth lolos (endpoint tanpa handler GET).
 * SecretKey tidak bisa diuji: header itu hanya dicek di jalur POST yang memproses
 * transaksi.
 */
class ConnectionTester
{
    /**
     * @return array{ok: bool, status: string, detail: string, http_status: ?int, duration_ms: int}
     */
    public function test(Client $client): array
    {
        $started = microtime(true);
        $url = rtrim($client->base_url, '/').'/'.ltrim((string) config('opsifin_cron.connection_test_path'), '/');

        try {
            $headers = ['Accept' => 'application/json'];

            if ($auth = $client->authorizationHeader()) {
                $headers['Authorization'] = $auth;
            }

            $response = Http::withHeaders($headers)
                ->connectTimeout(config('opsifin_cron.defaults.connect_timeout_sec'))
                ->timeout(config('opsifin_cron.defaults.connect_timeout_sec') * 2)
                ->get($url);
        } catch (Throwable $e) {
            // Credential dikirim lewat header, bukan URL, sehingga pesan cURL aman ditampilkan.
            return $this->result(false, 'Cannot connect', Str::limit($e->getMessage(), 300), null, $started);
        }

        $status = $response->status();
        $secretKeyNote = filled($client->auth_secret_key) ? ' The SecretKey header cannot be verified without running a transaction.' : '';

        return match (true) {
            $status === 200 || $status === 405 => $this->result(true, 'Credentials valid',
                "The client accepted the username and password (HTTP {$status}).".$secretKeyNote, $status, $started),
            $status === 401 || $status === 403 => $this->result(false, 'Credentials rejected',
                "The client returned HTTP {$status}. Check the username and password for this client.", $status, $started),
            $status === 404 => $this->result(false, 'Check unavailable',
                'The credential check endpoint was not found (HTTP 404). Check the base URL or the client application version.', $status, $started),
            $status >= 500 => $this->result(false, 'Client server error',
                "The client returned HTTP {$status} before the credentials could be confirmed.", $status, $started),
            default => $this->result(false, 'Unexpected response',
                "The client returned HTTP {$status}; the credentials could not be confirmed.", $status, $started),
        };
    }

    /** @return array{ok: bool, status: string, detail: string, http_status: ?int, duration_ms: int} */
    private function result(bool $ok, string $status, string $detail, ?int $httpStatus, float $started): array
    {
        return [
            'ok' => $ok,
            'status' => $status,
            'detail' => $detail,
            'http_status' => $httpStatus,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }
}
