<?php

namespace App\Services\Scheduling;

use App\Models\Run;
use Illuminate\Support\Facades\DB;

class DirectExecutionHealth
{
    public function snapshot(): array
    {
        $state = DB::table('executor_states')->where('name', 'direct')->first();
        $dispatcher = DB::table('executor_states')->where('name', 'dispatcher')->value('heartbeat_at');
        $online = $state?->owner !== null && $state?->expires_at > now()->toDateTimeString();
        $dispatcherOnline = $dispatcher !== null && $dispatcher >= now()->subMinutes(2)->toDateTimeString();
        $runs = Run::query()->where('execution_driver', 'direct')->where('scheduled_for', '>=', now()->subDay());
        $counts = (clone $runs)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();
        $percentiles = [];
        foreach (['start_lag_ms', 'duration_ms'] as $column) {
            $query = (clone $runs)->whereNotNull($column);
            if ($column === 'duration_ms') {
                $query->whereNotNull('started_at');
            }
            $count = (clone $query)->count();
            foreach ([50, 95, 99] as $percentile) {
                $percentiles[$column]['p'.$percentile] = $count === 0 ? null
                    : (int) (clone $query)->orderBy($column)->offset((int) ceil($count * $percentile / 100) - 1)->value($column);
            }
        }
        $expiredPending = Run::query()->where('status', 'pending')
            ->where('scheduled_for', '<=', now()->subSeconds(config('opsifin_cron.direct.start_window_sec')))->count();
        $expiredRunning = Run::query()->where('execution_driver', 'direct')->where('status', 'running')
            ->where('execution_deadline_at', '<', now())->count();
        $totalDecided = ($counts['succeeded'] ?? 0) + ($counts['failed'] ?? 0);
        $missedWindow = (clone $runs)->where('status', 'skipped')->where('error_message', 'like', 'Missed start window%')->count();
        $alerts = [];
        if (! $online) {
            $alerts[] = 'Direct executor heartbeat missing or executor stopped.';
        }
        if (! $dispatcherOnline) {
            $alerts[] = 'Dispatcher heartbeat is more than two minutes old or missing.';
        }
        if (($percentiles['start_lag_ms']['p95'] ?? 0) > 45000 || ($percentiles['start_lag_ms']['p99'] ?? 0) >= 60000) {
            $alerts[] = 'Start lag exceeds the operational target.';
        }
        if ($expiredPending > 0 || $expiredRunning > 0) {
            $alerts[] = 'Occurrences have exceeded their start window or execution deadline.';
        }
        if ($missedWindow > 0) {
            $alerts[] = 'Occurrences missed their start window in the last 24 hours.';
        }

        return [
            'driver' => config('opsifin_cron.execution_driver'), 'executor_online' => $online,
            'heartbeat_at' => $state?->heartbeat_at, 'dispatcher_online' => $dispatcherOnline,
            'dispatcher_heartbeat_at' => $dispatcher, 'pool' => json_decode($state?->metrics ?? '{}', true),
            'counts_24h' => $counts, 'percentiles_24h' => $percentiles,
            'oldest_pending_at' => Run::query()->where('status', 'pending')->min('prepared_at'),
            'expired_pending' => $expiredPending, 'expired_running' => $expiredRunning,
            'missed_start_window_24h' => $missedWindow,
            'failed_rate_24h' => $totalDecided === 0 ? null : round(($counts['failed'] ?? 0) / $totalDecided * 100, 2),
            'alerts' => $alerts,
        ];
    }
}
