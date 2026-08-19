<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\TaskTemplate;
use App\Services\LegacyImport\LegacyImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\TestCase;

class LegacyImporterV2Test extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    public function test_import_maps_http_config_and_never_enables_a_legacy_schedule(): void
    {
        $source = sys_get_temp_dir().'/opsifin-import-'.uniqid();
        File::ensureDirectoryExists($source.'/acme');
        File::ensureDirectoryExists($source.'/jobs');
        File::put($source.'/jobs/process.sh', <<<'SH'
#!/usr/bin/env bash
curl -X POST -H 'Content-Type: application/json' -d '{"mode":"daily"}' https://template.example.test/api/process
SH);
        File::put($source.'/acme/process.sh', <<<'SH'
#!/usr/bin/env bash
curl -X POST -u api-user:super-secret -H 'Content-Type: application/json' -d '{"mode":"daily"}' https://acme.example.test/api/process
SH);
        File::put($source.'/opsifin_crontab', '*/5 * * * * /bin/bash /home/ubuntu/cron/acme/process.sh'.PHP_EOL);

        try {
            app(LegacyImporter::class)->import($source);

            $task = TaskTemplate::query()->where('key', 'process')->firstOrFail();
            $schedule = Schedule::query()->firstOrFail();

            $this->assertSame('POST', $task->config['method']);
            $this->assertSame('/api/process', $task->config['path']);
            $this->assertFalse($schedule->is_enabled);
            $this->assertNull($schedule->next_run_at);
            $this->assertFalse($schedule->legacy_was_commented);
        } finally {
            File::deleteDirectory($source);
        }
    }

    public function test_import_accepts_crontab_txt_as_the_schedule_source(): void
    {
        $source = sys_get_temp_dir().'/opsifin-import-'.uniqid();
        File::ensureDirectoryExists($source.'/acme');
        File::ensureDirectoryExists($source.'/jobs');
        File::put($source.'/jobs/process.sh', 'curl -X POST https://template.example.test/api/process');
        File::put($source.'/acme/process.sh', <<<'SH'
#!/usr/bin/env bash
curl -X POST https://acme.example.test/api/process
SH);
        File::put($source.'/crontab.txt', '*/5 * * * * /home/ubuntu/cron/acme/process.sh'.PHP_EOL);

        try {
            $import = app(LegacyImporter::class)->import($source);

            $this->assertDatabaseCount('schedules', 1);
            $this->assertFalse(Schedule::query()->firstOrFail()->is_enabled);
            $this->assertSame(1, $import->stats['crontab_entries']);
        } finally {
            File::deleteDirectory($source);
        }
    }

    public function test_import_command_requires_fresh_when_domain_data_exists(): void
    {
        $source = sys_get_temp_dir().'/opsifin-import-'.uniqid();
        File::ensureDirectoryExists($source.'/acme');
        File::ensureDirectoryExists($source.'/jobs');
        File::put($source.'/jobs/process.sh', 'curl -X POST https://template.example.test/api/process');
        File::put($source.'/acme/process.sh', 'curl -X POST https://acme.example.test/api/process');
        File::put($source.'/opsifin_crontab', '*/5 * * * * /home/ubuntu/cron/acme/process.sh'.PHP_EOL);
        $this->schedule();

        try {
            $this->artisan('cron:import', ['--source' => $source])
                ->expectsOutputToContain('Domain data already exists')
                ->assertFailed();

            $this->assertDatabaseCount('schedules', 1);
        } finally {
            File::deleteDirectory($source);
        }
    }

    public function test_jobs_directory_is_the_only_task_template_source(): void
    {
        $source = sys_get_temp_dir().'/opsifin-import-'.uniqid();
        File::ensureDirectoryExists($source.'/acme');
        File::ensureDirectoryExists($source.'/jobs');
        File::put($source.'/jobs/process.sh', <<<'SH'
curl -X POST -d '{"source":"canonical"}' https://template.example.test/api/process
SH);
        File::put($source.'/acme/process.sh', <<<'SH'
curl -X GET https://acme.example.test/legacy/process
SH);
        File::put($source.'/acme/client_only.sh', 'curl -X POST https://acme.example.test/api/client-only');
        File::put($source.'/crontab.txt', '*/5 * * * * /home/ubuntu/cron/acme/process.sh'.PHP_EOL);

        try {
            $import = app(LegacyImporter::class)->import($source);
            $task = TaskTemplate::query()->sole();
            $schedule = Schedule::query()->sole();

            $this->assertSame('process', $task->key);
            $this->assertSame('POST', $task->config['method']);
            $this->assertSame('/api/process', $task->config['path']);
            $this->assertSame('{"source":"canonical"}', $task->config['body']);
            $this->assertSame($task->id, $schedule->task_template_id);
            $this->assertFalse($schedule->needs_review);
            $this->assertSame(1, $import->stats['canonical_job_templates']);
            $this->assertTrue($import->findings->contains('category', 'client_script_differs_from_canonical_job'));
            $this->assertTrue($import->findings->contains('category', 'script_not_in_jobs_catalog'));
        } finally {
            File::deleteDirectory($source);
        }
    }

    public function test_same_client_and_job_can_keep_multiple_cron_timings_without_cloning_template(): void
    {
        $source = sys_get_temp_dir().'/opsifin-import-'.uniqid();
        File::ensureDirectoryExists($source.'/acme');
        File::ensureDirectoryExists($source.'/jobs');
        File::put($source.'/jobs/process.sh', 'curl -X POST https://template.example.test/api/process');
        File::put($source.'/acme/process.sh', 'curl -X POST https://acme.example.test/api/process');
        File::put($source.'/crontab.txt', implode(PHP_EOL, [
            '*/5 * * * * /home/ubuntu/cron/acme/process.sh',
            '0 3 * * * /home/ubuntu/cron/acme/process.sh',
            '',
        ]));

        try {
            app(LegacyImporter::class)->import($source);

            $this->assertDatabaseCount('task_templates', 1);
            $this->assertDatabaseCount('schedules', 2);
        } finally {
            File::deleteDirectory($source);
        }
    }

    public function test_gateway_entry_maps_directly_to_jobs_filename_without_gateway_file(): void
    {
        $source = sys_get_temp_dir().'/opsifin-import-'.uniqid();
        File::ensureDirectoryExists($source.'/configs');
        File::ensureDirectoryExists($source.'/jobs');
        File::put($source.'/configs/acme.conf', implode(PHP_EOL, [
            'CLIENT_NAME="Acme"',
            'API_URL="https://acme.example.test"',
            '',
        ]));
        File::put($source.'/jobs/process.sh', 'curl -X POST https://template.example.test/api/process');
        File::put($source.'/crontab.txt', '*/5 * * * * /home/ubuntu/cron/gateway.sh acme process'.PHP_EOL);

        try {
            app(LegacyImporter::class)->import($source);

            $this->assertDatabaseCount('task_templates', 1);
            $this->assertDatabaseCount('schedules', 1);
            $this->assertSame('process', Schedule::query()->firstOrFail()->taskTemplate->key);
        } finally {
            File::deleteDirectory($source);
        }
    }
}
