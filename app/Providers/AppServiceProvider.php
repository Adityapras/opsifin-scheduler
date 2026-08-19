<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Observers\DomainAuditObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([Client::class, TaskTemplate::class, Schedule::class, User::class] as $model) {
            $model::observe(DomainAuditObserver::class);
        }
    }
}
