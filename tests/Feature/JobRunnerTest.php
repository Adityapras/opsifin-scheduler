<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Client;
use App\Models\Run;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use App\Services\Runner\JobLock;
use App\Services\Runner\JobRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JobRunnerTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(array $attributes = []): Schedule
    {
        config(['opsifin_cron.lock_dir' => storage_path('framework/testing/locks')]);

        $client = Client::create([
            'code' => 'gn',
            'name' => 'Golden Nusa',
            'base_url' => 'https://goldennusa.opsifin.test',
            'auth_type' => 'basic',
            'auth_username' => 'rest_gn',
            'auth_secret' => 'rahasia',
        ]);

        $template = TaskTemplate::create([
            'key' => 'repost',
            'name' => 'Repost',
            'http_method' => 'POST',
            'path_template' => '/apiv_g/api_repost',
            'body_template' => '{}',
            'default_timeout_sec' => 5,
            'default_connect_timeout_sec' => 2,
        ]);

        return Schedule::create(array_merge([
            'client_id' => $client->id,
            'task_template_id' => $template->id,
            'cron_expression' => '*/6 * * * *',
            'timezone' => 'Asia/Jakarta',
            'lock_key' => 'gn.repost',
            'lock_mode' => 'skip',
            'is_enabled' => true,
        ], $attributes));
    }

    public function test_successful_call_is_recorded(): void
    {
        Http::fake(['*' => Http::response('{"ok":true}', 200)]);

        $run = app(JobRunner::class)->run($this->makeSchedule());

        $this->assertSame(RunStatus::Success, $run->status);
        $this->assertSame(200, $run->http_status);
        $this->assertSame('{"ok":true}', $run->response_excerpt);
        $this->assertSame('https://goldennusa.opsifin.test/apiv_g/api_repost', $run->request_url);
        $this->assertNotNull($run->finished_at);
    }

    public function test_sends_resolved_headers_and_body(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        app(JobRunner::class)->run($this->makeSchedule());

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://goldennusa.opsifin.test/apiv_g/api_repost'
                && $request->header('Authorization')[0] === 'Basic '.base64_encode('rest_gn:rahasia')
                && $request->body() === '{}';
        });
    }

    public function test_non_2xx_response_is_recorded_as_failed(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $run = app(JobRunner::class)->run($this->makeSchedule());

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame(500, $run->http_status);
        $this->assertStringContainsString('HTTP 500', $run->error_message);
    }

    public function test_connection_failure_is_recorded_as_timeout(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $run = app(JobRunner::class)->run($this->makeSchedule());

        $this->assertSame(RunStatus::Timeout, $run->status);
        $this->assertStringContainsString('timed out', $run->error_message);
    }

    public function test_disabled_schedule_is_skipped_but_still_recorded(): void
    {
        Http::fake();

        $run = app(JobRunner::class)->run($this->makeSchedule(['is_enabled' => false]));

        $this->assertSame(RunStatus::SkippedDisabled, $run->status);
        Http::assertNothingSent();
    }

    public function test_manual_trigger_ignores_disabled_flag(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $run = app(JobRunner::class)->run(
            $this->makeSchedule(['is_enabled' => false]),
            RunTrigger::Manual,
        );

        $this->assertSame(RunStatus::Success, $run->status);
    }

    public function test_overlapping_run_is_recorded_as_skipped_lock(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $schedule = $this->makeSchedule();

        $held = JobLock::acquire($schedule);
        $this->assertNotNull($held);

        $run = app(JobRunner::class)->run($schedule);

        $this->assertSame(RunStatus::SkippedLock, $run->status);
        Http::assertNothingSent();

        $held->release();
    }

    public function test_lock_is_released_so_the_next_run_proceeds(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $schedule = $this->makeSchedule();

        app(JobRunner::class)->run($schedule);
        $second = app(JobRunner::class)->run($schedule);

        $this->assertSame(RunStatus::Success, $second->status);
    }

    public function test_dry_run_does_not_call_the_endpoint(): void
    {
        Http::fake();

        $run = app(JobRunner::class)->run($this->makeSchedule(), RunTrigger::Manual, dryRun: true);

        Http::assertNothingSent();
        $this->assertSame(RunTrigger::DryRun, $run->trigger);
        $this->assertStringContainsString('POST https://goldennusa.opsifin.test/apiv_g/api_repost', $run->response_excerpt);
        $this->assertStringNotContainsString('rest_gn:rahasia', $run->response_excerpt);
    }

    /**
     * Yang tersimpan di `runs` tetap tersamar, tapi pemanggil yang berhak bisa
     * meminta nilai asli untuk ditampilkan di layar.
     */
    public function test_request_summary_can_be_rendered_without_masking(): void
    {
        $schedule = $this->makeSchedule();
        $runner = app(JobRunner::class);

        $masked = $runner->describeRequest($schedule->resolveRequest());
        $plain = $runner->describeRequest($schedule->resolveRequest(), maskSecrets: false);

        $expected = 'Basic '.base64_encode('rest_gn:rahasia');

        $this->assertStringNotContainsString($expected, $masked);
        $this->assertStringContainsString($expected, $plain);
    }

    public function test_updates_last_and_next_run_on_the_schedule(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $schedule = $this->makeSchedule();

        app(JobRunner::class)->run($schedule);
        $schedule->refresh();

        $this->assertNotNull($schedule->last_run_at);
        $this->assertNotNull($schedule->next_run_at);
        $this->assertTrue($schedule->next_run_at->isFuture());
    }

    public function test_run_is_linked_to_client_and_template_for_filtering(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $schedule = $this->makeSchedule();

        app(JobRunner::class)->run($schedule);

        $run = Run::firstOrFail();
        $this->assertSame($schedule->client_id, $run->client_id);
        $this->assertSame($schedule->task_template_id, $run->task_template_id);
    }
}
