<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\RelationManagers\SchedulesRelationManager;
use App\Models\Client;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use App\Services\Scheduling\DefaultScheduleProvisioner;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
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

    public function test_assign_creates_selected_jobs_paused_and_skips_existing_ones(): void
    {
        $client = $this->client();
        // Default template enabled tetap dibuat paused lewat Assign jobs.
        $picked = $this->task(['default_cron_expression' => '0 6 * * *', 'default_schedule_enabled' => true]);
        $existing = $this->task();
        $inactive = $this->task(['is_active' => false]);
        $notPicked = $this->task();
        Schedule::query()->create(['client_id' => $client->id, 'task_template_id' => $existing->id,
            'cron_expression' => '0 1 * * *', 'timezone' => 'Asia/Jakarta', 'queue' => 'default']);

        $result = app(DefaultScheduleProvisioner::class)->assign($client, [$picked->id, $existing->id, $inactive->id]);

        $this->assertSame(['created' => 1, 'skipped' => 2], $result);
        $this->assertDatabaseHas('schedules', ['client_id' => $client->id, 'task_template_id' => $picked->id,
            'cron_expression' => '0 6 * * *', 'timezone' => 'Asia/Jakarta', 'is_enabled' => false]);
        $this->assertSame(1, Schedule::query()->where('task_template_id', $existing->id)->count());
        $this->assertDatabaseMissing('schedules', ['task_template_id' => $notPicked->id]);
    }

    public function test_assign_can_override_cron_and_timezone(): void
    {
        $client = $this->client();
        $task = $this->task();

        app(DefaultScheduleProvisioner::class)->assign($client, [$task->id], '15 2 * * *', 'UTC');

        $this->assertDatabaseHas('schedules', ['task_template_id' => $task->id, 'cron_expression' => '15 2 * * *', 'timezone' => 'UTC']);
    }

    public function test_assign_rejects_an_invalid_custom_cron(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(DefaultScheduleProvisioner::class)->assign($this->client(), [$this->task()->id], 'not a cron');
    }

    public function test_unassigned_tasks_exclude_assigned_and_inactive_jobs(): void
    {
        $client = $this->client();
        $free = $this->task();
        $this->task(['is_active' => false]);
        $taken = $this->task();
        Schedule::query()->create(['client_id' => $client->id, 'task_template_id' => $taken->id,
            'cron_expression' => '*/5 * * * *', 'timezone' => 'Asia/Jakarta', 'queue' => 'default']);

        $this->assertSame([$free->id], app(DefaultScheduleProvisioner::class)->unassignedTasks($client)->pluck('id')->all());
    }

    public function test_assign_jobs_works_from_the_client_schedules_tab(): void
    {
        $client = $this->client();
        $task = $this->task();

        Livewire::actingAs($this->user())
            ->test(SchedulesRelationManager::class, ['ownerRecord' => $client, 'pageClass' => EditClient::class])
            ->callAction(TestAction::make('assignJobs')->table(), ['task_ids' => [$task->id], 'timing' => 'default'])
            ->assertHasNoActionErrors()
            ->assertNotified('1 job(s) assigned to '.$client->code);

        $schedule = Schedule::query()->where('client_id', $client->id)->sole();
        $this->assertFalse($schedule->is_enabled);

        Livewire::actingAs($this->user())
            ->test(SchedulesRelationManager::class, ['ownerRecord' => $client, 'pageClass' => EditClient::class])
            ->assertCanSeeTableRecords([$schedule]);
    }

    public function test_assign_jobs_works_from_the_clients_table_with_a_custom_cron(): void
    {
        $client = $this->client();
        $task = $this->task();

        Livewire::actingAs($this->user())->test(ListClients::class)
            ->callTableAction('assignJobs', $client, [
                'task_ids' => [$task->id], 'timing' => 'custom', 'cron_expression' => '0 3 * * *', 'timezone' => 'Asia/Jakarta',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('schedules', ['client_id' => $client->id, 'cron_expression' => '0 3 * * *', 'is_enabled' => false]);
    }

    public function test_client_schedules_tab_supports_the_schedule_bulk_actions(): void
    {
        $client = $this->client();
        $first = Schedule::query()->create(['client_id' => $client->id, 'task_template_id' => $this->task()->id,
            'cron_expression' => '*/5 * * * *', 'timezone' => 'Asia/Jakarta', 'queue' => 'default']);
        $second = Schedule::query()->create(['client_id' => $client->id, 'task_template_id' => $this->task()->id,
            'cron_expression' => '*/5 * * * *', 'timezone' => 'Asia/Jakarta', 'queue' => 'default']);
        $tab = fn () => Livewire::actingAs($this->user())
            ->test(SchedulesRelationManager::class, ['ownerRecord' => $client, 'pageClass' => EditClient::class]);

        $tab()->callTableBulkAction('setCron', [$first, $second], ['cron_expression' => '0 4 * * *'])->assertHasNoTableBulkActionErrors();
        $this->assertSame(['0 4 * * *', '0 4 * * *'], [$first->fresh()->cron_expression, $second->fresh()->cron_expression]);

        $tab()->callTableBulkAction('resume', [$first, $second]);
        $this->assertTrue($first->fresh()->is_enabled && $second->fresh()->is_enabled);

        $tab()->callTableBulkAction('pause', [$first]);
        $this->assertFalse($first->fresh()->is_enabled);
        $this->assertTrue($second->fresh()->is_enabled);

        $tab()->callTableBulkAction('deleteSchedules', [$first]);
        $this->assertModelMissing($first);
    }

    public function test_bulk_create_missing_schedules_is_always_paused(): void
    {
        $client = $this->client();
        $task = $this->task(['default_schedule_enabled' => true]);

        Livewire::actingAs($this->user())->test(ListClients::class)
            ->callTableBulkAction('createMissingSchedules', [$client])
            ->assertNotified('1 missing schedule(s) created');

        $this->assertDatabaseHas('schedules', ['client_id' => $client->id, 'task_template_id' => $task->id, 'is_enabled' => false]);
    }

    public function test_new_client_provisioning_still_honours_enable_immediately(): void
    {
        $client = $this->client();
        $task = $this->task(['default_schedule_enabled' => true]);

        app(DefaultScheduleProvisioner::class)->provision($client);

        $this->assertDatabaseHas('schedules', ['client_id' => $client->id, 'task_template_id' => $task->id, 'is_enabled' => true]);
    }

    public function test_operators_cannot_assign_jobs(): void
    {
        $client = $this->client();

        Livewire::actingAs($this->user(UserRole::Operator))->test(ListClients::class)
            ->assertTableActionHidden('assignJobs', $client);
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
