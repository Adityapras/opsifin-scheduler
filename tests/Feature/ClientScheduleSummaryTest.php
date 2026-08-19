<?php

namespace Tests\Feature;

use App\Filament\Resources\ClientSummaries\Pages\ListClientSummaries;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use App\Services\Scheduling\ClientScheduleSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesSchedulerFixtures;
use Tests\TestCase;

class ClientScheduleSummaryTest extends TestCase
{
    use CreatesSchedulerFixtures, RefreshDatabase;

    public function test_summary_reports_missing_jobs_and_counts_duplicate_timings_once(): void
    {
        $schedule = $this->schedule();
        $client = $schedule->client;
        $secondTask = TaskTemplate::create([
            'key' => 'second-job',
            'name' => 'Second job',
            'executor' => 'http',
            'config' => ['method' => 'GET', 'path' => '/second'],
            'timeout_sec' => 60,
            'connect_timeout_sec' => 10,
            'is_active' => true,
        ]);
        Schedule::create([
            'client_id' => $client->id,
            'task_template_id' => $schedule->task_template_id,
            'cron_expression' => '15 * * * *',
            'timezone' => 'Asia/Jakarta',
            'is_enabled' => false,
            'queue' => 'default',
        ]);

        $client->load('schedules.taskTemplate');
        $summary = app(ClientScheduleSummary::class);

        $this->assertSame(1, $summary->assignedActiveTaskCount($client));
        $this->assertSame(['second-job'], $summary->missingJobKeys($client)->all());
        $this->assertFalse($summary->isComplete($client));
        $this->assertSame(1, ClientScheduleSummary::incompleteClientCount());

        Schedule::create([
            'client_id' => $client->id,
            'task_template_id' => $secondTask->id,
            'cron_expression' => '30 * * * *',
            'timezone' => 'Asia/Jakarta',
            'is_enabled' => false,
            'queue' => 'default',
        ]);

        $client->unsetRelation('schedules')->load('schedules.taskTemplate');
        $this->assertTrue(app(ClientScheduleSummary::class)->isComplete($client));
        $this->assertSame(0, ClientScheduleSummary::incompleteClientCount());
    }

    public function test_expandable_job_list_keeps_items_beyond_the_visible_limit_in_the_page(): void
    {
        $schedule = $this->schedule();

        foreach (range(2, 5) as $number) {
            $task = TaskTemplate::create([
                'key' => 'summary-job-'.$number,
                'name' => 'Summary job '.$number,
                'executor' => 'http',
                'config' => ['method' => 'GET', 'path' => '/summary-'.$number],
                'timeout_sec' => 60,
                'connect_timeout_sec' => 10,
                'is_active' => true,
            ]);

            Schedule::create([
                'client_id' => $schedule->client_id,
                'task_template_id' => $task->id,
                'cron_expression' => $number.' * * * *',
                'timezone' => 'Asia/Jakarta',
                'is_enabled' => true,
                'queue' => 'default',
            ]);
        }

        Livewire::actingAs($this->user())
            ->test(ListClientSummaries::class)
            ->assertSee('summary-job-5')
            ->assertSee('isLimited = false', false);
    }
}
