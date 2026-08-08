<?php

namespace App\Services\Alerting;

use App\Enums\AlertCondition;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Run;
use App\Models\Schedule;
use Illuminate\Support\Collection;

/**
 * Menilai rule terhadap kenyataan.
 *
 * Dua pemicu yang berbeda sifatnya:
 *  - evaluateRun() dipanggil begitu sebuah run selesai — menangkap job yang
 *    gagal atau timeout.
 *  - evaluateMissedRuns() dipanggil scheduler — menangkap job yang tidak pernah
 *    jalan sama sekali. Ini yang mendeteksi cron mati atau crontab belum
 *    di-deploy, kondisi yang tidak akan pernah menghasilkan baris di `runs`.
 */
class AlertEvaluator
{
    public function __construct(private readonly AlertDispatcher $dispatcher) {}

    /**
     * @return array<int, Alert>
     */
    public function evaluateRun(Run $run): array
    {
        // Dry run tidak memanggil endpoint, dan schedule nonaktif memang sengaja
        // dilewati — keduanya bukan kegagalan.
        if ($run->trigger === RunTrigger::DryRun || $run->status === RunStatus::SkippedDisabled) {
            return [];
        }

        $schedule = $run->schedule;

        if ($schedule === null) {
            return [];
        }

        $fired = [];

        $rules = AlertRule::query()
            ->matching($schedule)
            ->whereIn('condition', [
                AlertCondition::OnFailure->value,
                AlertCondition::OnTimeout->value,
                AlertCondition::ConsecutiveFailures->value,
            ])
            ->get();

        foreach ($rules as $rule) {
            $alert = match ($rule->condition) {
                AlertCondition::OnFailure => $this->checkFailure($rule, $schedule, $run),
                AlertCondition::OnTimeout => $this->checkTimeout($rule, $schedule, $run),
                AlertCondition::ConsecutiveFailures => $this->checkConsecutive($rule, $schedule, $run),
                default => null,
            };

            if ($alert !== null) {
                $fired[] = $alert;
            }
        }

        return $fired;
    }

    /**
     * @return array<int, Alert>
     */
    public function evaluateMissedRuns(): array
    {
        $rules = AlertRule::query()
            ->where('is_active', true)
            ->where('condition', AlertCondition::MissedRun->value)
            ->get();

        if ($rules->isEmpty()) {
            return [];
        }

        $fired = [];

        foreach ($this->watchedSchedules() as $schedule) {
            foreach ($rules as $rule) {
                if (! $this->ruleCovers($rule, $schedule)) {
                    continue;
                }

                $alert = $this->checkMissed($rule, $schedule);

                if ($alert !== null) {
                    $fired[] = $alert;

                    // Satu schedule cukup satu alert missed-run per siklus.
                    break;
                }
            }
        }

        return $fired;
    }

    /**
     * Hanya schedule yang benar-benar dirender ke crontab yang boleh dianggap
     * terlambat — gerbangnya sama dengan CrontabRenderer.
     *
     * @return Collection<int, Schedule>
     */
    private function watchedSchedules(): Collection
    {
        return Schedule::query()
            ->with(['client', 'taskTemplate'])
            ->where('is_enabled', true)
            ->whereHas('client', fn ($q) => $q->where('is_active', true))
            ->whereHas('taskTemplate', fn ($q) => $q->where('is_active', true))
            ->get();
    }

    private function ruleCovers(AlertRule $rule, Schedule $schedule): bool
    {
        return ($rule->client_id === null || $rule->client_id === $schedule->client_id)
            && ($rule->task_template_id === null || $rule->task_template_id === $schedule->task_template_id)
            && ($rule->schedule_id === null || $rule->schedule_id === $schedule->id);
    }

    private function checkFailure(AlertRule $rule, Schedule $schedule, Run $run): ?Alert
    {
        if ($run->status !== RunStatus::Failed) {
            return null;
        }

        return $this->dispatcher->fire(
            $rule,
            $schedule,
            $this->target($schedule).' failed',
            trim(($run->http_status ? 'HTTP '.$run->http_status.'. ' : '').($run->error_message ?? '')),
            $run,
        );
    }

    private function checkTimeout(AlertRule $rule, Schedule $schedule, Run $run): ?Alert
    {
        if ($run->status !== RunStatus::Timeout) {
            return null;
        }

        return $this->dispatcher->fire(
            $rule,
            $schedule,
            $this->target($schedule).' timed out',
            'Took longer than the configured timeout. '.($run->error_message ?? ''),
            $run,
        );
    }

    private function checkConsecutive(AlertRule $rule, Schedule $schedule, Run $run): ?Alert
    {
        if (! $run->status->isProblem()) {
            return null;
        }

        $threshold = max(1, (int) $rule->threshold);

        $recent = Run::query()
            ->where('schedule_id', $schedule->id)
            ->whereNot('trigger', RunTrigger::DryRun->value)
            ->whereNot('status', RunStatus::SkippedDisabled->value)
            ->orderByDesc('started_at')
            ->limit($threshold)
            ->get();

        if ($recent->count() < $threshold) {
            return null;
        }

        foreach ($recent as $candidate) {
            if (! $candidate->status->isProblem()) {
                return null;
            }
        }

        return $this->dispatcher->fire(
            $rule,
            $schedule,
            $this->target($schedule).' failed '.$threshold.' times in a row',
            'The last '.$threshold.' runs all failed or timed out. Latest: '.($run->error_message ?? $run->status->label()),
            $run,
        );
    }

    private function checkMissed(AlertRule $rule, Schedule $schedule): ?Alert
    {
        $previous = $schedule->previousRun();

        if ($previous === null) {
            return null;
        }

        $deadline = $previous->copy()->addMinutes((int) $rule->grace_minutes);

        if (now()->lt($deadline)) {
            return null;
        }

        $lastRun = $schedule->last_run_at;

        // Belum pernah jalan sama sekali. Schedule yang baru saja diaktifkan
        // belum layak dianggap terlambat, jadi perubahan terakhirnya harus
        // sudah lebih tua dari jadwal yang terlewat itu.
        if ($lastRun === null) {
            return $schedule->updated_at?->lt($previous)
                ? $this->fireMissed($rule, $schedule, $previous, 'It has never run.')
                : null;
        }

        if ($lastRun->gte($previous)) {
            return null;
        }

        return $this->fireMissed(
            $rule,
            $schedule,
            $previous,
            'Last run was '.$lastRun->diffForHumans().'.',
        );
    }

    private function fireMissed(AlertRule $rule, Schedule $schedule, $expectedAt, string $detail): ?Alert
    {
        return $this->dispatcher->fire(
            $rule,
            $schedule,
            $this->target($schedule).' missed its run',
            'Expected at '.$expectedAt->format('D, d M H:i').' ('.$schedule->timezone.'). '.$detail
                .' Check that the crontab is deployed and the cron daemon is running.',
            null,
        );
    }

    private function target(Schedule $schedule): string
    {
        return $schedule->client?->code.' / '.$schedule->taskTemplate?->key;
    }
}
