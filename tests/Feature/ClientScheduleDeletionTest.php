<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Schedules\Pages\EditSchedule;
use App\Filament\Resources\Schedules\Pages\ListSchedules;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Schedule;
use App\Services\Maintenance\ClientDeleter;
use App\Services\Maintenance\ScheduleDeleter;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\TestCase;

class ClientScheduleDeletionTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    public function test_a_client_with_schedules_cannot_be_deleted(): void
    {
        $schedule = $this->schedule();

        $this->expectException(InvalidArgumentException::class);

        try {
            app(ClientDeleter::class)->delete($schedule->client);
        } finally {
            $this->assertModelExists($schedule->client);
            $this->assertModelExists($schedule);
        }
    }

    public function test_a_client_without_schedules_is_deleted_and_audited(): void
    {
        $this->actingAs($this->user());
        $schedule = $this->schedule();
        $client = $schedule->client;
        $run = $this->occurrence($schedule, ['status' => 'succeeded']);

        app(ScheduleDeleter::class)->delete($schedule);
        app(ClientDeleter::class)->delete($client);

        $this->assertModelMissing($client);
        $this->assertModelMissing($schedule);
        // Riwayat Run tetap ada walau Schedule dan Client sudah hilang.
        $this->assertModelExists($run);
        $this->assertNull($run->fresh()->schedule_id);
        $this->assertTrue(AuditLog::query()->where('action', 'deleted')->where('entity_type', Client::class)->where('entity_id', $client->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'deleted')->where('entity_type', Schedule::class)->where('entity_id', $schedule->id)->exists());
    }

    public function test_a_schedule_with_a_running_occurrence_cannot_be_deleted(): void
    {
        $schedule = $this->schedule();
        $run = $this->occurrence($schedule, ['status' => 'running']);
        $schedule->forceFill(['running_run_id' => $run->id])->saveQuietly();

        $result = app(ScheduleDeleter::class)->deleteMany([$schedule]);

        $this->assertSame(['deleted' => 0, 'skipped' => 1], $result);
        $this->assertModelExists($schedule);
    }

    public function test_delete_actions_work_through_filament(): void
    {
        $first = $this->schedule();
        $second = $this->schedule();
        $third = $this->schedule();
        $admin = $this->user();

        Livewire::actingAs($admin)->test(ListSchedules::class)
            ->callTableAction('deleteSchedule', $first)
            ->assertHasNoActionErrors()
            ->callTableBulkAction('deleteSchedules', [$second])
            ->assertHasNoActionErrors();

        $this->assertModelMissing($first);
        $this->assertModelMissing($second);

        Livewire::actingAs($admin)->test(ListClients::class)
            ->callTableAction('deleteClient', $first->client)
            ->callTableBulkAction('deleteClients', [$second->client, $third->client]);

        $this->assertModelMissing($first->client);
        $this->assertModelMissing($second->client);
        // Client ketiga masih punya Schedule sehingga dilewati.
        $this->assertModelExists($third->client);
    }

    public function test_edit_pages_delete_through_the_guarded_actions(): void
    {
        $schedule = $this->schedule();
        $admin = $this->user();

        Livewire::actingAs($admin)->test(EditClient::class, ['record' => $schedule->client->getRouteKey()])
            ->callAction('deleteClient');
        $this->assertModelExists($schedule->client);

        Livewire::actingAs($admin)->test(EditSchedule::class, ['record' => $schedule->getRouteKey()])
            ->callAction('deleteSchedule')
            ->assertRedirect();
        $this->assertModelMissing($schedule);

        Livewire::actingAs($admin)->test(EditClient::class, ['record' => $schedule->client->getRouteKey()])
            ->callAction('deleteClient')
            ->assertRedirect();
        $this->assertModelMissing($schedule->client);
    }

    public function test_operators_cannot_delete_clients_or_schedules(): void
    {
        $schedule = $this->schedule();
        $operator = $this->user(UserRole::Operator);

        Livewire::actingAs($operator)->test(ListSchedules::class)
            ->assertTableActionHidden('deleteSchedule', $schedule);
        Livewire::actingAs($operator)->test(ListClients::class)
            ->assertTableActionHidden('deleteClient', $schedule->client);
    }

    public function test_saving_an_edit_page_asks_for_confirmation(): void
    {
        $client = $this->schedule()->client;

        $page = Livewire::actingAs($this->user())
            ->test(EditClient::class, ['record' => $client->getRouteKey()]);
        $save = (fn (): Action => $this->getSaveFormAction())->call($page->instance());

        $this->assertTrue($save->isConfirmationRequired());

        $page->fillForm(['name' => 'Confirmed name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Confirmed name', $client->fresh()->name);
    }
}
