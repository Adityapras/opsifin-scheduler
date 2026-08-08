<?php

namespace Tests\Feature;

use App\Enums\AlertCondition;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Client;
use App\Models\Run;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\Alerting\AlertEvaluator;
use App\Services\Runner\JobRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlertingTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(string $cron = '*/6 * * * *'): Schedule
    {
        User::create([
            'name' => 'Ops',
            'email' => 'ops@opsifin.test',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $client = Client::create([
            'code' => 'gn',
            'name' => 'Golden Nusa',
            'base_url' => 'https://gn.opsifin.test',
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
        ]);

        return Schedule::create([
            'client_id' => $client->id,
            'task_template_id' => $template->id,
            'cron_expression' => $cron,
            'timezone' => 'Asia/Jakarta',
            'lock_key' => 'gn.repost',
            'lock_mode' => 'skip',
            'is_enabled' => true,
        ]);
    }

    private function rule(AlertCondition $condition, array $attributes = []): AlertRule
    {
        return AlertRule::create(array_merge([
            'name' => $condition->label(),
            'condition' => $condition,
            'threshold' => 1,
            'grace_minutes' => 10,
            'cooldown_minutes' => 0,
            'is_active' => true,
        ], $attributes));
    }

    private function recordRun(Schedule $schedule, RunStatus $status, ?string $startedAt = null): Run
    {
        return Run::create([
            'schedule_id' => $schedule->id,
            'client_id' => $schedule->client_id,
            'task_template_id' => $schedule->task_template_id,
            'trigger' => RunTrigger::Cron,
            'status' => $status,
            'started_at' => $startedAt ?? now(),
            'finished_at' => now(),
            'duration_ms' => 120,
        ]);
    }

    public function test_a_failed_run_fires_an_alert(): void
    {
        $schedule = $this->makeSchedule();
        $this->rule(AlertCondition::OnFailure);

        $run = $this->recordRun($schedule, RunStatus::Failed);
        app(AlertEvaluator::class)->evaluateRun($run);

        $this->assertDatabaseCount('alerts', 1);
        $this->assertSame($schedule->id, Alert::first()->schedule_id);
    }

    public function test_a_successful_run_fires_nothing(): void
    {
        $schedule = $this->makeSchedule();
        $this->rule(AlertCondition::OnFailure);

        app(AlertEvaluator::class)->evaluateRun($this->recordRun($schedule, RunStatus::Success));

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_dry_runs_never_fire_alerts(): void
    {
        $schedule = $this->makeSchedule();
        $this->rule(AlertCondition::OnFailure);

        $run = $this->recordRun($schedule, RunStatus::Failed);
        $run->update(['trigger' => RunTrigger::DryRun]);

        app(AlertEvaluator::class)->evaluateRun($run->fresh());

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_cooldown_suppresses_repeat_alerts_for_the_same_target(): void
    {
        $schedule = $this->makeSchedule();
        $this->rule(AlertCondition::OnFailure, ['cooldown_minutes' => 60]);

        $evaluator = app(AlertEvaluator::class);
        $evaluator->evaluateRun($this->recordRun($schedule, RunStatus::Failed));
        $evaluator->evaluateRun($this->recordRun($schedule, RunStatus::Failed));

        $this->assertDatabaseCount('alerts', 1);
    }

    public function test_consecutive_failures_only_fire_once_the_threshold_is_reached(): void
    {
        $schedule = $this->makeSchedule();
        $this->rule(AlertCondition::ConsecutiveFailures, ['threshold' => 3]);

        $evaluator = app(AlertEvaluator::class);

        $evaluator->evaluateRun($this->recordRun($schedule, RunStatus::Failed, now()->subMinutes(12)->toDateTimeString()));
        $evaluator->evaluateRun($this->recordRun($schedule, RunStatus::Failed, now()->subMinutes(6)->toDateTimeString()));
        $this->assertDatabaseCount('alerts', 0);

        $evaluator->evaluateRun($this->recordRun($schedule, RunStatus::Failed));
        $this->assertDatabaseCount('alerts', 1);
    }

    public function test_a_success_in_between_resets_the_consecutive_counter(): void
    {
        $schedule = $this->makeSchedule();
        $this->rule(AlertCondition::ConsecutiveFailures, ['threshold' => 3]);

        $evaluator = app(AlertEvaluator::class);

        $evaluator->evaluateRun($this->recordRun($schedule, RunStatus::Failed, now()->subMinutes(18)->toDateTimeString()));
        $evaluator->evaluateRun($this->recordRun($schedule, RunStatus::Success, now()->subMinutes(12)->toDateTimeString()));
        $evaluator->evaluateRun($this->recordRun($schedule, RunStatus::Failed, now()->subMinutes(6)->toDateTimeString()));
        $evaluator->evaluateRun($this->recordRun($schedule, RunStatus::Failed));

        $this->assertDatabaseCount('alerts', 0);
    }

    /**
     * Waktu dibekukan siang hari untuk schedule harian pukul 03:00, supaya
     * "jadwal terakhir" selalu jauh melewati grace. Tanpa ini hasilnya berubah
     * tergantung menit ke berapa test kebetulan dijalankan.
     */
    private function freezeMidday(): void
    {
        $this->travelTo(Carbon::parse('2026-08-04 12:00:00', 'Asia/Jakarta'));
    }

    public function test_a_schedule_that_never_ran_is_reported_as_missed(): void
    {
        $this->freezeMidday();

        $schedule = $this->makeSchedule('0 3 * * *');
        $this->rule(AlertCondition::MissedRun, ['grace_minutes' => 5]);

        // Diubah langsung supaya updated_at tidak ikut disegarkan: schedule yang
        // baru saja disentuh sengaja tidak dianggap terlambat.
        DB::table('schedules')->where('id', $schedule->id)->update([
            'last_run_at' => null,
            'updated_at' => now()->subDay(),
        ]);

        app(AlertEvaluator::class)->evaluateMissedRuns();

        $this->assertDatabaseCount('alerts', 1);
        $this->assertSame(AlertCondition::MissedRun, Alert::first()->condition);
    }

    public function test_a_freshly_enabled_schedule_is_not_reported_as_missed(): void
    {
        $this->freezeMidday();

        $this->makeSchedule('0 3 * * *');
        $this->rule(AlertCondition::MissedRun, ['grace_minutes' => 5]);

        app(AlertEvaluator::class)->evaluateMissedRuns();

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_a_schedule_that_ran_on_time_is_not_reported_as_missed(): void
    {
        $this->freezeMidday();

        $schedule = $this->makeSchedule('0 3 * * *');
        $this->rule(AlertCondition::MissedRun, ['grace_minutes' => 5]);

        DB::table('schedules')->where('id', $schedule->id)->update([
            'last_run_at' => now(),
            'updated_at' => now()->subDay(),
        ]);

        app(AlertEvaluator::class)->evaluateMissedRuns();

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_disabled_schedules_are_never_reported_as_missed(): void
    {
        $this->freezeMidday();

        $schedule = $this->makeSchedule('0 3 * * *');
        $this->rule(AlertCondition::MissedRun, ['grace_minutes' => 5]);

        DB::table('schedules')->where('id', $schedule->id)->update([
            'is_enabled' => false,
            'last_run_at' => null,
            'updated_at' => now()->subDay(),
        ]);

        app(AlertEvaluator::class)->evaluateMissedRuns();

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_a_rule_scoped_to_another_client_does_not_fire(): void
    {
        $schedule = $this->makeSchedule();

        $other = Client::create([
            'code' => 'other',
            'name' => 'Other',
            'base_url' => 'https://other.opsifin.test',
            'auth_type' => 'none',
        ]);

        $this->rule(AlertCondition::OnFailure, ['client_id' => $other->id]);

        app(AlertEvaluator::class)->evaluateRun($this->recordRun($schedule, RunStatus::Failed));

        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_the_runner_fires_alerts_for_a_real_failure(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $schedule = $this->makeSchedule();
        $this->rule(AlertCondition::OnFailure);

        app(JobRunner::class)->run($schedule, RunTrigger::Manual);

        $this->assertDatabaseCount('alerts', 1);
        $this->assertDatabaseHas('notifications', ['notifiable_type' => User::class]);
    }
}
