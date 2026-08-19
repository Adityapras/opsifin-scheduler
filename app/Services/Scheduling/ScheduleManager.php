<?php

namespace App\Services\Scheduling;

use App\Models\Client;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use Cron\CronExpression;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ScheduleManager
{
    public function __construct(private readonly NextRunCalculator $calculator) {}

    public function setEnabled(Schedule $schedule, bool $enabled): Schedule
    {
        $schedule->forceFill([
            'is_enabled' => $enabled,
            'next_run_at' => $enabled ? $this->calculator->next($schedule) : null,
        ])->save();

        return $schedule->refresh();
    }

    public function changeTiming(Schedule $schedule, string $cron, string $timezone): Schedule
    {
        $this->assertValidCron($cron);

        $schedule->forceFill([
            'cron_expression' => $cron,
            'timezone' => $timezone,
            'next_run_at' => null,
        ])->save();

        return $schedule->refresh();
    }

    /**
     * @param  array<int, int|string>  $clientIds
     */
    public function assign(
        TaskTemplate $task,
        array $clientIds,
        string $cron,
        string $timezone,
        bool $enabled = false,
    ): int {
        $this->assertValidCron($cron);
        $ids = Client::query()->whereKey($clientIds)->pluck('id');

        return DB::transaction(function () use ($task, $ids, $cron, $timezone, $enabled): int {
            $assigned = 0;

            foreach ($ids as $clientId) {
                $schedule = Schedule::query()->firstOrNew([
                    'client_id' => $clientId,
                    'task_template_id' => $task->id,
                ]);

                if ($schedule->exists) {
                    continue;
                }

                $schedule->fill([
                    'cron_expression' => $cron,
                    'timezone' => $timezone,
                    'is_enabled' => $enabled,
                    'queue' => 'default',
                ])->save();

                $assigned++;
            }

            return $assigned;
        });
    }

    /**
     * @param  array<int, int|string>  $clientIds
     */
    public function removeAssignments(TaskTemplate $task, array $clientIds): int
    {
        return Schedule::query()
            ->where('task_template_id', $task->id)
            ->whereIn('client_id', $clientIds)
            ->whereNull('running_run_id')
            ->delete();
    }

    /** @param Collection<int, Schedule> $schedules */
    public function setEnabledBulk(Collection $schedules, bool $enabled): int
    {
        $updated = 0;

        foreach ($schedules as $schedule) {
            $this->setEnabled($schedule, $enabled);
            $updated++;
        }

        return $updated;
    }

    /** @param Collection<int, Schedule> $schedules */
    public function changeTimingBulk(Collection $schedules, string $cron, ?string $timezone = null): int
    {
        $this->assertValidCron($cron);
        $updated = 0;

        foreach ($schedules as $schedule) {
            $this->changeTiming($schedule, $cron, $timezone ?: $schedule->timezone);
            $updated++;
        }

        return $updated;
    }

    private function assertValidCron(string $cron): void
    {
        if (! CronExpression::isValidExpression($cron)) {
            throw new InvalidArgumentException('Invalid cron expression: '.$cron);
        }
    }
}
