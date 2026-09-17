<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Runs\Pages\ListRuns;
use App\Models\AuditLog;
use App\Models\Run;
use App\Services\Maintenance\RunLogDeleter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\TestCase;

class RunLogDeletionTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    public function test_terminal_run_is_deleted_and_the_deletion_is_audited(): void
    {
        $this->actingAs($this->user());
        $schedule = $this->schedule();
        $run = $this->occurrence($schedule, ['status' => 'succeeded', 'http_status' => 200, 'duration_ms' => 120]);

        app(RunLogDeleter::class)->delete($run);

        $this->assertModelMissing($run);

        $audit = AuditLog::query()->where('action', 'deleted')->where('entity_id', $run->id)->sole();
        $this->assertSame(Run::class, $audit->entity_type);
        $this->assertSame('succeeded', $audit->before['status']);
        $this->assertSame(200, $audit->before['http_status']);
    }

    public static function liveStatusProvider(): array
    {
        return [
            'pending' => ['pending'],
            'queued' => ['queued'],
            'running' => ['running'],
        ];
    }

    #[DataProvider('liveStatusProvider')]
    public function test_a_run_that_has_not_finished_cannot_be_deleted(string $status): void
    {
        $schedule = $this->schedule();
        $run = $this->occurrence($schedule, ['status' => $status]);

        $this->expectException(InvalidArgumentException::class);

        try {
            app(RunLogDeleter::class)->delete($run);
        } finally {
            $this->assertModelExists($run);
        }
    }

    public function test_deleting_a_finished_run_releases_a_stale_overlap_slot(): void
    {
        $this->actingAs($this->user());
        $schedule = $this->schedule(['prevent_overlap' => true]);
        $run = $this->occurrence($schedule, ['status' => 'failed']);
        $schedule->forceFill(['running_run_id' => $run->id])->saveQuietly();

        app(RunLogDeleter::class)->delete($run);

        $this->assertNull($schedule->fresh()->running_run_id);
    }

    public function test_bulk_delete_skips_runs_that_are_still_live(): void
    {
        $this->actingAs($this->user());
        $schedule = $this->schedule();
        $done = $this->occurrence($schedule, ['status' => 'succeeded']);
        $live = $this->occurrence($schedule, ['status' => 'running', 'materialization_key' => null]);

        $result = app(RunLogDeleter::class)->deleteMany([$done, $live]);

        $this->assertSame(['deleted' => 1, 'skipped' => 1], $result);
        $this->assertModelMissing($done);
        $this->assertModelExists($live);
    }

    public function test_only_an_administrator_may_delete_a_log(): void
    {
        $schedule = $this->schedule();
        $run = $this->occurrence($schedule, ['status' => 'succeeded']);

        $this->assertTrue($this->user(UserRole::Admin)->can('delete', $run));
        $this->assertFalse($this->user(UserRole::Operator)->can('delete', $run));
        $this->assertFalse($this->user(UserRole::Viewer)->can('delete', $run));
    }

    public function test_a_live_run_is_not_deletable_even_for_an_administrator(): void
    {
        $schedule = $this->schedule();
        $running = $this->occurrence($schedule, ['status' => 'running']);

        $this->assertFalse($this->user(UserRole::Admin)->can('delete', $running));
    }

    public function test_delete_actions_work_through_filament(): void
    {
        $schedule = $this->schedule();
        $done = $this->occurrence($schedule, ['status' => 'succeeded']);
        $alsoDone = $this->occurrence($schedule, ['status' => 'failed', 'materialization_key' => null]);
        $live = $this->occurrence($schedule, ['status' => 'running', 'materialization_key' => null]);

        $page = Livewire::actingAs($this->user())->test(ListRuns::class);

        $page->callTableAction('delete', $done)->assertHasNoActionErrors();
        $this->assertModelMissing($done);

        $page->callTableBulkAction('deleteLogs', [$alsoDone, $live])->assertHasNoActionErrors();
        $this->assertModelMissing($alsoDone);
        $this->assertModelExists($live);
    }

    public function test_purge_old_logs_action_keeps_recent_and_live_occurrences(): void
    {
        $schedule = $this->schedule();
        $old = $this->occurrence($schedule, ['status' => 'succeeded', 'scheduled_for' => now()->subDays(40)]);
        $oldLive = $this->occurrence($schedule, ['status' => 'pending', 'scheduled_for' => now()->subDays(40), 'materialization_key' => null]);
        $recent = $this->occurrence($schedule, ['status' => 'succeeded', 'scheduled_for' => now()->subDay(), 'materialization_key' => null]);

        Livewire::actingAs($this->user())
            ->test(ListRuns::class)
            ->callAction('purgeOldLogs', ['days' => 30])
            ->assertHasNoActionErrors();

        $this->assertModelMissing($old);
        $this->assertModelExists($oldLive);
        $this->assertModelExists($recent);
    }

    public function test_an_operator_cannot_reach_the_delete_actions(): void
    {
        $schedule = $this->schedule();
        $run = $this->occurrence($schedule, ['status' => 'succeeded']);

        Livewire::actingAs($this->user(UserRole::Operator))
            ->test(ListRuns::class)
            ->assertTableActionHidden('delete', $run)
            ->assertTableBulkActionHidden('deleteLogs')
            ->assertActionHidden('purgeOldLogs');

        $this->assertModelExists($run);
    }
}
