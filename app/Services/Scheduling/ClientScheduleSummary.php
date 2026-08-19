<?php

namespace App\Services\Scheduling;

use App\Models\Client;
use App\Models\TaskTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ClientScheduleSummary
{
    /** @var Collection<int, TaskTemplate>|null */
    private ?Collection $activeTasks = null;

    /** @return Collection<int, TaskTemplate> */
    public function activeTasks(): Collection
    {
        return $this->activeTasks ??= TaskTemplate::query()
            ->where('is_active', true)
            ->orderBy('key')
            ->get(['id', 'key', 'name']);
    }

    /** @return Collection<int, string> */
    public function configuredJobKeys(Client $client): Collection
    {
        $client->loadMissing('schedules.taskTemplate');

        return $client->schedules
            ->pluck('taskTemplate.key')
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    /** @return Collection<int, string> */
    public function missingJobKeys(Client $client): Collection
    {
        $client->loadMissing('schedules.taskTemplate');

        $assignedTaskIds = $client->schedules
            ->pluck('task_template_id')
            ->unique();

        return $this->activeTasks()
            ->reject(fn (TaskTemplate $task): bool => $assignedTaskIds->contains($task->id))
            ->pluck('key')
            ->values();
    }

    public function activeTaskCount(): int
    {
        return $this->activeTasks()->count();
    }

    public function assignedActiveTaskCount(Client $client): int
    {
        $activeTaskIds = $this->activeTasks()->pluck('id');

        return $client->schedules
            ->pluck('task_template_id')
            ->filter(fn (int $id): bool => $activeTaskIds->contains($id))
            ->unique()
            ->count();
    }

    public function isComplete(Client $client): bool
    {
        return $this->missingJobKeys($client)->isEmpty();
    }

    public static function applyIncompleteFilter(Builder $query): Builder
    {
        return $query->whereRaw(
            '(SELECT COUNT(DISTINCT schedules.task_template_id)
                FROM schedules
                INNER JOIN task_templates ON task_templates.id = schedules.task_template_id
                WHERE schedules.client_id = clients.id
                    AND task_templates.is_active = 1)
             < (SELECT COUNT(*) FROM task_templates WHERE task_templates.is_active = 1)'
        );
    }

    public static function incompleteClientCount(): int
    {
        return self::applyIncompleteFilter(Client::query())->count();
    }
}
