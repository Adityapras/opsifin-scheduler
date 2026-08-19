<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            return $isLocal ||
                   $entry->type === EntryType::REQUEST ||
                   $entry->type === EntryType::CLIENT_REQUEST ||
                   $entry->type === EntryType::JOB ||
                   $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->isFailedJob() ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters([
            '_token',
            'auth_secret',
            'auth_secret_key',
            'password',
            'password_confirmation',
            'token',
        ]);

        Telescope::hideRequestHeaders([
            'cookie',
            'authorization',
            'secretkey',
            'x-api-key',
            'x-csrf-token',
            'x-xsrf-token',
        ]);

        Telescope::hideResponseParameters([
            'access_token',
            'api_key',
            'auth_secret',
            'auth_secret_key',
            'password',
            'refresh_token',
            'secret',
            'token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', fn (?User $user): bool => $user?->isAdmin() ?? false);
    }

    /** Require an administrator in every environment, including local. */
    protected function authorization(): void
    {
        $this->gate();

        Telescope::auth(fn ($request): bool => Gate::check('viewTelescope', [$request->user()]));
    }
}
