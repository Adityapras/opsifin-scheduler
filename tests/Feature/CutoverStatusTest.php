<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CutoverStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeClient(array $attributes = []): Client
    {
        $client = Client::create(array_merge([
            'code' => 'gn',
            'name' => 'Golden Nusa',
            'base_url' => 'https://gn.opsifin.test',
            'auth_type' => 'basic',
            'auth_username' => 'rest_gn',
            'auth_secret' => 'rahasia',
        ], $attributes));

        $template = TaskTemplate::firstOrCreate(
            ['key' => 'repost'],
            ['name' => 'Repost', 'http_method' => 'POST', 'path_template' => '/apiv_g/api_repost'],
        );

        Schedule::create([
            'client_id' => $client->id,
            'task_template_id' => $template->id,
            'cron_expression' => '*/6 * * * *',
            'timezone' => 'Asia/Jakarta',
            'lock_key' => $client->code.'.repost',
            'lock_mode' => 'skip',
        ]);

        return $client;
    }

    public function test_a_clean_client_is_reported_as_ready(): void
    {
        $this->makeClient();

        $this->artisan('cron:cutover-status', ['client' => 'gn'])
            ->expectsOutputToContain('No blockers')
            ->assertSuccessful();
    }

    public function test_a_client_flagged_for_review_is_blocked(): void
    {
        $this->makeClient(['needs_review' => true]);

        $this->artisan('cron:cutover-status', ['client' => 'gn'])
            ->expectsOutputToContain('flagged for manual verification')
            ->assertSuccessful();
    }

    public function test_a_client_without_credentials_is_blocked(): void
    {
        $this->makeClient(['auth_secret' => null]);

        $this->artisan('cron:cutover-status', ['client' => 'gn'])
            ->expectsOutputToContain('Credentials incomplete')
            ->assertSuccessful();
    }

    public function test_a_client_with_no_schedule_is_blocked(): void
    {
        Client::create([
            'code' => 'empty',
            'name' => 'No schedules',
            'base_url' => 'https://empty.opsifin.test',
            'auth_type' => 'none',
        ]);

        $this->artisan('cron:cutover-status', ['client' => 'empty'])
            ->expectsOutputToContain('No schedule exists')
            ->assertSuccessful();
    }

    public function test_an_unknown_client_fails(): void
    {
        $this->artisan('cron:cutover-status', ['client' => 'nope'])->assertFailed();
    }
}
