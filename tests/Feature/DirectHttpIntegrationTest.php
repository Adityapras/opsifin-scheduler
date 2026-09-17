<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Services\Scheduling\DirectPoolExecutor;
use App\Services\Scheduling\DueScheduleDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\Support\StartsDirectHttpFixture;
use Tests\TestCase;

class DirectHttpIntegrationTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase, StartsDirectHttpFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['opsifin_cron.execution_driver' => 'direct', 'opsifin_cron.direct.concurrency' => 3,
            'opsifin_cron.direct.idle_delay_ms' => 1]);
        $this->startHttpFixture();
    }

    protected function tearDown(): void
    {
        $this->stopHttpFixture();
        parent::tearDown();
    }

    private function requestRun(string $path, int $timeout = 3): Run
    {
        $schedule = $this->schedule([], ['timeout_sec' => $timeout, 'connect_timeout_sec' => 1,
            'config' => ['method' => 'POST', 'base_url' => $this->fixtureUrl, 'path' => $path]]);

        return $this->occurrence($schedule, ['status' => 'pending', 'execution_driver' => 'direct',
            'scheduled_for' => now(), 'prepared_at' => now()]);
    }

    public function test_real_http_bounded_concurrency_error_body_redaction_and_no_redirect_or_retry(): void
    {
        $runs = [];
        for ($i = 0; $i < 10; $i++) {
            $runs[] = $this->requestRun('/run?delay=0.05');
        }
        $failure = $this->requestRun('/run?status=503&body='.rawurlencode('{"message":"super-secret secret-key"}'));
        $redirect = $this->requestRun('/run?status=302');
        $large = $this->requestRun('/run?bytes=5000000');
        $result = app(DirectPoolExecutor::class)->work(once: true);
        $metrics = $this->httpMetrics();
        $this->assertSame(13, $result['settled']);
        $this->assertLessThanOrEqual(3, $metrics['max_active']);
        $this->assertGreaterThan(1, $metrics['max_active']);
        $this->assertCount(13, $metrics['requests']);
        $this->assertSame(RunStatus::Failed, $failure->fresh()->status);
        $this->assertSame(503, $failure->fresh()->http_status);
        $this->assertStringNotContainsString('super-secret', $failure->fresh()->error_message);
        $this->assertStringNotContainsString('secret-key', $failure->fresh()->response_excerpt);
        $this->assertStringContainsString('••••••••', $failure->fresh()->error_message);
        $this->assertSame(RunStatus::Failed, $redirect->fresh()->status);
        $this->assertSame(RunStatus::Succeeded, $large->fresh()->status);
        $this->assertLessThanOrEqual(2000, mb_strlen($large->fresh()->response_excerpt));
        $this->assertSame(0, app(DirectPoolExecutor::class)->work(once: true)['started']);
    }

    public function test_timeout_and_disconnected_response_do_not_abort_other_requests(): void
    {
        $timeout = $this->requestRun('/run?delay=2', 1);
        $disconnect = $this->requestRun('/run?disconnect=1');
        $success = $this->requestRun('/run?delay=0.02');
        app(DirectPoolExecutor::class)->work(once: true);
        $this->assertSame(RunStatus::Failed, $timeout->fresh()->status);
        $this->assertStringContainsString('timed out', $timeout->fresh()->error_message);
        $this->assertSame(RunStatus::Failed, $disconnect->fresh()->status);
        $this->assertSame(RunStatus::Succeeded, $success->fresh()->status);
        $this->assertCount(3, $this->httpMetrics()['requests']);
    }

    public function test_fifty_short_requests_complete_and_overload_skips_without_late_send(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->requestRun('/short?delay=0.01');
        }
        $this->assertSame(50, app(DirectPoolExecutor::class)->work(once: true)['settled']);
        $this->assertCount(50, $this->httpMetrics()['requests']);

        config(['opsifin_cron.direct.concurrency' => 1, 'opsifin_cron.direct.start_window_sec' => 1]);
        $slow = $this->requestRun('/slow?delay=1.1');
        $expired = $this->requestRun('/must-not-send');
        app(DirectPoolExecutor::class)->work(once: true);
        app(DueScheduleDispatcher::class)->expirePendingRuns();
        $this->assertSame(RunStatus::Succeeded, $slow->fresh()->status);
        $this->assertSame(RunStatus::Skipped, $expired->fresh()->status);
        $this->assertNull($expired->fresh()->started_at);
        $this->assertCount(51, $this->httpMetrics()['requests']);
    }

    public function test_opt_in_capacity_scenario(): void
    {
        if (! getenv('DIRECT_HTTP_LOAD_RUNS')) {
            $this->markTestSkipped('Set DIRECT_HTTP_LOAD_RUNS, DIRECT_HTTP_LOAD_DELAY and DIRECT_HTTP_LOAD_CONCURRENCY for capacity testing.');
        }
        $count = (int) getenv('DIRECT_HTTP_LOAD_RUNS');
        $delay = (float) (getenv('DIRECT_HTTP_LOAD_DELAY') ?: 5);
        $capacity = (int) (getenv('DIRECT_HTTP_LOAD_CONCURRENCY') ?: 20);
        config(['opsifin_cron.direct.concurrency' => $capacity, 'opsifin_cron.direct.batch_limit' => max(250, $count)]);
        for ($i = 0; $i < $count; $i++) {
            $this->requestRun('/run?delay='.$delay, (int) ceil($delay) + 2);
        }
        // Measure from one shared occurrence after fixture construction.
        $scheduledFor = now()->startOfSecond();
        Run::query()->update(['scheduled_for' => $scheduledFor, 'prepared_at' => $scheduledFor]);
        $started = microtime(true);
        $cpuBefore = getrusage();
        $result = app(DirectPoolExecutor::class)->work(once: true);
        $metrics = $this->httpMetrics();
        $lags = Run::query()->whereNotNull('start_lag_ms')->orderBy('start_lag_ms')->pluck('start_lag_ms')->all();
        $durations = Run::query()->whereNotNull('duration_ms')->orderBy('duration_ms')->pluck('duration_ms')->all();
        $endpointLags = array_map(fn ($entry) => (int) round(($entry['started'] - $scheduledFor->timestamp) * 1000), $metrics['requests']);
        sort($endpointLags);
        $cpu = getrusage();
        $report = $result + ['runs' => $count, 'concurrency' => $capacity, 'delay_sec' => $delay,
            'elapsed_sec' => round(microtime(true) - $started, 3), 'endpoint_max_active' => $metrics['max_active'],
            'endpoint_requests' => count($metrics['requests']), 'response_bytes' => $metrics['bytes'],
            'p50_start_lag_ms' => $lags[(int) ceil(count($lags) * .5) - 1] ?? null,
            'p95_start_lag_ms' => $lags[(int) ceil(count($lags) * .95) - 1] ?? null,
            'p99_start_lag_ms' => $lags[(int) ceil(count($lags) * .99) - 1] ?? null,
            'max_start_lag_ms' => $lags === [] ? null : max($lags),
            'endpoint_p99_start_lag_ms' => $endpointLags[(int) ceil(count($endpointLags) * .99) - 1] ?? null,
            'endpoint_max_start_lag_ms' => $endpointLags === [] ? null : max($endpointLags),
            'p50_duration_ms' => $durations[(int) ceil(count($durations) * .5) - 1] ?? null,
            'p95_duration_ms' => $durations[(int) ceil(count($durations) * .95) - 1] ?? null,
            'p99_duration_ms' => $durations[(int) ceil(count($durations) * .99) - 1] ?? null,
            'peak_php_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            'cpu_user_sec' => $cpu['ru_utime.tv_sec'] - $cpuBefore['ru_utime.tv_sec'] + ($cpu['ru_utime.tv_usec'] - $cpuBefore['ru_utime.tv_usec']) / 1000000,
        ];
        fwrite(STDOUT, PHP_EOL.'DIRECT_CAPACITY '.json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL);
        $this->assertLessThanOrEqual($capacity, $metrics['max_active']);
        $this->assertSame($count, $result['started']);
        $this->assertCount($count, $metrics['requests']);
        $this->assertSame($count, Run::where('status', 'succeeded')->count());
        $this->assertLessThan(60000, max($lags));
        $this->assertLessThan(60000, max($endpointLags));
    }
}
