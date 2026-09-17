<?php

namespace App\Services\Scheduling;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Run;
use App\Models\Schedule;
use App\Services\Execution\Dto\ExecutionResult;
use App\Services\Execution\Dto\RunExecution;
use App\Services\Execution\ExecutorManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RunExecutionLifecycle
{
    public function __construct(private readonly ExecutorManager $executors) {}

    public function claim(int $runId, RunStatus $from = RunStatus::Queued): ?RunExecution
    {
        $run = DB::transaction(function () use ($runId, $from): ?Run {
            $run = Run::query()->find($runId);
            if ($run === null || $run->status !== $from) {
                return null;
            }
            $schedule = Schedule::query()->with(['client', 'taskTemplate'])->lockForUpdate()->find($run->schedule_id);
            $now = now();
            $claim = Run::query()->whereKey($runId)->where('status', $from->value);
            if ($from === RunStatus::Pending) {
                $claim->where('execution_driver', 'direct')->where('scheduled_for', '<=', $now)
                    ->where('scheduled_for', '>', $now->copy()->subSeconds(config('opsifin_cron.direct.start_window_sec')));
            }
            $timeout = max(1, (int) $schedule?->taskTemplate?->timeout_sec);
            if ($claim->update([
                'status' => RunStatus::Running->value,
                'execution_deadline_at' => $now->copy()->addSeconds($timeout + max(1, (int) config('opsifin_cron.execution_margin_sec'))),
                'worker' => (gethostname() ?: 'executor').':'.getmypid(), 'updated_at' => $now,
            ]) !== 1) {
                return null;
            }
            $run->refresh();
            $run->setRelation('schedule', $schedule);
            $reason = match (true) {
                $schedule === null || $schedule->client === null || $schedule->taskTemplate === null => 'The schedule, client, or task no longer exists.',
                ! $schedule->client->is_active || ! $schedule->taskTemplate->is_active => 'The client, task, or schedule is paused.',
                ! $schedule->is_enabled && ! in_array($run->trigger, [RunTrigger::Manual, RunTrigger::Retry], true) => 'The client, task, or schedule is paused.',
                $schedule->prevent_overlap && $schedule->running_run_id !== null => 'Previous run is still running.',
                default => null,
            };
            if ($reason !== null) {
                $this->finish($run, RunStatus::Skipped, errorMessage: $reason);

                return null;
            }
            if ($schedule->prevent_overlap) {
                $schedule->forceFill(['running_run_id' => $run->id])->saveQuietly();
            }

            return $run;
        });
        if ($run === null) {
            return null;
        }
        try {
            $request = $this->executors->for($run->schedule->taskTemplate)
                ->resolve($run->schedule->taskTemplate, $run->schedule->client, $run);
        } catch (Throwable) {
            // Resolution can contain credentials, so never log the raw exception.
            $this->finish($run, RunStatus::Failed, errorMessage: 'Invalid request configuration; check the client URL and task template.');

            return null;
        }
        // Database failures are infrastructure failures, not invalid templates.
        $now = now();
        if ($from === RunStatus::Pending && $run->scheduled_for->copy()->addSeconds(config('opsifin_cron.direct.start_window_sec'))->lte($now)) {
            $this->finish($run, RunStatus::Skipped, errorMessage: 'Missed start window; occurrence will not be replayed.');

            return null;
        }
        if (Run::query()->whereKey($runId)->where('status', RunStatus::Running->value)->update([
            'started_at' => $now,
            'start_lag_ms' => max(0, (int) $run->scheduled_for->diffInMilliseconds($now)), 'updated_at' => $now,
        ]) !== 1) {
            return null;
        }

        return new RunExecution($run, $request, microtime(true));
    }

    public function complete(RunExecution $execution, ExecutionResult $result): void
    {
        $this->finish($execution->run, $result->success ? RunStatus::Succeeded : RunStatus::Failed,
            duration: $result->durationMs, httpStatus: $result->statusCode,
            responseExcerpt: $result->outputExcerpt === null ? null : Str::limit($execution->request->redact($result->outputExcerpt), max(1, (int) config('opsifin_cron.response_excerpt_length')), ''),
            errorMessage: $result->errorMessage === null ? null : Str::limit($execution->request->redact($result->errorMessage), 1000, ''),
        );
    }

    public function fail(RunExecution $execution, string $message): void
    {
        $this->complete($execution, new ExecutionResult(false, null, null, $message,
            (int) round((microtime(true) - $execution->started) * 1000)));
    }

    public function finish(Run $run, RunStatus $status, int $duration = 0, ?int $httpStatus = null, ?string $responseExcerpt = null, ?string $errorMessage = null): void
    {
        DB::transaction(function () use ($run, $status, $duration, $httpStatus, $responseExcerpt, $errorMessage): void {
            Run::query()->whereKey($run->id)->where('status', RunStatus::Running->value)->update([
                'status' => $status->value, 'finished_at' => now(), 'execution_deadline_at' => null,
                'duration_ms' => $duration, 'http_status' => $httpStatus,
                'response_excerpt' => $responseExcerpt, 'error_message' => $errorMessage, 'updated_at' => now(),
            ]);
            Schedule::query()->whereKey($run->schedule_id)->where('running_run_id', $run->id)
                ->update(['running_run_id' => null, 'updated_at' => now()]);
        });
    }
}
