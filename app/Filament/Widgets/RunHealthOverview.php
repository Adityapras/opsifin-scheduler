<?php

namespace App\Filament\Widgets;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Models\Schedule;
use App\Services\Scheduling\DirectExecutionHealth;
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
        $direct = config('opsifin_cron.execution_driver') === 'direct';
        $queued = Run::query()->where('status', $direct ? RunStatus::Pending->value : RunStatus::Queued->value)->count();
        $running = Run::query()->where('status', RunStatus::Running->value)->count();

        $stats = [
            Stat::make('Enabled schedules', Schedule::query()->where('is_enabled', true)->count())
                ->description('Database-backed HTTP jobs')
                ->icon('heroicon-o-calendar-days')->color('primary'),
            Stat::make('Success, 24 hours', $rate === null ? '—' : $rate.'%')
                ->description(number_format($succeeded).' succeeded · '.number_format($failed).' failed')
                ->color(match (true) {
                    $rate === null => 'gray', $rate >= 99 => 'success', $rate >= 90 => 'warning', default => 'danger'
                }),
            Stat::make($direct ? 'Pending' : 'Queue', number_format($queued))
                ->description('Waiting to start')->color($queued > 0 ? 'warning' : 'success'),
            Stat::make('Running', number_format($running))
                ->description('HTTP calls in progress')->color($running > 0 ? 'info' : 'gray'),
        ];

        if ($direct) {
            $health = app(DirectExecutionHealth::class)->snapshot();
            $alive = $health['executor_online'];
            $metrics = $health['pool'];
            $p95 = $health['percentiles_24h']['start_lag_ms']['p95'];
            $p99 = $health['percentiles_24h']['start_lag_ms']['p99'];
            $stats[] = Stat::make('Direct executor', $alive ? 'Online' : 'Offline')
                ->description(($metrics['pool_active'] ?? 0).' / '.($metrics['pool_capacity'] ?? config('opsifin_cron.direct.concurrency')).' active slots')
                ->color($alive ? 'success' : 'danger');
            $stats[] = Stat::make('Start lag p99, 24 hours', $p99 === null ? '—' : number_format($p99 / 1000, 2).' s')
                ->description('p95: '.($p95 === null ? '—' : number_format($p95 / 1000, 2).' s'))
                ->color($p99 === null ? 'gray' : ($p99 < 60000 && $p95 <= 45000 ? 'success' : 'danger'));
            $stats[] = Stat::make('Dispatcher', $health['dispatcher_online'] ? 'Online' : 'Offline')
                ->description($health['dispatcher_heartbeat_at'] ?? 'No heartbeat yet');
            $stats[] = Stat::make('Missed start window, 24 hours', $health['missed_start_window_24h'])
                ->description('Occurrences skipped without replay')->color($health['missed_start_window_24h'] > 0 ? 'warning' : 'success');
        }

        return $stats;
    }
}
