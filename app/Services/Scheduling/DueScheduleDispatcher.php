<?php

namespace App\Services\Scheduling;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Models\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DueScheduleDispatcher
{
    public function __construct(
        private readonly NextRunCalculator $calculator,
        private readonly RunDispatcher $runs,
    ) {}

    /** @return array{scanned: int, queued: int, pending: int, skipped: int, recovered: int, errors: array<int, string>} */
    public function dispatch(?Carbon $at = null): array
    {
        $at = ($at ?? now())->copy()->setTimezone(config('app.timezone'))->startOfMinute();
        $recovered = $this->recoverExpiredRuns($at);
        $this->expirePendingRuns();

        $ids = Schedule::query()
            ->where('is_enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $at)
            ->whereHas('client', fn ($query) => $query->where('is_active', true))
            ->whereHas('taskTemplate', fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->pluck('id');

        $queued = 0;
        $pending = 0;
        $skipped = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $run = DB::transaction(fn () => $this->dispatchSchedule((int) $id, $at));

                if ($run?->status === RunStatus::Queued && $run->wasRecentlyCreated) {
                    $this->runs->enqueue($run);
                    $queued++;
                }

                $skipped += $run?->status === RunStatus::Skipped ? 1 : 0;
                $pending += $run?->status === RunStatus::Pending && $run->wasRecentlyCreated ? 1 : 0;
            } catch (Throwable $exception) {
                $errors[] = 'schedule '.$id.': '.Str::limit($exception->getMessage(), 500);
                report($exception);
            }
        }

        DB::table('executor_states')->where('name', 'dispatcher')->update(['heartbeat_at' => now()]);

        return compact('queued', 'pending', 'skipped', 'recovered', 'errors') + ['scanned' => $ids->count()];
    }

    private function dispatchSchedule(int $scheduleId, Carbon $at): ?Run
    {
        $schedule = Schedule::query()
            ->with(['client', 'taskTemplate'])
            ->lockForUpdate()
            ->findOrFail($scheduleId);

        if (! $schedule->isRunnable() || $schedule->next_run_at === null || $schedule->next_run_at->gt($at)) {
            return null;
        }

        $scheduledFor = $this->calculator->latestDue($schedule, $at);
        $schedule->forceFill(['next_run_at' => $this->calculator->next($schedule, $at)])->save();

        if (config('opsifin_cron.execution_driver') === 'direct'
            && $scheduledFor->copy()->addSeconds(config('opsifin_cron.direct.start_window_sec'))->lte(now())) {
            return $this->runs->materialize($schedule, $scheduledFor, status: RunStatus::Skipped,
                skipReason: 'Missed start window; occurrence will not be replayed.');
        }

        $slotBusy = $schedule->prevent_overlap
            && $schedule->running_run_id !== null
            && Run::query()->whereKey($schedule->running_run_id)->where('status', RunStatus::Running->value)->exists();

        if ($slotBusy) {
            return $this->runs->materialize(
                $schedule,
                $scheduledFor,
                status: RunStatus::Skipped,
                skipReason: 'Previous run is still running.',
            );
        }

        if ($schedule->prevent_overlap && $schedule->running_run_id !== null) {
            $schedule->forceFill(['running_run_id' => null])->save();
        }

        return $this->runs->materialize($schedule, $scheduledFor);
    }

    public function recoverExpiredRuns(Carbon $at): int
    {
        $recovered = 0;

        Run::query()
            ->where('status', RunStatus::Running->value)
            ->whereNotNull('execution_deadline_at')
            ->where('execution_deadline_at', '<', $at)
            ->orderBy('id')
            ->eachById(function (Run $run) use ($at, &$recovered): void {
                DB::transaction(function () use ($run, $at, &$recovered): void {
                    $updated = Run::query()
                        ->whereKey($run->id)
                        ->where('status', RunStatus::Running->value)
                        ->where('execution_deadline_at', '<', $at)
                        ->update([
                            'status' => RunStatus::Failed->value,
                            'finished_at' => $at,
                            'execution_deadline_at' => null,
                            'error_message' => $run->execution_driver === 'direct'
                                ? 'Execution process ended after HTTP may have been sent; endpoint outcome is unknown. No retry.'
                                : 'The worker did not finish before the execution deadline.',
                            'updated_at' => $at,
                        ]);

                    if ($updated !== 1) {
                        return;
                    }

                    Schedule::query()
                        ->whereKey($run->schedule_id)
                        ->where('running_run_id', $run->id)
                        ->update(['running_run_id' => null, 'updated_at' => $at]);

                    $recovered++;
                });
            });

        return $recovered;
    }

    public function expirePendingRuns(): int
    {
        return Run::query()->where('status', RunStatus::Pending->value)
            ->where('scheduled_for', '<=', now()->subSeconds(config('opsifin_cron.direct.start_window_sec')))
            ->update(['status' => RunStatus::Skipped->value, 'finished_at' => now(), 'duration_ms' => 0,
                'error_message' => 'Missed start window; occurrence will not be replayed.', 'updated_at' => now()]);
    }
}
