<?php

namespace App\Services\Scheduling;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Jobs\ExecuteRun;
use App\Models\Run;
use App\Models\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;

class RunDispatcher
{
    public function materialize(
        Schedule $schedule,
        Carbon $scheduledFor,
        RunTrigger $trigger = RunTrigger::Schedule,
        RunStatus $status = RunStatus::Queued,
        ?Run $source = null,
        ?string $skipReason = null,
    ): Run {
        $key = $trigger === RunTrigger::Schedule
            ? Schedule::materializationKey($schedule->id, $scheduledFor)
            : null;

        $run = $key === null
            ? new Run
            : Run::query()->firstOrNew(['materialization_key' => $key]);

        if ($run->exists) {
            return $run;
        }

        $run->fill([
            'schedule_id' => $schedule->id,
            'client_id' => $schedule->client_id,
            'task_template_id' => $schedule->task_template_id,
            'source_run_id' => $source?->id,
            'materialization_key' => $key,
            'scheduled_for' => $scheduledFor->copy()->setTimezone(config('app.timezone')),
            'trigger' => $trigger,
            'status' => $status,
            'queued_at' => $status === RunStatus::Queued ? now() : null,
            'finished_at' => $status->isTerminal() ? now() : null,
            'error_message' => $status === RunStatus::Skipped ? $skipReason : null,
        ])->save();

        if ($status === RunStatus::Queued) {
            $queueJobId = Queue::connection('database')->pushOn(
                $schedule->queue,
                new ExecuteRun($run->id),
            );

            $run->forceFill(['queue_job_id' => $queueJobId])->saveQuietly();
        }

        return $run;
    }

    public function manual(Schedule $schedule): Run
    {
        return DB::transaction(fn () => $this->materialize($schedule, now(), RunTrigger::Manual));
    }

    public function retry(Run $source): Run
    {
        if ($source->status !== RunStatus::Failed) {
            throw new InvalidArgumentException('Only failed runs can be retried.');
        }

        $schedule = $source->schedule()->firstOrFail();

        return DB::transaction(fn () => $this->materialize($schedule, now(), RunTrigger::Retry, RunStatus::Queued, $source));
    }
}
