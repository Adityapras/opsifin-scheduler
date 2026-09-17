<?php

namespace Tests\Feature;

use App\Enums\RunStatus;
use App\Models\Run;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\Support\StartsDirectHttpFixture;
use Tests\TestCase;

/** Real process signals against a disposable SQLite database and loopback endpoint. */
class DirectExecutorProcessTest extends TestCase
{
    use CreatesSchedulerFixtures, StartsDirectHttpFixture;

    private string $databaseFile;

    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->databaseFile = tempnam(sys_get_temp_dir(), 'opsifin-direct-drill-');
        config(['database.default' => 'drill', 'database.connections.drill' => [
            'driver' => 'sqlite', 'database' => $this->databaseFile, 'foreign_key_constraints' => true, 'busy_timeout' => 5000,
        ]]);
        Artisan::call('migrate', ['--database' => 'drill', '--force' => true]);
        $this->startHttpFixture();
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            if (is_resource($child['process'])) {
                proc_terminate($child['process'], 9);
                foreach ($child['pipes'] as $pipe) {
                    fclose($pipe);
                }
                proc_close($child['process']);
            }
        }
        $this->stopHttpFixture();
        DB::disconnect('drill');
        if (isset($this->databaseFile) && is_file($this->databaseFile)) {
            unlink($this->databaseFile);
        }
        parent::tearDown();
    }

    private function pending(string $name, float $delay = .5): Run
    {
        $schedule = $this->schedule([], ['timeout_sec' => 2, 'connect_timeout_sec' => 1,
            'config' => ['method' => 'POST', 'base_url' => $this->fixtureUrl, 'path' => '/'.$name.'?delay='.$delay]]);

        return $this->occurrence($schedule, ['status' => 'pending', 'execution_driver' => 'direct',
            'scheduled_for' => now(), 'prepared_at' => now()]);
    }

    private function startExecutor(): int
    {
        $env = array_merge(getenv(), [
            // Laravel disables signal traps in APP_ENV=testing. Use an isolated
            // non-testing process with explicit disposable database/cache settings.
            'APP_ENV' => 'direct-process-test', 'APP_CONFIG_CACHE' => $this->databaseFile.'-no-config',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->databaseFile, 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'TELESCOPE_ENABLED' => 'false',
            'CRON_EXECUTION_DRIVER' => 'direct', 'CRON_DIRECT_CONCURRENCY' => '1',
        ]);
        $process = proc_open([PHP_BINARY, 'artisan', 'jobs:work-direct', '--once'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        $this->children[] = compact('process', 'pipes');

        return array_key_last($this->children);
    }

    private function awaitCondition(callable $condition): void
    {
        $deadline = microtime(true) + 8;
        do {
            if ($condition()) {
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('Process drill condition was not reached within 8 seconds.');
    }

    public function test_sigterm_drains_current_request_and_leaves_pending_work_for_restart(): void
    {
        $first = $this->pending('first', .8);
        $second = $this->pending('second');
        $index = $this->startExecutor();
        $this->awaitCondition(fn () => count($this->httpMetrics()['requests']) === 1);
        proc_terminate($this->children[$index]['process'], SIGTERM);
        $this->awaitCondition(fn () => ! proc_get_status($this->children[$index]['process'])['running']);
        $this->assertSame(RunStatus::Succeeded, $first->fresh()->status);
        $this->assertSame(RunStatus::Pending, $second->fresh()->status);
        $this->assertCount(1, $this->httpMetrics()['requests']);
        $this->startExecutor();
        $this->awaitCondition(fn () => $second->fresh()->isTerminal());
        $this->assertSame(RunStatus::Succeeded, $second->fresh()->status);
        $this->assertCount(2, $this->httpMetrics()['requests']);
    }

    public function test_second_executor_stands_by_while_first_owns_the_pool(): void
    {
        $first = $this->pending('first', .8);
        $second = $this->pending('second');
        $owner = $this->startExecutor();
        $this->awaitCondition(fn () => count($this->httpMetrics()['requests']) === 1);
        $standby = $this->startExecutor();
        $this->awaitCondition(fn () => ! proc_get_status($this->children[$standby]['process'])['running']);
        $this->assertStringContainsString('"busy":true', stream_get_contents($this->children[$standby]['pipes'][1]));
        $this->awaitCondition(fn () => ! proc_get_status($this->children[$owner]['process'])['running']);

        $this->assertSame(RunStatus::Succeeded, $first->fresh()->status);
        $this->assertSame(RunStatus::Succeeded, $second->fresh()->status);
        $this->assertCount(2, $this->httpMetrics()['requests']);
        $this->assertSame(1, $this->httpMetrics()['max_active']);
    }

    public function test_sigkill_recovers_ambiguous_run_without_resending_it(): void
    {
        $first = $this->pending('ambiguous', .5);
        $second = $this->pending('next');
        $index = $this->startExecutor();
        $this->awaitCondition(fn () => count($this->httpMetrics()['requests']) === 1);
        proc_terminate($this->children[$index]['process'], SIGKILL);
        $this->awaitCondition(fn () => ! proc_get_status($this->children[$index]['process'])['running']);
        $this->assertSame(RunStatus::Running, $first->fresh()->status);
        $this->awaitCondition(fn () => $this->httpMetrics()['active'] === 0);
        // Advance only the recovery deadline in the isolated test database.
        $first->forceFill(['execution_deadline_at' => now()->subSecond()])->saveQuietly();
        DB::table('executor_states')->where('name', 'direct')->update(['expires_at' => now()->subSecond()]);
        $this->startExecutor();
        $this->awaitCondition(fn () => $second->fresh()->isTerminal());
        $this->assertSame(RunStatus::Failed, $first->fresh()->status);
        $this->assertStringContainsString('unknown', $first->fresh()->error_message);
        $this->assertSame(RunStatus::Succeeded, $second->fresh()->status);
        $requests = $this->httpMetrics()['requests'];
        $this->assertCount(2, $requests);
        $this->assertSame(1, count(array_filter($requests, fn ($entry) => str_starts_with($entry['path'], '/ambiguous'))));
    }
}
