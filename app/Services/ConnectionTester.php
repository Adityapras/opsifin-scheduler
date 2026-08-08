<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tes koneksi non-destruktif untuk sebuah client.
 *
 * Semua endpoint Opsifin bersifat mutasi (POST), jadi tes ini sengaja hanya
 * melakukan GET ke base URL dengan header Authorization milik client. Yang bisa
 * dipastikan: DNS/TLS/host hidup, dan apakah kredensial ditolak (401/403).
 * Yang TIDAK bisa dipastikan: apakah kredensial benar untuk endpoint tertentu.
 */
class ConnectionTester
{
    /**
     * @return array{ok: bool, status: string, detail: string, http_status: ?int, duration_ms: int}
     */
    public function test(Client $client): array
    {
        $started = microtime(true);

        try {
            $headers = ['Accept' => 'application/json'];

            if ($auth = $client->authorizationHeader()) {
                $headers['Authorization'] = $auth;
            }

            $response = Http::withHeaders($headers)
                ->connectTimeout(config('opsifin_cron.defaults.connect_timeout_sec'))
                ->timeout(config('opsifin_cron.defaults.timeout_sec'))
                ->get($client->base_url);

            $duration = (int) round((microtime(true) - $started) * 1000);

            if (in_array($response->status(), [401, 403], true)) {
                return [
                    'ok' => false,
                    'status' => 'Kredensial ditolak',
                    'detail' => "Host hidup, tapi mengembalikan HTTP {$response->status()}. ".
                        'Periksa username/password client ini.',
                    'http_status' => $response->status(),
                    'duration_ms' => $duration,
                ];
            }

            return [
                'ok' => true,
                'status' => 'Host terjangkau',
                'detail' => "HTTP {$response->status()} dari {$client->base_url}. ".
                    'Kredensial tidak ditolak, tapi belum tentu valid untuk endpoint task tertentu.',
                'http_status' => $response->status(),
                'duration_ms' => $duration,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'status' => 'Tidak bisa terhubung',
                'detail' => $e->getMessage(),
                'http_status' => null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        }
    }
}
