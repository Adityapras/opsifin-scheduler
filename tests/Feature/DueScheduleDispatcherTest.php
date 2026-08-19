<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ExecuteRun;
use App\Services\Scheduling\DueScheduleDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\TestCase;

class DueScheduleDispatcherTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_due_schedule_is_queued_once_and_next_run_is_advanced(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-08-18 12:15:00 UTC');
        $schedule = $this->schedule();
        $schedule->forceFill(['next_run_at' => now()->subMinutes(10)])->saveQuietly();

        $first = app(DueScheduleDispatcher::class)->dispatch(now());
        $second = app(DueScheduleDispatcher::class)->dispatch(now());

        $this->assertSame(1, $first['queued']);
        $this->assertSame(0, $second['queued']);
        $this->assertDatabaseCount('runs', 1);
        $this->assertTrue($schedule->fresh()->next_run_at->gt(now()));
        Queue::assertPushed(ExecuteRun::class, 1);
    }

    public function test_recovery_dispatches_only_the_latest_overdue_occurrence(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-08-18 12:16:00 UTC');
        $schedule = $this->schedule();
        $schedule->forceFill(['next_run_at' => now()->subHour()])->saveQuietly();

        app(DueScheduleDispatcher::class)->dispatch(now());

        $run = $schedule->runs()->firstOrFail();
        $this->assertSame('2026-08-18 12:15:00', $run->scheduled_for->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, $schedule->runs()->count());
    }

    public function test_busy_schedule_records_one_skipped_run_without_queueing_http_work(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-08-18 12:15:00 UTC');
        $schedule = $this->schedule();
        $running = $this->occurrence($schedule, [
            'status' => RunStatus::Running,
            'started_at' => now(),
            'execution_deadline_at' => now()->addMinute(),
        ]);
        $schedule->forceFill(['running_run_id' => $running->id, 'next_run_at' => now()])->saveQuietly();

        $report = app(DueScheduleDispatcher::class)->dispatch(now());

        $this->assertSame(1, $report['skipped']);
        $this->assertDatabaseHas('runs', ['schedule_id' => $schedule->id, 'status' => RunStatus::Skipped->value]);
        Queue::assertNothingPushed();
    }

    public function test_schedule_can_explicitly_allow_overlap(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-08-18 12:15:00 UTC');
        $schedule = $this->schedule(['prevent_overlap' => false]);
        $running = $this->occurrence($schedule, [
            'status' => RunStatus::Running,
            'started_at' => now(),
            'execution_deadline_at' => now()->addMinute(),
        ]);
        $schedule->forceFill(['running_run_id' => $running->id, 'next_run_at' => now()])->saveQuietly();

        $report = app(DueScheduleDispatcher::class)->dispatch(now());

        $this->assertSame(1, $report['queued']);
        $this->assertDatabaseHas('runs', ['schedule_id' => $schedule->id, 'status' => RunStatus::Queued->value]);
        Queue::assertPushed(ExecuteRun::class, 1);
    }

    public function test_expired_running_job_is_failed_and_its_slot_is_released(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-08-18 12:15:00 UTC');
        $schedule = $this->schedule();
        $run = $this->occurrence($schedule, [
            'status' => RunStatus::Running,
            'started_at' => now()->subMinutes(5),
            'execution_deadline_at' => now()->subMinute(),
        ]);
        $schedule->forceFill(['running_run_id' => $run->id, 'next_run_at' => now()->addHour()])->saveQuietly();

        $report = app(DueScheduleDispatcher::class)->dispatch(now());

        $this->assertSame(1, $report['recovered']);
        $this->assertSame(RunStatus::Failed, $run->fresh()->status);
        $this->assertNull($schedule->fresh()->running_run_id);
    }
}
