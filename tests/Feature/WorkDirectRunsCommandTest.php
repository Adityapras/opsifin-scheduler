<?php

namespace Tests\Feature;

use App\Services\Execution\DirectHttpTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeDirectHttpTransport;
use Tests\TestCase;

class WorkDirectRunsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_mode_stands_by_without_fetching_work(): void
    {
        $this->artisan('jobs:work-direct --once')->expectsOutputToContain('"standby":true')->assertSuccessful();
    }

    public function test_direct_mode_records_heartbeat_and_exits_cleanly_when_empty(): void
    {
        config(['opsifin_cron.execution_driver' => 'direct']);
        $this->app->instance(DirectHttpTransport::class, new FakeDirectHttpTransport);
        $this->artisan('jobs:work-direct --once')->expectsOutputToContain('"started":0')->assertSuccessful();
        $this->assertDatabaseHas('executor_states', ['name' => 'direct', 'owner' => null]);
        $this->assertNotNull(DB::table('executor_states')->where('name', 'direct')->value('heartbeat_at'));
    }

    public function test_invalid_concurrency_fails_before_any_execution(): void
    {
        config(['opsifin_cron.execution_driver' => 'direct', 'opsifin_cron.direct.concurrency' => 0]);
        $this->artisan('jobs:work-direct --once')->assertFailed();
    }
}
