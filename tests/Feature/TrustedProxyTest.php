<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_https_forwarder_headers_are_used_for_a_trusted_proxy(): void
    {
        Route::get('/_trusted-proxy-test', fn (Request $request) => [
            'secure' => $request->isSecure(),
            'url' => url('/admin'),
        ]);

        $this
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders([
                'X-Forwarded-Host' => 'temporary-forwarder.example',
                'X-Forwarded-Port' => '443',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('/_trusted-proxy-test')
            ->assertOk()
            ->assertJson([
                'secure' => true,
                'url' => 'https://temporary-forwarder.example/admin',
            ]);
    }

    public function test_forwarder_headers_are_ignored_for_an_untrusted_source(): void
    {
        Route::get('/_untrusted-proxy-test', fn (Request $request) => [
            'secure' => $request->isSecure(),
            'url' => url('/admin'),
        ]);

        $this
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.5'])
            ->withHeaders([
                'Host' => 'localhost',
                'X-Forwarded-Host' => 'spoofed.example',
                'X-Forwarded-Port' => '443',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('/_untrusted-proxy-test')
            ->assertOk()
            ->assertJson([
                'secure' => false,
                'url' => 'http://opsifin-cron.local/admin',
            ]);
    }
}
