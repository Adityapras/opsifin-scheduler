<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Filament\Widgets\RunHealthOverview;
use App\Jobs\ExecuteRun;
use App\Models\Run;
use App\Services\Execution\DirectHttpTransport;
use App\Services\Execution\Dto\ExecutionResult;
use App\Services\Execution\ExecutorManager;
use App\Services\Scheduling\DirectExecutionHealth;
use App\Services\Scheduling\DirectExecutorLease;
use App\Services\Scheduling\DirectPoolExecutor;
use App\Services\Scheduling\DueScheduleDispatcher;
use App\Services\Scheduling\QueuedRunCanceller;
use App\Services\Scheduling\RunDispatcher;
use App\Services\Scheduling\RunExecutionLifecycle;
use App\Services\Scheduling\RunWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\Support\FakeDirectHttpTransport;
use Tests\TestCase;

class DirectPoolExecutorTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    private FakeDirectHttpTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        config(['opsifin_cron.execution_driver' => 'direct', 'opsifin_cron.direct.concurrency' => 2,
            'opsifin_cron.direct.idle_delay_ms' => 1]);
        $this->transport = new FakeDirectHttpTransport;
        $this->app->instance(DirectHttpTransport::class, $this->transport);
    }

    private function pending(array $attributes = []): Run
    {
        return $this->occurrence($this->schedule(), $attributes + [
            'status' => 'pending', 'execution_driver' => 'direct', 'prepared_at' => now(),
        ]);
    }

    public function test_materialization_is_idempotent_and_manual_work_stays_in_background(): void
    {
        Queue::fake();
        $schedule = $this->schedule(['cron_expression' => '* * * * *', 'next_run_at' => now()]);
        $schedule->forceFill(['next_run_at' => now()])->saveQuietly();
        $dispatcher = app(DueScheduleDispatcher::class);
        $this->assertSame(1, $dispatcher->dispatch()['pending']);
        $this->assertSame(0, $dispatcher->dispatch()['pending']);
        $manual = app(RunDispatcher::class)->manual($schedule);
        $this->assertSame(RunStatus::Pending, $manual->status);
        $this->assertNotNull($manual->prepared_at);
        $this->assertNull($manual->queue_job_id);
        $this->assertSame([], $this->transport->sent);
        Queue::assertNothingPushed();
    }

    public function test_concurrency_failure_isolation_and_no_retry(): void
    {
        $runs = collect(range(1, 7))->map(fn () => $this->pending());
        $failedId = $runs[0]->id;
        $this->transport->onTick = function (FakeDirectHttpTransport $transport) use ($failedId): void {
            foreach (array_keys($transport->pending) as $id) {
                $transport->settle($id, $id === $failedId ? new ExecutionResult(false, 500,
                    'super-secret secret-key', 'super-secret failed', 50) : null);
            }
        };
        $result = app(DirectPoolExecutor::class)->work(once: true);
        $this->assertSame(7, $result['settled']);
        $this->assertSame(2, $this->transport->maxActive);
        $failed = $runs[0]->fresh();
        $this->assertSame(RunStatus::Failed, $failed->status);
        $this->assertStringNotContainsString('super-secret', $failed->response_excerpt);
        $this->assertStringNotContainsString('secret-key', $failed->response_excerpt);
        $this->assertStringNotContainsString('super-secret', $failed->error_message);
        $this->assertSame(6, Run::where('status', 'succeeded')->count());
        $this->assertSame(0, app(DirectPoolExecutor::class)->work(once: true)['started']);
        $this->assertCount(7, $this->transport->sent);
    }

    public function test_slow_request_does_not_block_newly_arriving_manual_run(): void
    {
        $slow = $this->pending();
        $fast = $this->pending();
        $arrival = null;
        $this->transport->onTick = function (FakeDirectHttpTransport $transport) use ($slow, $fast, &$arrival): void {
            if ($transport->ticks === 1) {
                $transport->settle($fast->id);
                $arrival = $this->pending();

                return;
            }
            if (isset($transport->pending[$arrival->id])) {
                $this->assertSame(RunStatus::Succeeded, $fast->fresh()->status);
                $this->assertSame(RunStatus::Running, $slow->fresh()->status);
                $transport->settle($arrival->id);
                $transport->settle($slow->id);
            }
        };
        app(DirectPoolExecutor::class)->work(once: true);
        $this->assertSame(RunStatus::Succeeded, $arrival->fresh()->status);
    }

    public function test_pause_cancel_overlap_and_invalid_config_prevent_send(): void
    {
        $paused = $this->pending();
        $paused->schedule->client->update(['is_active' => false]);
        $disabled = $this->pending();
        $disabled->schedule->update(['is_enabled' => false]);
        $cancelled = $this->pending();
        app(QueuedRunCanceller::class)->cancel($cancelled);
        $overlap = $this->pending();
        $overlap->schedule->update(['running_run_id' => 999]);
        $invalid = $this->pending();
        $invalid->taskTemplate->update(['config' => ['base_url' => 'invalid-secret-url']]);
        app(DirectPoolExecutor::class)->work(once: true);
        foreach ([$paused, $disabled, $overlap] as $run) {
            $this->assertSame(RunStatus::Skipped, $run->fresh()->status);
        }
        $this->assertSame(RunStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame(RunStatus::Failed, $invalid->fresh()->status);
        $this->assertSame([], $this->transport->sent);
    }

    public function test_manual_run_can_execute_while_schedule_is_paused(): void
    {
        $schedule = $this->schedule(['is_enabled' => false, 'next_run_at' => null]);
        $run = app(RunDispatcher::class)->manual($schedule);
        $this->transport->onTick = function (FakeDirectHttpTransport $transport) use ($run): void {
            if (isset($transport->pending[$run->id])) {
                $transport->settle($run->id);
            }
        };

        app(DirectPoolExecutor::class)->work(once: true);

        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status);
        $this->assertFalse($schedule->fresh()->is_enabled);
        $this->assertCount(1, $this->transport->sent);
    }

    public function test_expired_pending_and_crashed_running_are_terminal_without_catch_up(): void
    {
        $old = $this->pending(['scheduled_for' => now()->subMinute()]);
        $crashed = $this->pending(['status' => 'running', 'execution_deadline_at' => now()->subSecond()]);
        $crashed->schedule->update(['running_run_id' => $crashed->id]);
        app(DirectPoolExecutor::class)->work(once: true);
        $this->assertSame(RunStatus::Skipped, $old->fresh()->status);
        $this->assertSame(RunStatus::Failed, $crashed->fresh()->status);
        $this->assertStringContainsString('unknown', $crashed->fresh()->error_message);
        $this->assertNull($crashed->schedule->fresh()->running_run_id);
        $this->assertSame([], $this->transport->sent);
        $crashed->schedule->update(['cron_expression' => '* * * * *', 'next_run_at' => now()]);
        $crashed->schedule->forceFill(['next_run_at' => now()])->saveQuietly();
        app(DueScheduleDispatcher::class)->dispatch();
        app(DirectPoolExecutor::class)->work(once: true);
        $this->assertCount(1, $this->transport->sent);
        $this->assertSame(3, Run::count());
    }

    public function test_admission_rechecks_window_after_waiting_for_a_slot(): void
    {
        config(['opsifin_cron.direct.concurrency' => 1, 'opsifin_cron.direct.heartbeat_sec' => 30]);
        $first = $this->pending();
        $late = $this->pending();
        $this->transport->onTick = function (FakeDirectHttpTransport $transport) use ($first): void {
            if ($transport->ticks === 1) {
                $this->travel(56)->seconds();
                $transport->settle($first->id);
            }
        };
        app(DirectPoolExecutor::class)->work(once: true);
        app(DueScheduleDispatcher::class)->expirePendingRuns();
        $this->assertSame(RunStatus::Skipped, $late->fresh()->status);
        $this->assertNull($late->fresh()->started_at);
        $this->assertCount(1, $this->transport->sent);
    }

    public function test_queue_worker_cannot_claim_direct_pending_and_lifecycle_cannot_claim_twice(): void
    {
        Http::fake();
        $run = $this->pending();
        app(RunWorker::class)->process($run->id);
        $this->assertSame(RunStatus::Pending, $run->fresh()->status);
        Http::assertNothingSent();
        $lifecycle = app(RunExecutionLifecycle::class);
        $claimed = $lifecycle->claim($run->id, RunStatus::Pending);
        $this->assertNotNull($claimed);
        $this->assertNull($lifecycle->claim($run->id, RunStatus::Pending));
        $lifecycle->fail($claimed, 'Terminal failure');
        $lifecycle->complete($claimed, new ExecutionResult(true, 200, 'late', null, 2));
        $this->assertSame(RunStatus::Failed, $run->fresh()->status);
    }

    public function test_database_lease_prevents_parallel_daemons_and_restart_before_deadline(): void
    {
        $lease = new DirectExecutorLease;
        $this->assertTrue($lease->acquire());
        $this->assertFalse((new DirectExecutorLease)->acquire());
        $lease->release();
        $run = $this->pending(['status' => 'running', 'execution_deadline_at' => now()->addMinute()]);
        $this->assertFalse((new DirectExecutorLease)->acquire());
        $this->travel(61)->seconds();
        app(DueScheduleDispatcher::class)->recoverExpiredRuns(now());
        $this->assertTrue((new DirectExecutorLease)->acquire());
        $this->assertSame(RunStatus::Failed, $run->fresh()->status);
    }

    public function test_stop_drains_in_flight_without_claiming_waiting_work(): void
    {
        config(['opsifin_cron.direct.concurrency' => 1]);
        $first = $this->pending();
        $waiting = $this->pending();
        $executor = app(DirectPoolExecutor::class);
        $this->transport->onTick = function (FakeDirectHttpTransport $transport) use ($executor, $first): void {
            $executor->stop();
            $transport->settle($first->id);
        };
        $executor->work(once: true);
        $this->assertSame(RunStatus::Succeeded, $first->fresh()->status);
        $this->assertSame(RunStatus::Pending, $waiting->fresh()->status);
        $this->assertNull(DB::table('executor_states')->where('name', 'direct')->value('owner'));
    }

    public function test_rollback_routes_only_new_occurrences_to_queue_and_blocks_direct_retry(): void
    {
        Queue::fake();
        $old = $this->pending(['status' => 'failed']);
        config(['opsifin_cron.execution_driver' => 'queue']);
        $new = app(RunDispatcher::class)->manual($old->schedule);
        $this->assertSame(RunStatus::Queued, $new->status);
        $this->assertSame('queue', $new->execution_driver);
        Queue::assertPushed(ExecuteRun::class, 1);
        $this->expectException(\InvalidArgumentException::class);
        app(RunDispatcher::class)->retry($old);
    }

    public function test_health_reports_missing_heartbeat_and_lag_percentiles_from_database(): void
    {
        $this->pending(['status' => 'succeeded', 'started_at' => now(), 'start_lag_ms' => 1200, 'duration_ms' => 500]);
        $this->pending(['status' => 'succeeded', 'started_at' => now(), 'start_lag_ms' => 50000, 'duration_ms' => 700]);
        $this->pending(['status' => 'skipped', 'duration_ms' => 0]);
        $health = app(DirectExecutionHealth::class)->snapshot();
        $this->assertFalse($health['executor_online']);
        $this->assertFalse($health['dispatcher_online']);
        $this->assertSame(1200, $health['percentiles_24h']['start_lag_ms']['p50']);
        $this->assertSame(50000, $health['percentiles_24h']['start_lag_ms']['p99']);
        $this->assertSame(500, $health['percentiles_24h']['duration_ms']['p50']);
        $this->assertCount(3, $health['alerts']);
        $this->artisan('jobs:direct-status --json')->expectsOutputToContain('"executor_online":false')->assertFailed();
    }

    public function test_direct_admin_views_show_pending_health_and_hide_retry(): void
    {
        $this->actingAs($this->user());
        $failed = $this->pending(['status' => 'failed']);
        $this->get('/admin')->assertSuccessful()->assertDontSee('href="/horizon"', false);
        Livewire::withoutLazyLoading()->test(RunHealthOverview::class)
            ->assertSee('Direct executor')->assertSee('Pending');
        $this->get('/admin/runs/'.$failed->id)->assertSuccessful()->assertSee('Start lag')->assertDontSee('mountAction(\'retry\'', false);
        $this->assertFalse(auth()->user()->can('retry', $failed));
    }

    public function test_health_remains_unhealthy_after_missed_occurrence_is_terminal(): void
    {
        DB::table('executor_states')->where('name', 'direct')->update([
            'owner' => 'healthy-executor', 'heartbeat_at' => now(), 'expires_at' => now()->addMinute(),
        ]);
        DB::table('executor_states')->where('name', 'dispatcher')->update(['heartbeat_at' => now()]);
        $this->pending(['scheduled_for' => now()->subMinute()]);
        app(DueScheduleDispatcher::class)->expirePendingRuns();

        $health = app(DirectExecutionHealth::class)->snapshot();
        $this->assertSame(0, $health['expired_pending']);
        $this->assertSame(1, $health['missed_start_window_24h']);
        $this->assertCount(1, $health['alerts']);
        $this->artisan('jobs:direct-status --json')->assertFailed();
    }

    public function test_callback_failure_isolated_and_final_sweep_never_replays_http(): void
    {
        $first = $this->pending();
        $second = $this->pending();
        $lifecycle = \Mockery::mock(RunExecutionLifecycle::class, [app(ExecutorManager::class)])->makePartial();
        $lifecycle->shouldReceive('complete')->withArgs(fn ($execution) => $execution->run->id === $first->id)->andThrow(new \RuntimeException('Persistence unavailable'));
        $this->app->instance(RunExecutionLifecycle::class, $lifecycle);
        $result = app(DirectPoolExecutor::class)->work(once: true);
        $this->assertTrue($result['failed']);
        $this->assertSame(RunStatus::Succeeded, $second->fresh()->status);
        $this->assertSame(RunStatus::Running, $first->fresh()->status);
        $this->assertCount(2, $this->transport->sent);
    }
}
