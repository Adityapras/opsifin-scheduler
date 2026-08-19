<?php

namespace App\Filament\Widgets;

use App\Models\Client;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use App\Services\Scheduling\ClientScheduleSummary;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ClientCoverageOverview extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $clients = Client::query()->count();
        $incomplete = ClientScheduleSummary::incompleteClientCount();

        return [
            Stat::make('Clients', number_format($clients))
                ->description(number_format(Client::query()->where('is_active', true)->count()).' active')
                ->icon('heroicon-o-building-office-2')
                ->color('primary'),
            Stat::make('Complete coverage', number_format(max(0, $clients - $incomplete)))
                ->description('Have every active job assignment')
                ->icon('heroicon-o-check-badge')
                ->color('success'),
            Stat::make('Need assignment', number_format($incomplete))
                ->description('Missing one or more active jobs')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($incomplete > 0 ? 'warning' : 'success'),
            Stat::make('Catalog & timings', TaskTemplate::query()->where('is_active', true)->count().' jobs')
                ->description(number_format(Schedule::query()->count()).' configured timings')
                ->icon('heroicon-o-square-3-stack-3d')
                ->color('info'),
        ];
    }
}
