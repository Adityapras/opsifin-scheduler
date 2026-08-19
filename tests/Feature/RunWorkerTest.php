<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Services\Scheduling\RunWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\TestCase;

class RunWorkerTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    public function test_successful_http_call_completes_the_run(): void
    {
        Http::fake(['*' => Http::response('done', 200)]);
        $schedule = $this->schedule();
        $run = $this->occurrence($schedule);

        app(RunWorker::class)->process($run->id);

        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status);
        $this->assertSame(200, $run->fresh()->http_status);
        $this->assertSame('done', $run->fresh()->response_excerpt);
        $this->assertNull($schedule->fresh()->running_run_id);
        Http::assertSentCount(1);
    }

    public function test_failed_http_call_is_not_retried_automatically(): void
    {
        Http::fake(['*' => Http::response('temporary', 500)]);
        $schedule = $this->schedule();
        $run = $this->occurrence($schedule);

        app(RunWorker::class)->process($run->id);

        $this->assertSame(RunStatus::Failed, $run->fresh()->status);
        $this->assertSame(500, $run->fresh()->http_status);
        Http::assertSentCount(1);
    }

    public function test_overlap_is_skipped_without_sending_http_request(): void
    {
        Http::fake();
        $schedule = $this->schedule(['running_run_id' => 999]);
        $run = $this->occurrence($schedule);

        app(RunWorker::class)->process($run->id);

        $this->assertSame(RunStatus::Skipped, $run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_overlap_guard_can_be_disabled_for_a_schedule(): void
    {
        Http::fake(['*' => Http::response('done', 200)]);
        $schedule = $this->schedule(['running_run_id' => 999, 'prevent_overlap' => false]);
        $run = $this->occurrence($schedule);

        app(RunWorker::class)->process($run->id);

        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_disabled_scheduled_run_is_skipped_after_queueing(): void
    {
        Http::fake();
        $schedule = $this->schedule(['is_enabled' => false]);
        $run = $this->occurrence($schedule);

        app(RunWorker::class)->process($run->id);

        $this->assertSame(RunStatus::Skipped, $run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_response_excerpt_masks_client_secrets(): void
    {
        Http::fake(['*' => Http::response('secret=super-secret key=secret-key', 500)]);
        $schedule = $this->schedule();
        $run = $this->occurrence($schedule);

        app(RunWorker::class)->process($run->id);

        $this->assertStringNotContainsString('super-secret', $run->fresh()->response_excerpt);
        $this->assertStringNotContainsString('secret-key', $run->fresh()->response_excerpt);
    }
}
