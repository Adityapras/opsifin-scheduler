<?php

namespace Tests\Feature;

use App\Enums\LockMode;
use App\Models\Client;
use App\Models\ClientTaskOverride;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleResolveRequestTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(array $overrideAttributes = []): Schedule
    {
        $client = Client::create([
            'code' => 'gn',
            'name' => 'Golden Nusa',
            'base_url' => 'https://goldennusa.opsifin.com',
            'auth_type' => 'basic',
            'auth_username' => 'rest_goldennusa',
            'auth_secret' => 'rahasia',
            'auth_secret_key' => 'kunci-rahasia',
        ]);

        $template = TaskTemplate::create([
            'key' => 'repost',
            'name' => 'Repost',
            'http_method' => 'POST',
            'path_template' => '/apiv_g/api_repost',
            'body_template' => '{}',
            'headers' => ['SecretKey' => '{{client.secret_key}}'],
            'default_timeout_sec' => 60,
            'default_connect_timeout_sec' => 10,
        ]);

        if ($overrideAttributes !== []) {
            ClientTaskOverride::create([
                'client_id' => $client->id,
                'task_template_id' => $template->id,
            ] + $overrideAttributes);
        }

        return Schedule::create([
            'client_id' => $client->id,
            'task_template_id' => $template->id,
            'cron_expression' => '*/6 * * * *',
            'timezone' => 'Asia/Jakarta',
            'lock_key' => 'gn.repost',
            'lock_mode' => 'skip',
        ]);
    }

    public function test_resolves_request_from_template_defaults(): void
    {
        $request = $this->makeSchedule()->resolveRequest();

        $this->assertSame('POST', $request['method']->value);
        $this->assertSame('https://goldennusa.opsifin.com/apiv_g/api_repost', $request['url']);
        $this->assertSame('{}', $request['body']);
        $this->assertSame(60, $request['timeout']);
        $this->assertSame(10, $request['connect_timeout']);
    }

    public function test_client_override_wins_over_template(): void
    {
        $request = $this->makeSchedule([
            'path_override' => '/apiv1/api_all',
            'body_override' => '{"person":{"name":"bob"}}',
            'timeout_override' => 120,
        ])->resolveRequest();

        $this->assertSame('https://goldennusa.opsifin.com/apiv1/api_all', $request['url']);
        $this->assertSame('{"person":{"name":"bob"}}', $request['body']);
        $this->assertSame(120, $request['timeout']);
    }

    public function test_base_url_override_replaces_client_host(): void
    {
        $request = $this->makeSchedule([
            'base_url_override' => 'https://gns.opsifin.com',
        ])->resolveRequest();

        $this->assertSame('https://gns.opsifin.com/apiv_g/api_repost', $request['url']);
    }

    public function test_builds_basic_authorization_header_from_encrypted_credentials(): void
    {
        $request = $this->makeSchedule()->resolveRequest();

        $this->assertSame(
            'Basic '.base64_encode('rest_goldennusa:rahasia'),
            $request['headers']['Authorization'],
        );
    }

    public function test_substitutes_per_client_secret_key_placeholder(): void
    {
        $request = $this->makeSchedule()->resolveRequest();

        $this->assertSame('kunci-rahasia', $request['headers']['SecretKey']);
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $schedule = $this->makeSchedule();
        $raw = \DB::table('clients')->where('id', $schedule->client_id)->first();

        $this->assertNotSame('rahasia', $raw->auth_secret);
        $this->assertSame('rahasia', $schedule->client->auth_secret);
    }

    public function test_generates_flock_arguments_and_lock_path(): void
    {
        $schedule = $this->makeSchedule();

        $this->assertSame('-n', $schedule->flockArguments());
        $this->assertStringEndsWith('/gn.repost.lock', $schedule->lockFilePath());

        $schedule->lock_mode = LockMode::Wait;
        $schedule->lock_wait_sec = 30;

        $this->assertSame('-w 30', $schedule->flockArguments());
    }

    public function test_computes_next_runs_in_client_timezone(): void
    {
        $runs = $this->makeSchedule()->nextRuns(3);

        $this->assertCount(3, $runs);
        $this->assertSame('Asia/Jakarta', $runs[0]->timezone->getName());
        $this->assertSame(0, $runs[0]->minute % 6);
    }

    /**
     * Cast `datetime` menulis jam dinding Carbon apa adanya. Kalau next_run_at
     * disimpan masih dalam timezone schedule, nilainya terbaca kembali sebagai
     * jam UTC dan tampil bergeser sebesar offset timezone.
     */
    public function test_next_run_survives_a_round_trip_through_the_database(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->cron_expression = '0 21 * * *';
        $schedule->recalculateNextRun();
        $schedule->save();

        $stored = $schedule->fresh();

        $this->assertSame(
            '21:00',
            $stored->next_run_at->setTimezone($stored->timezone)->format('H:i'),
        );
    }
}
