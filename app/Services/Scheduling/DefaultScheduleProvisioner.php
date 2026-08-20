<?php

namespace App\Services\Scheduling;

use App\Models\Client;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DefaultScheduleProvisioner
{
    public function provision(Client $client): int
    {
        return DB::transaction(function () use ($client): int {
            $lockedClient = Client::query()->lockForUpdate()->findOrFail($client->getKey());
            $assignedTaskIds = Schedule::query()
                ->where('client_id', $lockedClient->getKey())
                ->pluck('task_template_id');

            $tasks = TaskTemplate::query()
                ->where('is_active', true)
                ->where('auto_assign_to_new_clients', true)
                ->whereNotIn('id', $assignedTaskIds)
                ->orderBy('id')
                ->get();

            foreach ($tasks as $task) {
                $cron = (string) $task->default_cron_expression;

                if (! CronExpression::isValidExpression($cron)) {
                    throw new InvalidArgumentException("Invalid default cron expression for task [{$task->key}].");
                }

                Schedule::query()->create([
                    'client_id' => $lockedClient->getKey(),
                    'task_template_id' => $task->getKey(),
                    'cron_expression' => $cron,
                    'timezone' => $lockedClient->timezone ?: config('opsifin_cron.default_timezone'),
                    'is_enabled' => (bool) $task->default_schedule_enabled,
                    'prevent_overlap' => (bool) $task->default_prevent_overlap,
                    'queue' => config('opsifin_cron.defaults.queue', 'default'),
                ]);
            }

            return $tasks->count();
        });
    }
}
