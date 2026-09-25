<?php

namespace Tests\Feature;

use App\Services\Monitoring\RunActivity;
use App\Services\Monitoring\ServerHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\TestCase;

class ServerHealthTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    public function test_every_check_reports_a_status_and_a_mitigation(): void
    {
        $checks = app(ServerHealth::class)->checks();

        $this->assertNotEmpty($checks);
        foreach ($checks as $check) {
            $this->assertContains($check['status'], ['ok', 'warning', 'critical', 'unknown'], $check['key']);
            $this->assertNotSame('', $check['mitigation']);
        }
    }

    public function test_missing_dispatcher_heartbeat_is_critical(): void
    {
        DB::table('executor_states')->where('name', 'dispatcher')->update(['heartbeat_at' => null]);

        $checks = collect(app(ServerHealth::class)->checks())->keyBy('key');

        $this->assertSame('critical', $checks['dispatcher']['status']);
        $this->assertSame('critical', ServerHealth::overall($checks->values()->all()));
    }

    public function test_high_failure_rate_is_critical(): void
    {
        $schedule = $this->schedule();
        $this->occurrence($schedule, ['status' => 'failed']);
        $this->occurrence($schedule, ['status' => 'succeeded', 'materialization_key' => null]);

        $checks = collect(app(ServerHealth::class)->checks())->keyBy('key');

        $this->assertSame('critical', $checks['failure_rate']['status']);
        $this->assertSame('50.0%', $checks['failure_rate']['value']);
    }

    public function test_hourly_activity_groups_runs_by_status(): void
    {
        $schedule = $this->schedule();
        $this->occurrence($schedule, ['status' => 'succeeded', 'start_lag_ms' => 2000, 'duration_ms' => 1000]);
        $this->occurrence($schedule, ['status' => 'failed', 'materialization_key' => null, 'start_lag_ms' => 4000]);

        $volume = app(RunActivity::class)->statusPerHour();
        $latency = app(RunActivity::class)->latencyPerHour();

        $this->assertCount(24, $volume['labels']);
        $this->assertSame(1, end($volume['series']['succeeded']));
        $this->assertSame(1, end($volume['series']['failed']));
        $this->assertSame(3.0, end($latency['series']['avg_lag']));
        $this->assertSame(4.0, end($latency['series']['max_lag']));
    }

    public function test_dashboard_shows_server_health_and_charts(): void
    {
        $this->schedule();

        $this->actingAs($this->user())->get('/admin')
            ->assertSuccessful()
            ->assertSee('Server health')
            ->assertSee('Mitigation steps');
    }
}
