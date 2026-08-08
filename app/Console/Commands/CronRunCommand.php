<?php

namespace App\Console\Commands;

use App\Enums\RunTrigger;
use App\Models\Schedule;
use App\Services\Runner\JobRunner;
use Illuminate\Console\Command;

/**
 * Satu-satunya entry point eksekusi job. Baris crontab yang di-generate
 * `cron:render` memanggil perintah ini dengan id schedule.
 */
class CronRunCommand extends Command
{
    protected $signature = 'cron:run
        {schedule : ID schedule, atau <client_code>/<task_key>}
        {--trigger=cron : cron|manual|shadow}
        {--dry-run : Tampilkan request yang akan dikirim tanpa memanggil endpoint}';

    protected $description = 'Jalankan satu schedule: HTTP call, catat ke tabel runs';

    public function handle(JobRunner $runner): int
    {
        $schedule = $this->resolveSchedule($this->argument('schedule'));

        if ($schedule === null) {
            $this->error('Schedule tidak ditemukan: '.$this->argument('schedule'));

            return self::FAILURE;
        }

        $trigger = RunTrigger::tryFrom($this->option('trigger')) ?? RunTrigger::Cron;

        $run = $runner->run($schedule, $trigger, (bool) $this->option('dry-run'));

        $this->line(sprintf(
            '[%s] %s / %s → %s%s (%d ms)',
            $run->started_at?->toDateTimeString(),
            $schedule->client->code,
            $schedule->taskTemplate->key,
            $run->status->value,
            $run->http_status ? ' HTTP '.$run->http_status : '',
            $run->duration_ms ?? 0,
        ));

        if ($run->error_message) {
            $this->warn($run->error_message);
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line($run->response_excerpt ?? '');
        }

        return $run->status->isProblem() ? self::FAILURE : self::SUCCESS;
    }

    private function resolveSchedule(string $identifier): ?Schedule
    {
        if (ctype_digit($identifier)) {
            return Schedule::with(['client', 'taskTemplate'])->find((int) $identifier);
        }

        [$clientCode, $taskKey] = array_pad(explode('/', $identifier, 2), 2, null);

        if ($taskKey === null) {
            return null;
        }

        return Schedule::with(['client', 'taskTemplate'])
            ->whereHas('client', fn ($q) => $q->where('code', $clientCode))
            ->whereHas('taskTemplate', fn ($q) => $q->where('key', $taskKey))
            ->first();
    }
}
