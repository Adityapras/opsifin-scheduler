<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\TestCase;

class TelescopeAccessTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    public function test_administrator_can_access_telescope(): void
    {
        $this->actingAs($this->user(UserRole::Admin));

        $this->assertTrue(Gate::allows('viewTelescope'));
    }

    public function test_operator_cannot_access_telescope(): void
    {
        $this->actingAs($this->user(UserRole::Operator));

        $this->assertFalse(Gate::allows('viewTelescope'));
    }

    public function test_guest_cannot_access_telescope(): void
    {
        $this->assertFalse(Gate::allows('viewTelescope'));
    }
}
