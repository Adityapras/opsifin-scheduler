<?php

namespace App\Services\Monitoring;

use App\Enums\RunStatus;
use App\Models\Run;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Pemeriksaan kesehatan server untuk dashboard.
 *
 * Setiap check mengembalikan status plus langkah mitigasi, supaya operator
 * yang membuka dashboard saat insiden langsung tahu tindakan pertama tanpa
 * harus membuka runbook. Tidak ada check yang boleh melempar exception:
 * dashboard justru paling dibutuhkan ketika sebagian komponen sedang mati.
 */
class ServerHealth
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    public const UNKNOWN = 'unknown';

    /**
     * @return array<int, array{key: string, label: string, status: string, value: string, detail: ?string, mitigation: string}>
     */
    public function checks(): array
    {
        $checks = [
            $this->guard('database', 'Database', fn () => $this->database(), 'Check the MySQL service or container and the DB_* settings. Without the database no occurrence is created or recorded.'),
            $this->guard('db_connections', 'DB connections', fn () => $this->databaseConnections(), 'Look for stuck processes with SHOW PROCESSLIST and restart idle workers, or raise max_connections.'),
            $this->guard('dispatcher', 'Dispatcher', fn () => $this->dispatcher(), 'Make sure cron (or the scheduler container) runs `artisan schedule:run` every minute, then run `artisan jobs:dispatch-due` once to confirm. Missed occurrences are not replayed.'),
        ];

        $checks[] = config('opsifin_cron.execution_driver') === 'direct'
            ? $this->guard('executor', 'Direct executor', fn () => $this->directExecutor(), 'Start `artisan jobs:work-direct` (Supervisor or the direct container) and check `artisan jobs:direct-status` for the lease owner.')
            : $this->guard('executor', 'Redis queue', fn () => $this->redis(), 'Check the Redis service, then `artisan horizon:status`; restart Horizon through Supervisor.');

        return [
            ...$checks,
            $this->guard('backlog', 'Waiting backlog', fn () => $this->backlog(), 'The executor is not keeping up. Check the executor status, CRON_DIRECT_CONCURRENCY and slow endpoints in Execution logs.'),
            $this->guard('overdue', 'Overdue running', fn () => $this->overdueRunning(), 'Runs passed their execution deadline. Inspect them in Execution logs and restart the executor if it hangs.'),
            $this->guard('failure_rate', 'Failure rate, 24 hours', fn () => $this->failureRate(), 'Filter Execution logs by "Problems only" to find the failing client, then use "Test connection" on that client.'),
            $this->guard('cpu', 'CPU load', fn () => $this->cpu(), 'Find heavy processes with `top`. Lower CRON_DIRECT_CONCURRENCY temporarily if the executor is the cause.'),
            $this->guard('memory', 'Memory', fn () => $this->memory(), 'Free memory or restart the heaviest process; the executor and MySQL are the first to be killed when memory runs out.'),
            $this->guard('disk', 'Disk (storage)', fn () => $this->disk(), 'Delete old execution logs (Execution logs → Delete old logs), rotate storage/logs, and prune Telescope.'),
        ];
    }

    /** @param array<int, array{status: string}> $checks */
    public static function overall(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        return match (true) {
            in_array(self::CRITICAL, $statuses, true) => self::CRITICAL,
            in_array(self::WARNING, $statuses, true) => self::WARNING,
            default => self::OK,
        };
    }

    /** @return array{0: string, 1: string, 2?: ?string} */
    private function database(): array
    {
        $started = hrtime(true);
        DB::select('select 1');
        $ms = (hrtime(true) - $started) / 1_000_000;

        return [$ms > 200 ? self::WARNING : self::OK, number_format($ms, 1).' ms', DB::connection()->getDriverName().' ping'];
    }

    /** @return array{0: string, 1: string, 2?: ?string} */
    private function databaseConnections(): array
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return [self::UNKNOWN, 'n/a', 'Only measured on MySQL'];
        }

        $used = (int) (DB::selectOne("show status like 'Threads_connected'")->Value ?? 0);
        $max = (int) (DB::selectOne("show variables like 'max_connections'")->Value ?? 0);
        $ratio = $max > 0 ? $used / $max : 0;

        return [$this->level($ratio, 0.8, 0.95), $used.' / '.$max, round($ratio * 100).'% of max_connections'];
    }

    /** @return array{0: string, 1: string, 2?: ?string} */
    private function dispatcher(): array
    {
        $heartbeat = DB::table('executor_states')->where('name', 'dispatcher')->value('heartbeat_at');

        if ($heartbeat === null) {
            return [self::CRITICAL, 'No heartbeat', 'The dispatcher has never run'];
        }

        $at = Carbon::parse($heartbeat);

        return [$at->lt(now()->subMinutes(2)) ? self::CRITICAL : self::OK, $at->diffForHumans(), 'Last dispatch-due heartbeat'];
    }

    /** @return array{0: string, 1: string, 2?: ?string} */
    private function directExecutor(): array
    {
        $state = DB::table('executor_states')->where('name', 'direct')->first();
        $online = $state?->owner !== null && $state?->expires_at > now()->toDateTimeString();
        $metrics = json_decode($state?->metrics ?? '{}', true) ?: [];
        $slots = ($metrics['pool_active'] ?? 0).' / '.($metrics['pool_capacity'] ?? config('opsifin_cron.direct.concurrency')).' slots';

        return [$online ? self::OK : self::CRITICAL, $online ? 'Online' : 'Offline', $online ? $slots : 'Lease expired or never acquired'];
    }

    /** @return array{0: string, 1: string, 2?: ?string} */
    private function redis(): array
    {
        $started = hrtime(true);
        Redis::connection()->ping();
        $ms = (hrtime(true) - $started) / 1_000_000;

        return [self::OK, 'Online', number_format($ms, 1).' ms ping'];
    }

    /** @return array{0: string, 1: string, 2?: ?string} */
    private function backlog(): array
    {
        $waiting = Run::query()->whereIn('status', [RunStatus::Pending->value, RunStatus::Queued->value]);
        $count = (clone $waiting)->count();
        $oldest = (clone $waiting)->min('prepared_at');

        if ($count === 0 || $oldest === null) {
            return [self::OK, '0 waiting', null];
        }

        $age = (int) Carbon::parse($oldest)->diffInSeconds(now(), true);
        $window = (int) config('opsifin_cron.direct.start_window_sec', 55);

        return [
            $age > 300 ? self::CRITICAL : ($age > $window ? self::WARNING : self::OK),
            number_format($count).' waiting',
            'Oldest '.Carbon::parse($oldest)->diffForHumans(now(), CarbonInterface::DIFF_ABSOLUTE).' old',
        ];
    }

    /** @return array{0: string, 1: string, 2?: ?string} */
    private function overdueRunning(): array
    {
        $count = Run::query()->where('status', RunStatus::Running->value)
            ->whereNotNull('execution_deadline_at')->where('execution_deadline_at', '<', now())->count();

        return [$count > 0 ? self::CRITICAL : self::OK, (string) $count, 'Running past the execution deadline'];
    }

    /** @return array{0: string, 1: string, 2?: ?string} */
    private function failureRate(): array
    {
        $counts = Run::query()->where('scheduled_for', '>=', now()->subDay())
            ->whereIn('status', [RunStatus::Succeeded->value, RunStatus::Failed->value])
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $failed = (int) ($counts[RunStatus::Failed->value] ?? 0);
        $total = $failed + (int) ($counts[RunStatus::Succeeded->value] ?? 0);

        if ($total === 0) {
            return [self::OK, '—', 'No finished run in 24 hours'];
        }

        $ratio = $failed / $total;

        return [$this->level($ratio, 0.01, 0.10), number_format($ratio * 100, 1).'%', number_format($failed).' of '.number_format($total).' failed'];
    }

    /** @return array{0: string, 1: string, 2?: ?string} */
    private function cpu(): array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        if ($load === false) {
            return [self::UNKNOWN, 'n/a', 'Load average is not available on this OS'];
        }

        $cores = max(1, $this->cpuCores());
        $ratio = $load[0] / $cores;

        return [$this->level($ratio, 0.8, 1.5), number_format($load[0], 2), $cores.' core(s) · 5m '.number_format($load[1], 2).' · 15m '.number_format($load[2], 2)];
    }

    /** @return array{0: string, 1: string, 2?: ?string} */
    private function memory(): array
    {
        $info = @file_get_contents('/proc/meminfo');

        if ($info === false || ! preg_match('/MemTotal:\s+(\d+)/', $info, $total) || ! preg_match('/MemAvailable:\s+(\d+)/', $info, $available)) {
            return [self::UNKNOWN, 'n/a', 'Memory info is only available on Linux'];
        }

        $free = (int) $available[1] / max(1, (int) $total[1]);

        return [
            $this->level(1 - $free, 0.85, 0.95),
            round((1 - $free) * 100).'% used',
            $this->bytes((int) $available[1] * 1024).' available of '.$this->bytes((int) $total[1] * 1024),
        ];
    }

    /** @return array{0: string, 1: string, 2?: ?string} */
    private function disk(): array
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());

        if ($free === false || $total === false || $total <= 0) {
            return [self::UNKNOWN, 'n/a', 'Disk usage is not readable'];
        }

        $used = 1 - $free / $total;

        return [$this->level($used, 0.85, 0.95), round($used * 100).'% used', $this->bytes((int) $free).' free of '.$this->bytes((int) $total)];
    }

    /**
     * @param  callable(): array{0: string, 1: string, 2?: ?string}  $check
     * @return array{key: string, label: string, status: string, value: string, detail: ?string, mitigation: string}
     */
    private function guard(string $key, string $label, callable $check, string $mitigation): array
    {
        try {
            [$status, $value, $detail] = $check() + [2 => null];
        } catch (Throwable $exception) {
            // Pesan exception bisa memuat host/credential; cukup nama kelasnya.
            [$status, $value, $detail] = [self::CRITICAL, 'Unreachable', class_basename($exception)];
        }

        return compact('key', 'label', 'status', 'value', 'detail', 'mitigation');
    }

    private function level(float $ratio, float $warning, float $critical): string
    {
        return $ratio >= $critical ? self::CRITICAL : ($ratio >= $warning ? self::WARNING : self::OK);
    }

    private function cpuCores(): int
    {
        $info = @file_get_contents('/proc/cpuinfo');

        return $info === false ? 1 : max(1, preg_match_all('/^processor\s*:/m', $info));
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return number_format($bytes / 1024 ** $power, $power >= 3 ? 1 : 0).' '.$units[$power];
    }
}
