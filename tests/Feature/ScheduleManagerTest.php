<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Services\Scheduling\ScheduleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\TestCase;

class ScheduleManagerTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_assignment_to_selected_clients_is_idempotent_and_disabled_by_default(): void
    {
        $existing = $this->schedule(['is_enabled' => false]);
        $second = Client::create([
            'code' => 'second', 'name' => 'Second', 'base_url' => 'https://second.example.test',
            'timezone' => 'Asia/Jakarta', 'auth_type' => 'none', 'is_active' => true,
        ]);
        $manager = app(ScheduleManager::class);

        $first = $manager->assign($existing->taskTemplate, [$existing->client_id, $second->id], '0 * * * *', 'Asia/Jakarta');
        $again = $manager->assign($existing->taskTemplate, [$existing->client_id, $second->id], '0 * * * *', 'Asia/Jakarta');

        $this->assertSame(1, $first);
        $this->assertSame(0, $again);
        $this->assertDatabaseCount('schedules', 2);
        $this->assertDatabaseHas('schedules', ['client_id' => $second->id, 'is_enabled' => false, 'next_run_at' => null]);
    }

    public function test_pause_resume_and_bulk_cron_recalculate_next_run(): void
    {
        Carbon::setTestNow('2026-08-18 12:01:00 UTC');
        $schedule = $this->schedule();
        $manager = app(ScheduleManager::class);

        $manager->setEnabled($schedule, false);
        $this->assertFalse($schedule->fresh()->is_enabled);
        $this->assertNull($schedule->fresh()->next_run_at);

        $manager->setEnabled($schedule->fresh(), true);
        $this->assertTrue($schedule->fresh()->next_run_at->gt(now()));

        $manager->changeTimingBulk(collect([$schedule->fresh()]), '0 * * * *');
        $this->assertSame('0 * * * *', $schedule->fresh()->cron_expression);
        $this->assertSame('2026-08-18 13:00:00', $schedule->fresh()->next_run_at->utc()->format('Y-m-d H:i:s'));
    }
}
