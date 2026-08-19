<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

class TrustConfiguredProxies extends TrustProxies
{
    /**
     * @return array<int, string>|string|null
     */
    protected function proxies(): array|string|null
    {
        $proxies = config('opsifin_cron.trusted_proxies', []);

        return $proxies !== [] ? $proxies : parent::proxies();
    }
}
