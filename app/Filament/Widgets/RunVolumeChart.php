<?php

namespace App\Filament\Widgets;

use App\Services\Monitoring\RunActivity;
use Filament\Widgets\ChartWidget;

class RunVolumeChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Runs per hour';

    protected ?string $description = 'Last 24 hours by final status';

    protected ?string $pollingInterval = '60s';

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return auth()->user()?->is_active ?? false;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $data = app(RunActivity::class)->statusPerHour();
        $colors = ['succeeded' => '#16a34a', 'failed' => '#dc2626', 'skipped' => '#d97706', 'cancelled' => '#9ca3af'];

        return [
            'labels' => $data['labels'],
            'datasets' => collect($data['series'])->map(fn (array $values, string $status) => [
                'label' => ucfirst($status),
                'data' => $values,
                'backgroundColor' => $colors[$status],
                'borderColor' => $colors[$status],
                'stack' => 'runs',
            ])->values()->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }
}
