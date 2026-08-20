<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Models\Client;
use App\Models\TaskTemplate;
use App\Services\Scheduling\DefaultScheduleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\TestCase;

class DefaultScheduleProvisionerTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    public function test_it_creates_paused_defaults_for_unassigned_active_services(): void
    {
        $client = $this->client();
        $included = $this->task([
            'default_cron_expression' => '0 6 * * *',
            'default_prevent_overlap' => true,
        ]);
        $this->task(['is_active' => false]);
        $this->task(['auto_assign_to_new_clients' => false]);

        $created = app(DefaultScheduleProvisioner::class)->provision($client);

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('schedules', [
            'client_id' => $client->id,
            'task_template_id' => $included->id,
            'cron_expression' => '0 6 * * *',
            'timezone' => 'Asia/Jakarta',
            'is_enabled' => false,
            'prevent_overlap' => true,
        ]);
        $this->assertSame(0, app(DefaultScheduleProvisioner::class)->provision($client));
    }

    public function test_create_client_page_provisions_default_schedules(): void
    {
        $this->task();

        Livewire::actingAs($this->user())
            ->test(CreateClient::class)
            ->fillForm([
                'code' => 'new-client',
                'name' => 'New Client',
                'base_url' => 'https://new-client.example.test',
                'timezone' => 'Asia/Jakarta',
                'is_active' => true,
                'auth_type' => 'none',
                'provision_default_schedules' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $client = Client::query()->where('code', 'new-client')->firstOrFail();

        $this->assertSame(1, $client->schedules()->count());
        $this->assertFalse((bool) $client->schedules()->firstOrFail()->is_enabled);
    }

    public function test_create_client_page_can_skip_default_schedule_provisioning(): void
    {
        $this->task();

        Livewire::actingAs($this->user())
            ->test(CreateClient::class)
            ->fillForm([
                'code' => 'manual-client',
                'name' => 'Manual Client',
                'base_url' => 'https://manual-client.example.test',
                'timezone' => 'Asia/Jakarta',
                'is_active' => true,
                'auth_type' => 'none',
                'provision_default_schedules' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $client = Client::query()->where('code', 'manual-client')->firstOrFail();

        $this->assertSame(0, $client->schedules()->count());
    }

    private function client(): Client
    {
        return Client::query()->create([
            'code' => 'client-'.uniqid(),
            'name' => 'Test Client',
            'base_url' => 'https://client.example.test',
            'timezone' => 'Asia/Jakarta',
            'auth_type' => 'none',
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function task(array $attributes = []): TaskTemplate
    {
        return TaskTemplate::query()->create(array_merge([
            'key' => 'task_'.uniqid(),
            'name' => 'Test Task',
            'executor' => 'http',
            'config' => [
                'method' => 'POST',
                'path' => '/api/process',
                'body' => '{}',
                'headers' => [],
            ],
            'timeout_sec' => 60,
            'connect_timeout_sec' => 10,
            'is_active' => true,
            'auto_assign_to_new_clients' => true,
            'default_cron_expression' => '*/5 * * * *',
            'default_schedule_enabled' => false,
            'default_prevent_overlap' => true,
        ], $attributes));
    }
}
