<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Run;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role = UserRole::Admin): User
    {
        return User::create([
            'name' => 'Tester',
            'email' => $role->value.'@opsifin.test',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function seedDomain(): Schedule
    {
        $client = Client::create([
            'code' => 'gn',
            'name' => 'Golden Nusa',
            'base_url' => 'https://gn.opsifin.test',
            'auth_type' => 'basic',
            'auth_username' => 'rest_gn',
            'auth_secret' => 'rahasia',
        ]);

        $template = TaskTemplate::create([
            'key' => 'repost',
            'name' => 'Repost',
            'http_method' => 'POST',
            'path_template' => '/apiv_g/api_repost',
            'body_template' => '{}',
        ]);

        $schedule = Schedule::create([
            'client_id' => $client->id,
            'task_template_id' => $template->id,
            'cron_expression' => '*/6 * * * *',
            'timezone' => 'Asia/Jakarta',
            'lock_key' => 'gn.repost',
            'lock_mode' => 'skip',
            'is_enabled' => true,
        ]);

        Run::create([
            'schedule_id' => $schedule->id,
            'client_id' => $client->id,
            'task_template_id' => $template->id,
            'trigger' => 'cron',
            'status' => 'success',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'duration_ms' => 431,
            'http_status' => 200,
            'request_method' => 'POST',
            'request_url' => 'https://gn.opsifin.test/apiv_g/api_repost',
            'response_excerpt' => '{"ok":true}',
        ]);

        return $schedule;
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function pageProvider(): array
    {
        return [
            'clients' => ['/admin/clients'],
            'client create' => ['/admin/clients/create'],
            'task templates' => ['/admin/task-templates'],
            'task template create' => ['/admin/task-templates/create'],
            'schedules' => ['/admin/schedules'],
            'schedule create' => ['/admin/schedules/create'],
            'runs' => ['/admin/runs'],
            'matrix' => ['/admin/schedule-matrix'],
            'deploy' => ['/admin/deploy-crontab'],
        ];
    }

    #[DataProvider('pageProvider')]
    public function test_admin_can_open_page(string $url): void
    {
        $this->seedDomain();

        $this->actingAs($this->user())->get($url)->assertSuccessful();
    }

    public function test_admin_can_open_edit_pages(): void
    {
        $schedule = $this->seedDomain();
        $admin = $this->user();

        $this->actingAs($admin)->get("/admin/clients/{$schedule->client_id}/edit")->assertSuccessful();
        $this->actingAs($admin)->get("/admin/task-templates/{$schedule->task_template_id}/edit")->assertSuccessful();
        $this->actingAs($admin)->get("/admin/schedules/{$schedule->id}/edit")->assertSuccessful();
        $this->actingAs($admin)->get('/admin/runs/'.Run::first()->id)->assertSuccessful();
    }

    public function test_viewer_can_read_but_not_create(): void
    {
        $this->seedDomain();
        $viewer = $this->user(UserRole::Viewer);

        $this->actingAs($viewer)->get('/admin/clients')->assertSuccessful();
        $this->actingAs($viewer)->get('/admin/clients/create')->assertForbidden();
        $this->actingAs($viewer)->get('/admin/schedules/create')->assertForbidden();
    }

    public function test_operator_cannot_create_master_data(): void
    {
        $this->seedDomain();
        $operator = $this->user(UserRole::Operator);

        $this->actingAs($operator)->get('/admin/schedules')->assertSuccessful();
        $this->actingAs($operator)->get('/admin/clients/create')->assertForbidden();
    }

    public function test_deactivated_user_cannot_access_the_panel(): void
    {
        $user = $this->user();
        $user->update(['is_active' => false]);

        $this->actingAs($user)->get('/admin/clients')->assertForbidden();
    }

    public function test_runs_cannot_be_created_or_edited_through_the_panel(): void
    {
        $this->seedDomain();
        $admin = $this->user();

        $this->actingAs($admin)->get('/admin/runs/create')->assertNotFound();
        $this->actingAs($admin)->get('/admin/runs/'.Run::first()->id.'/edit')->assertNotFound();
    }
}
