<?php

namespace Tests\Feature;

use App\Services\Maintenance\RetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\TestCase;

class RetentionTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    public function test_only_old_terminal_runs_are_purged(): void
    {
        $schedule = $this->schedule();
        $old = $this->occurrence($schedule, ['status' => 'succeeded', 'scheduled_for' => now()->subDays(100)]);
        $queued = $this->occurrence($schedule, ['status' => 'queued', 'scheduled_for' => now()->subDays(100), 'materialization_key' => null]);
        $recent = $this->occurrence($schedule, ['status' => 'failed', 'scheduled_for' => now()->subDay(), 'materialization_key' => null]);

        $deleted = app(RetentionService::class)->purge(90, 100);

        $this->assertSame(1, $deleted);
        $this->assertModelMissing($old);
        $this->assertModelExists($queued);
        $this->assertModelExists($recent);
    }
}
