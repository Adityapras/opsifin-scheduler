<?php

namespace App\Filament\Widgets;

use App\Enums\AlertStatus;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Alert;
use App\Models\Run;
use App\Models\Schedule;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Empat angka yang menjawab "apakah semalam baik-baik saja?" tanpa harus
 * membuka tabel runs.
 */
class RunHealthOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return auth()->user()?->is_active ?? false;
    }

    protected function getStats(): array
    {
        $since = now()->subDay();

        $counts = Run::query()
            ->where('started_at', '>=', $since)
            ->whereNot('trigger', RunTrigger::DryRun->value)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = (int) $counts->sum();
        $success = (int) ($counts[RunStatus::Success->value] ?? 0);
        $failed = (int) ($counts[RunStatus::Failed->value] ?? 0)
            + (int) ($counts[RunStatus::Timeout->value] ?? 0);
        $skipped = (int) ($counts[RunStatus::SkippedLock->value] ?? 0);

        $rate = $total > 0 ? round($success / $total * 100) : null;
        $openAlerts = Alert::where('status', AlertStatus::Open->value)->count();

        return [
            Stat::make('Runs in 24h', number_format($total))
                ->description(Schedule::where('is_enabled', true)->count().' schedules enabled')
                ->color('gray'),

            Stat::make('Success rate', $rate === null ? '—' : $rate.'%')
                ->description($success.' succeeded')
                ->color(match (true) {
                    $rate === null => 'gray',
                    $rate >= 99 => 'success',
                    $rate >= 90 => 'warning',
                    default => 'danger',
                }),

            Stat::make('Failed or timed out', number_format($failed))
                ->description($skipped.' skipped by a lock')
                ->color($failed > 0 ? 'danger' : 'success'),

            Stat::make('Open alerts', number_format($openAlerts))
                ->description($openAlerts > 0 ? 'Nobody has picked these up' : 'Nothing outstanding')
                ->color($openAlerts > 0 ? 'danger' : 'success'),
        ];
    }
}
