<?php

namespace App\Services\Scheduling;

use App\Models\Client;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use Cron\CronExpression;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DefaultScheduleProvisioner
{
    /** Provisioning Client baru: seluruh template aktif auto-assign yang belum dimiliki. */
    public function provision(Client $client): int
    {
        return $this->create($client, $this->autoAssignTasks(), null, null, false);
    }

    /**
     * Bulk "Create missing schedules" untuk Client yang sudah ada: sama dengan
     * provision(), tetapi selalu paused karena operator tidak meninjau tiap job.
     */
    public function provisionMissing(Client $client): int
    {
        return $this->create($client, $this->autoAssignTasks(), null, null, true);
    }

    /**
     * Assign job pilihan ke Client yang sudah ada. Schedule selalu dibuat paused:
     * aktivasi adalah keputusan terpisah setelah credential dan request dicek.
     *
     * @param  array<int, int|string>  $taskIds
     * @return array{created: int, skipped: int}
     */
    public function assign(Client $client, array $taskIds, ?string $cron = null, ?string $timezone = null): array
    {
        if ($cron !== null) {
            $this->assertValidCron($cron, 'custom');
        }

        $tasks = TaskTemplate::query()->where('is_active', true)->whereKey($taskIds)->orderBy('id')->get();
        $created = $this->create($client, $tasks, $cron, $timezone, true);

        return ['created' => $created, 'skipped' => count(array_unique($taskIds)) - $created];
    }

    /** @return Collection<int, TaskTemplate> Template aktif yang belum dimiliki Client. */
    public function unassignedTasks(Client $client): Collection
    {
        return TaskTemplate::query()
            ->where('is_active', true)
            ->whereNotIn('id', Schedule::query()->where('client_id', $client->getKey())->select('task_template_id'))
            ->orderBy('key')
            ->get();
    }

    /** @return Collection<int, TaskTemplate> */
    private function autoAssignTasks(): Collection
    {
        return TaskTemplate::query()
            ->where('is_active', true)
            ->where('auto_assign_to_new_clients', true)
            ->orderBy('id')
            ->get();
    }

    /** @param Collection<int, TaskTemplate> $tasks */
    private function create(Client $client, Collection $tasks, ?string $cron, ?string $timezone, bool $forcePaused): int
    {
        return DB::transaction(function () use ($client, $tasks, $cron, $timezone, $forcePaused): int {
            $lockedClient = Client::query()->lockForUpdate()->findOrFail($client->getKey());
            // Dicek ulang di dalam lock: job yang sudah dimiliki dilewati, bukan digandakan.
            $assignedTaskIds = Schedule::query()
                ->where('client_id', $lockedClient->getKey())
                ->pluck('task_template_id')
                ->all();
            $created = 0;

            foreach ($tasks as $task) {
                if (in_array($task->getKey(), $assignedTaskIds, false)) {
                    continue;
                }

                $taskCron = $cron ?? (string) $task->default_cron_expression;
                $this->assertValidCron($taskCron, $task->key);

                Schedule::query()->create([
                    'client_id' => $lockedClient->getKey(),
                    'task_template_id' => $task->getKey(),
                    'cron_expression' => $taskCron,
                    'timezone' => $timezone ?: ($lockedClient->timezone ?: config('opsifin_cron.default_timezone')),
                    'is_enabled' => ! $forcePaused && (bool) $task->default_schedule_enabled,
                    'prevent_overlap' => (bool) $task->default_prevent_overlap,
                    'queue' => config('opsifin_cron.defaults.queue', 'default'),
                ]);
                $created++;
            }

            return $created;
        });
    }

    private function assertValidCron(string $cron, string $source): void
    {
        if (! CronExpression::isValidExpression($cron)) {
            throw new InvalidArgumentException("Invalid cron expression for [{$source}].");
        }
    }
}
