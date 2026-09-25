<?php

namespace App\Filament\Widgets;

use App\Services\Monitoring\RunActivity;
use Filament\Widgets\ChartWidget;

class RunLatencyChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'Start lag and duration';

    protected ?string $description = 'Seconds per hour, last 24 hours. Max start lag must stay under 60 s.';

    protected ?string $pollingInterval = '60s';

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return auth()->user()?->is_active ?? false;
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $data = app(RunActivity::class)->latencyPerHour();

        return [
            'labels' => $data['labels'],
            'datasets' => [
                $this->line('Max start lag', $data['series']['max_lag'], '#dc2626'),
                $this->line('Avg start lag', $data['series']['avg_lag'], '#d97706'),
                $this->line('Avg duration', $data['series']['avg_duration'], '#2563eb'),
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'spanGaps' => true,
            'scales' => ['y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => 'seconds']]],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }

    /** @param array<int, float|null> $values @return array<string, mixed> */
    private function line(string $label, array $values, string $color): array
    {
        return [
            'label' => $label,
            'data' => $values,
            'borderColor' => $color,
            'backgroundColor' => $color,
            'pointRadius' => 2,
            'tension' => 0.3,
        ];
    }
}
