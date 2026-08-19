<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Runs\Pages\ListRuns;
use App\Filament\Resources\Schedules\Pages\CreateSchedule;
use App\Filament\Resources\Schedules\Pages\ListSchedules;
use App\Filament\Resources\TaskTemplates\Pages\ListTaskTemplates;
use App\Filament\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function pageProvider(): array
    {
        return [
            'dashboard' => ['/admin'],
            'clients' => ['/admin/clients'],
            'jobs' => ['/admin/task-templates'],
            'schedules' => ['/admin/schedules'],
            'runs' => ['/admin/runs'],
            'summary' => ['/admin/client-summaries'],
            'users' => ['/admin/users'],
            'audit' => ['/admin/audit-logs'],
        ];
    }

    #[DataProvider('pageProvider')]
    public function test_admin_can_open_every_lean_operations_page(string $url): void
    {
        $this->schedule();
        $this->actingAs($this->user())->get($url)->assertSuccessful();
    }

    public function test_login_password_is_hidden_by_default_and_livewire_is_loaded(): void
    {
        $response = $this->get('/admin/login')->assertSuccessful();

        $response->assertSee("x-bind:type=\"isPasswordRevealed ? 'text' : 'password'\"", false);
        $response->assertSee('autocomplete="current-password"', false);
        $response->assertSee('livewire.min.js', false);
    }

    public function test_removed_v2_pages_are_not_available(): void
    {
        $this->actingAs($this->user());

        foreach (['client-task-overrides', 'incidents', 'blackout-windows', 'schedule-matrix', 'occurrence-timeline', 'alert-rules'] as $path) {
            $this->get('/admin/'.$path)->assertNotFound();
        }
    }

    public function test_viewer_can_read_but_cannot_create_schedule(): void
    {
        $this->schedule();
        $viewer = $this->user(UserRole::Viewer);
        $this->actingAs($viewer)->get('/admin/schedules')->assertSuccessful();
        $this->actingAs($viewer)->get('/admin/schedules/create')->assertForbidden();
    }

    public function test_only_administrators_can_open_user_management(): void
    {
        $operator = $this->user(UserRole::Operator);
        $viewer = $this->user(UserRole::Viewer);

        $this->actingAs($operator)->get('/admin/users')->assertForbidden();
        $this->actingAs($viewer);
        $this->assertFalse(UserResource::canViewAny());
    }

    public function test_create_schedule_prefill_keeps_assignment_paused(): void
    {
        $schedule = $this->schedule();
        $clientId = $schedule->client_id;
        $taskId = $schedule->task_template_id;
        $schedule->delete();

        Livewire::actingAs($this->user())
            ->withQueryParams(['client_id' => $clientId, 'task_template_id' => $taskId])
            ->test(CreateSchedule::class)
            ->assertFormSet([
                'client_id' => $clientId,
                'task_template_id' => $taskId,
                'is_enabled' => false,
                'prevent_overlap' => true,
            ]);
    }

    public function test_audit_history_renders_boolean_and_nested_json_changes(): void
    {
        $user = $this->user();
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'updated',
            'entity_type' => Client::class,
            'entity_id' => 123,
            'before' => ['is_active' => true, 'config' => ['retry' => false]],
            'after' => ['is_active' => false, 'config' => ['retry' => true]],
            'ip' => '127.0.0.1',
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/admin/audit-logs')
            ->assertSuccessful()
            ->assertSee('&quot;is_active&quot;:true', false)
            ->assertSee('&quot;is_active&quot;:false', false);
    }

    public function test_runs_have_no_create_or_edit_routes(): void
    {
        $run = $this->occurrence($this->schedule());
        $this->actingAs($this->user())->get('/admin/runs/create')->assertNotFound();
        $this->actingAs($this->user())->get('/admin/runs/'.$run->id.'/edit')->assertNotFound();
        $this->assertSame(1, Run::query()->count());
    }

    public function test_schedules_table_renders_the_latest_cast_run_status(): void
    {
        $schedule = $this->schedule();
        $this->occurrence($schedule, ['status' => RunStatus::Succeeded]);

        Livewire::actingAs($this->user())
            ->test(ListSchedules::class)
            ->assertSee('Succeeded');
    }

    public function test_runs_period_filter_accepts_a_date_range(): void
    {
        $schedule = $this->schedule();
        $withinRange = $this->occurrence($schedule, [
            'scheduled_for' => now()->utc()->startOfMinute(),
            'status' => RunStatus::Succeeded,
        ]);
        $outsideRange = $this->occurrence($schedule, [
            'scheduled_for' => now()->utc()->subDays(3)->startOfMinute(),
            'status' => RunStatus::Succeeded,
        ]);

        Livewire::actingAs($this->user())
            ->test(ListRuns::class)
            ->filterTable('period', [
                'from' => now()->subHour()->format('Y-m-d H:i:s'),
                'until' => now()->addHour()->format('Y-m-d H:i:s'),
            ])
            ->assertCanSeeTableRecords([$withinRange])
            ->assertCanNotSeeTableRecords([$outsideRange]);
    }

    public function test_editing_a_client_loads_decrypted_credentials_and_preserves_them(): void
    {
        $client = $this->schedule()->client;

        Livewire::actingAs($this->user())
            ->test(EditClient::class, ['record' => $client->getRouteKey()])
            ->assertFormSet([
                'auth_secret' => 'super-secret',
                'auth_secret_key' => 'secret-key',
            ])
            ->fillForm(['name' => 'Updated client'])
            ->call('save')
            ->assertHasNoFormErrors();

        $client->refresh();
        $this->assertSame('Updated client', $client->name);
        $this->assertSame('super-secret', $client->auth_secret);
        $this->assertSame('secret-key', $client->auth_secret_key);
    }

    public function test_schedule_bulk_cron_pause_and_resume_actions_work_through_filament(): void
    {
        $schedule = $this->schedule();
        $page = Livewire::actingAs($this->user())->test(ListSchedules::class);

        $page->callTableBulkAction('pause', [$schedule])->assertHasNoActionErrors();
        $this->assertFalse($schedule->fresh()->is_enabled);
        $this->assertNull($schedule->fresh()->next_run_at);

        $page->callTableBulkAction('setCron', [$schedule], [
            'cron_expression' => '0 * * * *',
            'timezone' => 'Asia/Jakarta',
        ])->assertHasNoActionErrors();
        $this->assertSame('0 * * * *', $schedule->fresh()->cron_expression);

        $page->callTableBulkAction('resume', [$schedule])->assertHasNoActionErrors();
        $this->assertTrue($schedule->fresh()->is_enabled);
        $this->assertNotNull($schedule->fresh()->next_run_at);
    }

    public function test_task_assignment_and_removal_actions_work_through_filament(): void
    {
        $existing = $this->schedule(['is_enabled' => false]);
        $active = Client::create([
            'code' => 'active-client', 'name' => 'Active client', 'base_url' => 'https://active.example.test',
            'timezone' => 'Asia/Jakarta', 'auth_type' => 'none', 'is_active' => true,
        ]);
        $inactive = Client::create([
            'code' => 'inactive-client', 'name' => 'Inactive client', 'base_url' => 'https://inactive.example.test',
            'timezone' => 'Asia/Jakarta', 'auth_type' => 'none', 'is_active' => false,
        ]);
        $page = Livewire::actingAs($this->user())->test(ListTaskTemplates::class);

        $page->callTableAction('assignAllActive', $existing->taskTemplate, [
            'cron_expression' => '*/10 * * * *',
            'timezone' => 'Asia/Jakarta',
            'is_enabled' => false,
        ])->assertHasNoActionErrors();

        $this->assertDatabaseHas('schedules', ['client_id' => $active->id, 'task_template_id' => $existing->task_template_id]);
        $this->assertDatabaseMissing('schedules', ['client_id' => $inactive->id, 'task_template_id' => $existing->task_template_id]);

        $page->callTableAction('assignSelected', $existing->taskTemplate, [
            'client_ids' => [$inactive->id],
            'cron_expression' => '*/15 * * * *',
            'timezone' => 'Asia/Jakarta',
            'is_enabled' => false,
        ])->assertHasNoActionErrors();
        $this->assertDatabaseHas('schedules', ['client_id' => $inactive->id, 'task_template_id' => $existing->task_template_id]);

        $page->callTableAction('removeSelected', $existing->taskTemplate, [
            'client_ids' => [$active->id, $inactive->id],
        ])->assertHasNoActionErrors();
        $this->assertDatabaseCount('schedules', 1);
    }
}
