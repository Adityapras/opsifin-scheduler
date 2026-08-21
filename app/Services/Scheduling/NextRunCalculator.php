<?php

namespace App\Services\Scheduling;

use App\Models\Schedule;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class NextRunCalculator
{
    public function next(Schedule $schedule, ?Carbon $after = null): Carbon
    {
        if (! $schedule->isValidCron()) {
            throw new InvalidArgumentException('Invalid cron expression: '.$schedule->cron_expression);
        }

        $timezone = $schedule->timezone ?: config('opsifin_cron.default_timezone');
        $after ??= now();

        return Carbon::instance((new CronExpression($schedule->cron_expression))
            ->getNextRunDate($after->copy()->setTimezone($timezone), 0, false, $timezone))
            ->setTimezone(config('app.timezone'));
    }

    public function latestDue(Schedule $schedule, Carbon $at): Carbon
    {
        if (! $schedule->isValidCron()) {
            throw new InvalidArgumentException('Invalid cron expression: '.$schedule->cron_expression);
        }

        $timezone = $schedule->timezone ?: config('opsifin_cron.default_timezone');

        return Carbon::instance((new CronExpression($schedule->cron_expression))
            ->getPreviousRunDate($at->copy()->setTimezone($timezone), 0, true, $timezone))
            ->setTimezone(config('app.timezone'));
    }
}
