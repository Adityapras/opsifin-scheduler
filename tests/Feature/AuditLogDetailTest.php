<?php

namespace Tests\Feature;

use App\Filament\Resources\AuditLogs\AuditLogPresenter;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Schedules\Pages\EditSchedule;
use App\Filament\Resources\TaskTemplates\Pages\EditTaskTemplate;
use App\Models\AuditLog;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\TestCase;

class AuditLogDetailTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    public function test_update_changes_are_listed_per_field_and_secrets_stay_redacted(): void
    {
        $this->actingAs($this->user());
        $client = $this->schedule()->client;
        $client->update(['name' => 'Renamed client', 'auth_secret' => 'new-secret']);

        $log = AuditLog::query()->where('action', 'updated')->where('entity_type', Client::class)->latest('id')->firstOrFail();
        $rows = collect(AuditLogPresenter::changes($log))->keyBy('field');

        $this->assertSame('Test Client', $rows['name']['before']);
        $this->assertSame('Renamed client', $rows['name']['after']);
        $this->assertTrue($rows['name']['changed']);
        $this->assertSame('[redacted]', $rows['auth_secret']['after']);
        $this->assertSame($client->code, AuditLogPresenter::entityLabel($log));
    }

    public function test_json_strings_and_arrays_are_formatted_the_same_way(): void
    {
        $log = new AuditLog([
            'action' => 'updated',
            'entity_type' => Client::class,
            'before' => ['config' => ['path' => '/a']],
            'after' => ['config' => '{"path":"/b"}'],
        ]);

        $row = AuditLogPresenter::changes($log)[0];

        $this->assertStringContainsString('"path": "/a"', $row['before']);
        $this->assertStringContainsString('"path": "/b"', $row['after']);
        $this->assertTrue($row['changed']);
    }

    public function test_iso_utc_timestamps_are_shown_in_local_time(): void
    {
        $log = new AuditLog([
            'action' => 'updated',
            'entity_type' => Client::class,
            'before' => ['updated_at' => '2026-09-14T10:34:02.000000Z'],
            'after' => ['updated_at' => '2026-09-14 17:36:31'],
        ]);

        $this->assertSame('2026-09-14 17:34:02', AuditLogPresenter::changes($log)[0]['before']);
    }

    public function test_details_panel_opens_from_the_audit_table(): void
    {
        $admin = $this->user();
        $this->actingAs($admin);
        $schedule = $this->schedule();
        $log = AuditLog::query()->where('entity_type', Client::class)->firstOrFail();

        Livewire::actingAs($admin)->test(ListAuditLogs::class)
            ->mountTableAction('view', $log)
            ->assertMountedActionModalSee(['Changes', $schedule->client->code, 'base_url']);
    }

    public function test_hidden_review_and_migration_cards_are_not_rendered(): void
    {
        $schedule = $this->schedule();
        $admin = $this->user();

        Livewire::actingAs($admin)->test(EditClient::class, ['record' => $schedule->client->getRouteKey()])
            ->assertFormFieldDoesNotExist('review_notes')
            ->assertFormFieldDoesNotExist('notes')
            ->assertFormFieldDoesNotExist('legacy_config_file')
            ->assertFormFieldDoesNotExist('legacy_script_dir');
        Livewire::actingAs($admin)->test(EditSchedule::class, ['record' => $schedule->getRouteKey()])
            ->assertFormFieldDoesNotExist('legacy_command');
        Livewire::actingAs($admin)->test(EditTaskTemplate::class, ['record' => $schedule->taskTemplate->getRouteKey()])
            ->assertFormFieldDoesNotExist('legacy_job_file')
            ->assertFormFieldDoesNotExist('needs_review');
    }
}
