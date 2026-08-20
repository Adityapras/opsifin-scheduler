<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthRouteTest extends TestCase
{
    public function test_root_redirects_to_the_admin_panel(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_google_health_check_receives_a_successful_root_response(): void
    {
        $this
            ->withHeader('User-Agent', 'GoogleHC/1.0')
            ->get('/')
            ->assertOk()
            ->assertSeeText('OK');
    }

    public function test_health_endpoint_remains_available_without_a_redirect(): void
    {
        $this->get('/up')->assertOk();
    }
}
