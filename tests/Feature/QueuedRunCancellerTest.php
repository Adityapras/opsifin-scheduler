<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Jobs\ExecuteRun;
use App\Models\AuditLog;
use App\Services\Scheduling\QueuedRunCanceller;
use App\Services\Scheduling\RunDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\TestCase;

class QueuedRunCancellerTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    public function test_manual_run_tracks_its_queue_payload_and_can_be_cancelled(): void
    {
        $this->actingAs($this->user());
        $run = app(RunDispatcher::class)->manual($this->schedule());

        $this->assertSame(RunStatus::Queued, $run->status);
        $this->assertNotNull($run->queue_job_id);
        $this->assertDatabaseHas('jobs', ['id' => $run->queue_job_id]);

        app(QueuedRunCanceller::class)->cancel($run);

        $run->refresh();
        $this->assertSame(RunStatus::Cancelled, $run->status);
        $this->assertSame('Cancelled before execution.', $run->error_message);
        $this->assertDatabaseMissing('jobs', ['id' => $run->queue_job_id]);
        $this->assertTrue(AuditLog::query()->where('action', 'cancelled')->where('entity_id', $run->id)->exists());
    }

    public function test_legacy_queued_run_without_a_tracked_job_id_can_be_cancelled(): void
    {
        $run = $this->occurrence($this->schedule());
        $jobId = Queue::connection('database')->pushOn('default', new ExecuteRun($run->id));

        app(QueuedRunCanceller::class)->cancel($run);

        $this->assertDatabaseMissing('jobs', ['id' => $jobId]);
        $this->assertSame(RunStatus::Cancelled, $run->fresh()->status);
    }

    public function test_queued_run_without_a_payload_id_is_reconciled(): void
    {
        $run = $this->occurrence($this->schedule(), [
            'queued_at' => now()->subMinutes(2),
            'queue_job_id' => null,
        ]);

        $this->artisan('jobs:reconcile-queued')->assertSuccessful();

        $this->assertNotNull($run->fresh()->queue_job_id);
        $this->assertDatabaseHas('jobs', ['id' => $run->fresh()->queue_job_id]);
    }

    public function test_running_run_cannot_be_cancelled(): void
    {
        $run = $this->occurrence($this->schedule(), ['status' => RunStatus::Running]);

        $this->expectException(InvalidArgumentException::class);
        app(QueuedRunCanceller::class)->cancel($run);
    }

    public function test_execute_run_exposes_a_telescope_run_tag(): void
    {
        $this->assertSame(['run:42'], (new ExecuteRun(42))->tags());
    }
}
