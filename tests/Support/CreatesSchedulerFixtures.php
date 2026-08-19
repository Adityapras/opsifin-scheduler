<?php

namespace Tests\Support;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Run;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use App\Models\User;

trait CreatesSchedulerFixtures
{
    protected function user(UserRole $role = UserRole::Admin): User
    {
        return User::create([
            'name' => $role->label(),
            'email' => $role->value.'-'.uniqid().'@scheduler.test',
            'password' => 'test-password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $scheduleAttributes @param array<string, mixed> $taskAttributes */
    protected function schedule(array $scheduleAttributes = [], array $taskAttributes = []): Schedule
    {
        $client = Client::create([
            'code' => 'client-'.uniqid(),
            'name' => 'Test Client',
            'base_url' => 'https://client.example.test',
            'timezone' => 'Asia/Jakarta',
            'auth_type' => 'basic',
            'auth_username' => 'api-user',
            'auth_secret' => 'super-secret',
            'auth_secret_key' => 'secret-key',
            'is_active' => true,
        ]);

        $task = TaskTemplate::create(array_merge([
            'key' => 'task_'.uniqid(),
            'name' => 'Test Task',
            'executor' => 'http',
            'config' => [
                'method' => 'POST',
                'path' => '/api/process/{{client.code}}',
                'body' => '{"client":"{{client.code}}"}',
                'headers' => ['SecretKey' => '{{client.secret_key}}'],
            ],
            'timeout_sec' => 60,
            'connect_timeout_sec' => 10,
            'is_active' => true,
        ], $taskAttributes));

        return Schedule::create(array_merge([
            'client_id' => $client->id,
            'task_template_id' => $task->id,
            'cron_expression' => '*/5 * * * *',
            'timezone' => 'Asia/Jakarta',
            'is_enabled' => true,
            'queue' => 'default',
        ], $scheduleAttributes));
    }

    /** @param array<string, mixed> $attributes */
    protected function occurrence(Schedule $schedule, array $attributes = []): Run
    {
        return Run::create(array_merge([
            'schedule_id' => $schedule->id,
            'client_id' => $schedule->client_id,
            'task_template_id' => $schedule->task_template_id,
            'scheduled_for' => now()->utc()->startOfMinute(),
            'trigger' => 'schedule',
            'status' => 'queued',
            'queued_at' => now(),
        ], $attributes));
    }
}
