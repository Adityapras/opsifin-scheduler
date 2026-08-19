<?php

namespace App\Services\Scheduling;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Run;
use App\Models\Schedule;
use App\Services\Execution\ExecutorManager;
use Throwable;

class RunWorker
{
    public const DONE = 'done';

    public function __construct(private readonly ExecutorManager $executors) {}

    public function process(int $runId): string
    {
        $run = Run::query()->with(['schedule.client', 'schedule.taskTemplate'])->find($runId);

        if ($run === null || $run->isTerminal() || $run->status === RunStatus::Running) {
            return self::DONE;
        }

        $schedule = $run->schedule;
        if ($schedule === null || $schedule->client === null || $schedule->taskTemplate === null) {
            Run::query()
                ->whereKey($run->id)
                ->where('status', RunStatus::Queued->value)
                ->update([
                    'status' => RunStatus::Skipped->value,
                    'finished_at' => now(),
                    'duration_ms' => 0,
                    'error_message' => 'The schedule, client, or task no longer exists.',
                    'updated_at' => now(),
                ]);

            return self::DONE;
        }

        $deadlineSeconds = max(1, (int) $schedule->taskTemplate->timeout_sec)
            + (int) config('opsifin_cron.execution_margin_sec', 60);
        $worker = (gethostname() ?: 'worker').':'.getmypid();

        $claimed = Run::query()
            ->whereKey($run->id)
            ->where('status', RunStatus::Queued->value)
            ->update([
                'status' => RunStatus::Running->value,
                'started_at' => now(),
                'execution_deadline_at' => now()->addSeconds($deadlineSeconds),
                'worker' => $worker,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return self::DONE;
        }

        $run->refresh();
        $guardOverlap = $schedule->prevent_overlap;
        $slotHeld = ! $guardOverlap || Schedule::query()
            ->whereKey($schedule->id)
            ->whereNull('running_run_id')
            ->update(['running_run_id' => $run->id, 'updated_at' => now()]) === 1;
        $started = microtime(true);
        $request = null;

        try {
            if (! $slotHeld) {
                $this->finish($run, RunStatus::Skipped, $started, errorMessage: 'Previous run is still running.');

                return self::DONE;
            }

            if (! $this->mayRun($run, $schedule)) {
                $this->finish($run, RunStatus::Skipped, $started, errorMessage: 'The client, task, or schedule is paused.');

                return self::DONE;
            }

            $executor = $this->executors->for($schedule->taskTemplate);
            $request = $executor->resolve($schedule->taskTemplate, $schedule->client, $run);
            $result = $executor->execute($request);

            $this->finish(
                $run,
                $result->success ? RunStatus::Succeeded : RunStatus::Failed,
                $started,
                httpStatus: $result->statusCode,
                responseExcerpt: $request->redact($result->outputExcerpt),
                errorMessage: $request->redact($result->errorMessage),
            );

            return self::DONE;
        } catch (Throwable $exception) {
            $this->finish(
                $run,
                RunStatus::Failed,
                $started,
                errorMessage: $request?->redact($exception->getMessage())
                    ?? 'Execution failed while resolving the request. Check the application log.',
            );
            report($exception);

            return self::DONE;
        } finally {
            if ($guardOverlap && $slotHeld) {
                Schedule::query()
                    ->whereKey($schedule->id)
                    ->where('running_run_id', $run->id)
                    ->update(['running_run_id' => null, 'updated_at' => now()]);
            }
        }
    }

    private function mayRun(Run $run, Schedule $schedule): bool
    {
        if (! $schedule->client->is_active || ! $schedule->taskTemplate->is_active) {
            return false;
        }

        if (in_array($run->trigger, [RunTrigger::Manual, RunTrigger::Retry], true)) {
            return true;
        }

        return $schedule->is_enabled;
    }

    private function finish(
        Run $run,
        RunStatus $status,
        float $started,
        ?int $httpStatus = null,
        ?string $responseExcerpt = null,
        ?string $errorMessage = null,
    ): void {
        Run::query()
            ->whereKey($run->id)
            ->where('status', RunStatus::Running->value)
            ->update([
                'status' => $status->value,
                'finished_at' => now(),
                'execution_deadline_at' => null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'http_status' => $httpStatus,
                'response_excerpt' => $responseExcerpt,
                'error_message' => $errorMessage,
                'updated_at' => now(),
            ]);
    }
}
