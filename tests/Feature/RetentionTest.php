<?php

namespace Tests\Feature;

use App\Enums\AlertCondition;
use App\Enums\AlertStatus;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Alert;
use App\Models\Client;
use App\Models\Run;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetentionTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(): Schedule
    {
        $client = Client::create([
            'code' => 'gn',
            'name' => 'Golden Nusa',
            'base_url' => 'https://gn.opsifin.test',
            'auth_type' => 'none',
        ]);

        $template = TaskTemplate::create([
            'key' => 'repost',
            'name' => 'Repost',
            'http_method' => 'POST',
            'path_template' => '/apiv_g/api_repost',
        ]);

        return Schedule::create([
            'client_id' => $client->id,
            'task_template_id' => $template->id,
            'cron_expression' => '*/6 * * * *',
            'timezone' => 'Asia/Jakarta',
            'lock_key' => 'gn.repost',
            'lock_mode' => 'skip',
        ]);
    }

    private function makeRun(Schedule $schedule, string $startedAt): Run
    {
        return Run::create([
            'schedule_id' => $schedule->id,
            'client_id' => $schedule->client_id,
            'task_template_id' => $schedule->task_template_id,
            'trigger' => RunTrigger::Cron,
            'status' => RunStatus::Success,
            'started_at' => $startedAt,
            'finished_at' => $startedAt,
            'duration_ms' => 10,
        ]);
    }

    private function alert(Schedule $schedule, AlertStatus $status, string $firedAt): Alert
    {
        return Alert::create([
            'schedule_id' => $schedule->id,
            'client_id' => $schedule->client_id,
            'task_template_id' => $schedule->task_template_id,
            'condition' => AlertCondition::OnFailure,
            'status' => $status,
            'title' => 'something broke',
            'fired_at' => $firedAt,
        ]);
    }

    public function test_purge_removes_runs_past_the_retention_window(): void
    {
        $schedule = $this->makeSchedule();

        $old = $this->makeRun($schedule, now()->subDays(120)->toDateTimeString());
        $recent = $this->makeRun($schedule, now()->subDays(10)->toDateTimeString());

        $this->artisan('cron:purge-runs', ['--days' => 90])->assertSuccessful();

        $this->assertDatabaseMissing('runs', ['id' => $old->id]);
        $this->assertDatabaseHas('runs', ['id' => $recent->id]);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $schedule = $this->makeSchedule();
        $old = $this->makeRun($schedule, now()->subDays(120)->toDateTimeString());

        $this->artisan('cron:purge-runs', ['--days' => 90, '--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('runs', ['id' => $old->id]);
    }

    /**
     * Alert yang belum ditangani justru yang paling perlu dilihat, sekalipun
     * sudah tua — hanya yang sudah ditutup yang boleh ikut dibersihkan.
     */
    public function test_purge_keeps_open_alerts_but_removes_closed_ones(): void
    {
        $schedule = $this->makeSchedule();

        $open = $this->alert($schedule, AlertStatus::Open, now()->subDays(200)->toDateTimeString());
        $resolved = $this->alert($schedule, AlertStatus::Resolved, now()->subDays(200)->toDateTimeString());
        $recent = $this->alert($schedule, AlertStatus::Resolved, now()->subDay()->toDateTimeString());

        $this->artisan('cron:purge-runs', ['--days' => 90])->assertSuccessful();

        $this->assertDatabaseHas('alerts', ['id' => $open->id]);
        $this->assertDatabaseMissing('alerts', ['id' => $resolved->id]);
        $this->assertDatabaseHas('alerts', ['id' => $recent->id]);
    }

    public function test_retention_below_one_day_is_refused(): void
    {
        $this->artisan('cron:purge-runs', ['--days' => 0])->assertFailed();
    }
}
