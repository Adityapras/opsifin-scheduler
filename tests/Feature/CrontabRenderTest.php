<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use App\Services\Crontab\CrontabDeployer;
use App\Services\Crontab\CrontabRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CrontabRenderTest extends TestCase
{
    use RefreshDatabase;

    private string $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->target = storage_path('framework/testing/cron.d/opsifin');
        File::ensureDirectoryExists(dirname($this->target));
        File::delete($this->target);

        config([
            'opsifin_cron.deploy.cron_d_file' => $this->target,
            'opsifin_cron.lock_dir' => '/var/lock/opsifin',
            'opsifin_cron.deploy.base_dir' => '/opt/opsifin-cron',
            'opsifin_cron.deploy.user' => 'ubuntu',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->target));
        File::deleteDirectory(storage_path('app/crontab-backups'));

        parent::tearDown();
    }

    private function makeSchedule(array $attributes = [], string $code = 'gn'): Schedule
    {
        $client = Client::firstOrCreate(['code' => $code], [
            'name' => strtoupper($code),
            'base_url' => "https://{$code}.opsifin.test",
            'auth_type' => 'basic',
            'auth_username' => 'rest_'.$code,
            'auth_secret' => 'rahasia',
        ]);

        $template = TaskTemplate::firstOrCreate(['key' => 'repost'], [
            'name' => 'Repost',
            'http_method' => 'POST',
            'path_template' => '/apiv_g/api_repost',
            'body_template' => '{}',
        ]);

        return Schedule::create(array_merge([
            'client_id' => $client->id,
            'task_template_id' => $template->id,
            'cron_expression' => '*/6 * * * *',
            'timezone' => 'Asia/Jakarta',
            'lock_key' => $code.'.repost',
            'lock_mode' => 'skip',
            'is_enabled' => true,
        ], $attributes));
    }

    public function test_renders_managed_block_with_one_line_per_enabled_schedule(): void
    {
        $schedule = $this->makeSchedule();
        $output = app(CrontabRenderer::class)->render();

        $this->assertStringContainsString(CrontabRenderer::BEGIN_MARKER, $output);
        $this->assertStringContainsString(CrontabRenderer::END_MARKER, $output);
        $this->assertStringContainsString('CRON_TZ=Asia/Jakarta', $output);
        $this->assertStringContainsString("cron:run {$schedule->id}", $output);
        $this->assertStringContainsString('*/6 * * * * ubuntu', $output);
    }

    public function test_every_line_is_wrapped_in_flock(): void
    {
        $this->makeSchedule();
        $output = app(CrontabRenderer::class)->render();

        foreach (preg_split('/\r?\n/', $output) as $line) {
            if (preg_match('/cron:run \d+/', $line)) {
                $this->assertStringContainsString('flock -n', $line, "Baris tanpa flock: {$line}");
            }
        }
    }

    public function test_disabled_schedules_and_clients_are_excluded(): void
    {
        $this->makeSchedule(['is_enabled' => false]);
        $disabledClient = $this->makeSchedule([], 'anta');
        $disabledClient->client->update(['is_active' => false]);

        $output = app(CrontabRenderer::class)->render();

        $this->assertStringContainsString('(tidak ada schedule aktif)', $output);
    }

    public function test_validation_rejects_invalid_cron_expression(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->updateQuietly(['cron_expression' => 'setiap enam menit']);

        $problems = app(CrontabRenderer::class)->validate();

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('tidak valid', $problems[0]['problem']);
    }

    public function test_validation_rejects_unsafe_lock_key(): void
    {
        $this->makeSchedule(['lock_key' => 'gn/../etc/passwd']);

        $problems = app(CrontabRenderer::class)->validate();

        $this->assertStringContainsString('tidak aman', $problems[0]['problem']);
    }

    public function test_validation_rejects_mixed_timezones(): void
    {
        $this->makeSchedule();
        $this->makeSchedule(['timezone' => 'Asia/Makassar', 'lock_key' => 'anta.repost'], 'anta');

        $problems = app(CrontabRenderer::class)->validate();

        $this->assertStringContainsString('CRON_TZ', $problems[0]['problem']);
    }

    public function test_apply_writes_file_and_creates_backup_on_second_deploy(): void
    {
        $this->makeSchedule();
        $deployer = app(CrontabDeployer::class);

        $first = $deployer->apply();
        $this->assertFileExists($first['path']);
        $this->assertNull($first['backup']);

        $second = $deployer->apply();
        $this->assertNotNull($second['backup']);
        $this->assertFileExists($second['backup']);
    }

    public function test_apply_preserves_manual_lines_outside_the_managed_block(): void
    {
        File::put($this->target, "# baris manual yang harus bertahan\n0 4 * * * ubuntu /usr/bin/backup.sh\n");
        $this->makeSchedule();

        app(CrontabDeployer::class)->apply();
        $contents = File::get($this->target);

        $this->assertStringContainsString('/usr/bin/backup.sh', $contents);
        $this->assertStringContainsString(CrontabRenderer::BEGIN_MARKER, $contents);
    }

    public function test_second_apply_replaces_the_block_instead_of_appending(): void
    {
        $this->makeSchedule();
        $deployer = app(CrontabDeployer::class);

        $deployer->apply();
        $deployer->apply();

        $contents = File::get($this->target);
        $this->assertSame(1, substr_count($contents, CrontabRenderer::BEGIN_MARKER));
        $this->assertSame(1, substr_count($contents, CrontabRenderer::END_MARKER));
    }

    public function test_diff_reports_added_lines_before_first_deploy(): void
    {
        $schedule = $this->makeSchedule();
        $diff = app(CrontabDeployer::class)->diff();

        $added = array_filter($diff, fn ($d) => $d['type'] === 'added');
        $this->assertNotEmpty($added);
        $this->assertTrue(
            (bool) array_filter($added, fn ($d) => str_contains($d['line'], "cron:run {$schedule->id}")),
        );
    }

    public function test_rollback_restores_the_previous_file(): void
    {
        $this->makeSchedule();
        $deployer = app(CrontabDeployer::class);

        $deployer->apply();
        $original = File::get($this->target);

        Schedule::query()->update(['is_enabled' => false]);
        $deployer->apply();
        $this->assertStringContainsString('(tidak ada schedule aktif)', File::get($this->target));

        $deployer->rollback();
        $this->assertSame($original, File::get($this->target));
    }

    public function test_deploy_and_rollback_are_recorded_in_the_audit_log(): void
    {
        $this->makeSchedule();
        $deployer = app(CrontabDeployer::class);

        $deployer->apply();
        $deployer->apply();
        $deployer->rollback();

        $this->assertDatabaseHas('audit_logs', ['action' => 'crontab_deployed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'crontab_rollback']);
    }
}
