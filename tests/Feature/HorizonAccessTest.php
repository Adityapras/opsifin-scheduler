<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    public function test_administrator_can_access_horizon(): void
    {
        $this->actingAs($this->user(UserRole::Admin));

        $this->assertTrue(Gate::allows('viewHorizon'));
    }

    public function test_operator_cannot_access_horizon(): void
    {
        $this->actingAs($this->user(UserRole::Operator));

        $this->assertFalse(Gate::allows('viewHorizon'));
    }

    public function test_guest_cannot_access_horizon(): void
    {
        $this->assertFalse(Gate::allows('viewHorizon'));
    }

    public function test_queue_timeout_exceeds_horizon_worker_timeout(): void
    {
        $this->assertFalse(config('queue.connections.redis.after_commit'));
        $this->assertSame('queue', config('queue.connections.redis.connection'));
        $this->assertGreaterThan(
            config('horizon.defaults.supervisor-1.timeout'),
            config('queue.connections.redis.retry_after'),
        );
    }
}
