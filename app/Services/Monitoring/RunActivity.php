<?php

namespace App\Services\Monitoring;

use App\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * Agregat Run per jam untuk grafik dashboard.
 *
 * Pengelompokan dilakukan di database karena volume 24 jam bisa ratusan ribu
 * baris; PHP hanya menerima maksimal 24 bucket per status.
 */
class RunActivity
{
    /**
     * @return array{labels: array<int, string>, buckets: array<int, string>, rows: array<string, array<string, mixed>>}
     */
    public function hourly(int $hours = 24, array $columns = []): array
    {
        $end = now()->startOfHour();
        $start = $end->copy()->subHours($hours - 1);
        $bucket = $this->hourExpression('scheduled_for');

        $rows = Run::query()
            ->where('scheduled_for', '>=', $start)
            ->selectRaw($bucket.' as bucket')
            ->selectRaw(implode(', ', $columns))
            ->groupByRaw($bucket)
            ->toBase()->get()
            ->keyBy('bucket')
            ->map(fn ($row) => (array) $row)
            ->all();

        $buckets = [];
        $labels = [];
        $timezone = config('opsifin_cron.default_timezone');

        for ($hour = $start->copy(); $hour->lte($end); $hour->addHour()) {
            $buckets[] = $hour->format('Y-m-d H:00');
            $labels[] = $hour->copy()->timezone($timezone)->format('H:00');
        }

        return ['labels' => $labels, 'buckets' => $buckets, 'rows' => $rows];
    }

    /** @return array{labels: array<int, string>, series: array<string, array<int, int>>} */
    public function statusPerHour(int $hours = 24): array
    {
        $statuses = ['succeeded', 'failed', 'skipped', 'cancelled'];
        $columns = array_map(fn (string $status) => "sum(case when status = '{$status}' then 1 else 0 end) as {$status}", $statuses);
        $data = $this->hourly($hours, $columns);

        $series = [];
        foreach ($statuses as $status) {
            $series[$status] = array_map(fn (string $bucket): int => (int) ($data['rows'][$bucket][$status] ?? 0), $data['buckets']);
        }

        return ['labels' => $data['labels'], 'series' => $series];
    }

    /** @return array{labels: array<int, string>, series: array<string, array<int, float|null>>} */
    public function latencyPerHour(int $hours = 24): array
    {
        $data = $this->hourly($hours, [
            'avg(start_lag_ms) as avg_lag',
            'max(start_lag_ms) as max_lag',
            'avg(duration_ms) as avg_duration',
        ]);

        $seconds = fn (string $key) => array_map(function (string $bucket) use ($data, $key): ?float {
            $value = $data['rows'][$bucket][$key] ?? null;

            return $value === null ? null : round((float) $value / 1000, 2);
        }, $data['buckets']);

        return ['labels' => $data['labels'], 'series' => [
            'avg_lag' => $seconds('avg_lag'),
            'max_lag' => $seconds('max_lag'),
            'avg_duration' => $seconds('avg_duration'),
        ]];
    }

    private function hourExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m-%d %H:00', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM-DD HH24:00')",
            default => "date_format({$column}, '%Y-%m-%d %H:00')",
        };
    }
}
