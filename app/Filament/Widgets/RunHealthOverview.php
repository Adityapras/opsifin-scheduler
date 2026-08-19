<?php

namespace App\Filament\Widgets;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Models\Schedule;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RunHealthOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    public static function canView(): bool
    {
        return auth()->user()?->is_active ?? false;
    }

    protected function getStats(): array
    {
        $counts = Run::query()->where('scheduled_for', '>=', now()->subDay())
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $succeeded = (int) ($counts[RunStatus::Succeeded->value] ?? 0);
        $failed = (int) ($counts[RunStatus::Failed->value] ?? 0);
        $decided = $succeeded + $failed;
        $rate = $decided > 0 ? round($succeeded / $decided * 100, 1) : null;
        $queued = Run::query()->where('status', RunStatus::Queued->value)->count();
        $running = Run::query()->where('status', RunStatus::Running->value)->count();

        return [
            Stat::make('Enabled schedules', Schedule::query()->where('is_enabled', true)->count())
                ->description('Database-backed HTTP jobs')
                ->icon('heroicon-o-calendar-days')->color('primary'),
            Stat::make('Success, 24 hours', $rate === null ? '—' : $rate.'%')
                ->description(number_format($succeeded).' succeeded · '.number_format($failed).' failed')
                ->color(match (true) {
                    $rate === null => 'gray', $rate >= 99 => 'success', $rate >= 90 => 'warning', default => 'danger'
                }),
            Stat::make('Queue', number_format($queued))
                ->description('Waiting for a worker')->color($queued > 0 ? 'warning' : 'success'),
            Stat::make('Running', number_format($running))
                ->description('HTTP calls in progress')->color($running > 0 ? 'info' : 'gray'),
        ];
    }
}
