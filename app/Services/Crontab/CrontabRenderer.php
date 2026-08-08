<?php

namespace App\Services\Crontab;

use App\Models\Schedule;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Menghasilkan isi /etc/cron.d/opsifin dari tabel `schedules`.
 *
 * File cron.d dipilih ketimbang `crontab -e` karena punya kolom user, bisa
 * di-version-control, dan tidak menimpa crontab manual yang mungkin masih ada
 * selama masa migrasi (§3.4 rencana).
 */
class CrontabRenderer
{
    public const BEGIN_MARKER = '# BEGIN OPSIFIN-CRON MANAGED BLOCK';

    public const END_MARKER = '# END OPSIFIN-CRON MANAGED BLOCK';

    public function render(?Carbon $generatedAt = null): string
    {
        $config = config('opsifin_cron.deploy');
        $generatedAt ??= now();

        $lines = [];
        $lines[] = self::BEGIN_MARKER.' — generated '.$generatedAt->toIso8601String();
        $lines[] = '# Dihasilkan otomatis oleh `php artisan cron:render`. JANGAN diedit manual —';
        $lines[] = '# perubahan akan tertimpa pada deploy berikutnya. Sumber kebenaran: tabel `schedules`.';
        $lines[] = '';
        $lines[] = 'SHELL=/bin/bash';
        $lines[] = 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        $lines[] = 'MAILTO=""';

        $schedules = $this->enabledSchedules();
        $timezones = $schedules->pluck('timezone')->unique();

        // Tanpa CRON_TZ, daemon memakai timezone server — bukan timezone schedule.
        if ($timezones->count() === 1) {
            $lines[] = 'CRON_TZ='.$timezones->first();
        }

        $lines[] = '';

        if ($schedules->isEmpty()) {
            $lines[] = '# (tidak ada schedule aktif)';
        }

        foreach ($schedules->groupBy(fn (Schedule $s) => $s->client->code) as $clientCode => $group) {
            $lines[] = '# ── '.$clientCode.' — '.$group->first()->client->name;

            foreach ($group as $schedule) {
                $lines[] = $this->renderComment($schedule);
                $lines[] = $this->renderLine($schedule, $config);
            }

            $lines[] = '';
        }

        $lines[] = self::END_MARKER;
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @return Collection<int, Schedule>
     */
    public function enabledSchedules(): Collection
    {
        return Schedule::with(['client', 'taskTemplate'])
            ->where('is_enabled', true)
            ->whereHas('client', fn ($q) => $q->where('is_active', true))
            ->whereHas('taskTemplate', fn ($q) => $q->where('is_active', true))
            ->join('clients', 'clients.id', '=', 'schedules.client_id')
            ->join('task_templates', 'task_templates.id', '=', 'schedules.task_template_id')
            ->orderBy('clients.code')
            ->orderBy('task_templates.key')
            ->select('schedules.*')
            ->get();
    }

    private function renderComment(Schedule $schedule): string
    {
        return sprintf(
            '# schedule_id=%d client=%s task=%s tz=%s lock=%s',
            $schedule->id,
            $schedule->client->code,
            $schedule->taskTemplate->key,
            $schedule->timezone,
            $schedule->lock_key,
        );
    }

    /**
     * @param  array<string, string>  $config
     */
    private function renderLine(Schedule $schedule, array $config): string
    {
        $php = $config['php_binary'];
        $artisan = rtrim($config['base_dir'], '/').'/artisan';
        $log = rtrim(config('opsifin_cron.log_dir'), '/').'/runner.log';

        // Dua lapis lock, sengaja pakai file berbeda:
        //  - di sini: mencegah proses PHP menumpuk kalau runner menggantung
        //    (mis. MySQL lambat) — murah, tidak butuh bootstrap Laravel.
        //  - di dalam runner: lock sebenarnya per job, yang mencatat status
        //    `skipped_lock` ke tabel `runs` dan juga berlaku untuk "Run now".
        return sprintf(
            '%s %s %s -n %s %s %s cron:run %d >> %s 2>&1',
            $schedule->cron_expression,
            $config['user'],
            $config['flock_binary'],
            escapeshellarg($schedule->cronLockFilePath()),
            $php,
            escapeshellarg($artisan),
            $schedule->id,
            $log,
        );
    }

    /**
     * Sisipkan / ganti managed block di dalam isi file yang sudah ada, sehingga
     * baris manual di luar block tidak ikut terhapus.
     */
    public function merge(string $existing, string $block): string
    {
        $begin = strpos($existing, self::BEGIN_MARKER);
        $end = strpos($existing, self::END_MARKER);

        if ($begin === false || $end === false || $end < $begin) {
            return rtrim($existing) === ''
                ? $block
                : rtrim($existing)."\n\n".$block;
        }

        $before = substr($existing, 0, $begin);
        $after = substr($existing, $end + strlen(self::END_MARKER));

        return $before.rtrim($block)."\n".ltrim($after, "\n");
    }

    /**
     * Validasi tiap baris sebelum deploy.
     *
     * @return array<int, array{schedule: Schedule, problem: string}>
     */
    public function validate(): array
    {
        $problems = [];
        $schedules = $this->enabledSchedules();

        // Satu file cron.d hanya bisa punya satu CRON_TZ. Timezone campuran
        // berarti sebagian job akan jalan di jam yang salah.
        $timezones = $schedules->pluck('timezone')->unique();

        if ($timezones->count() > 1) {
            foreach ($schedules as $schedule) {
                if ($schedule->timezone !== $timezones->first()) {
                    $problems[] = [
                        'schedule' => $schedule,
                        'problem' => "Timezone '{$schedule->timezone}' berbeda dari mayoritas ('{$timezones->first()}'). ".
                            'Satu file cron.d hanya mendukung satu CRON_TZ.',
                    ];
                }
            }
        }

        foreach ($schedules as $schedule) {
            if (! CronExpression::isValidExpression($schedule->cron_expression)) {
                $problems[] = ['schedule' => $schedule, 'problem' => 'Ekspresi cron tidak valid.'];

                continue;
            }

            if (str_contains($schedule->cron_expression, "\n")) {
                $problems[] = ['schedule' => $schedule, 'problem' => 'Ekspresi cron mengandung newline.'];
            }

            if (blank($schedule->lock_key)) {
                $problems[] = ['schedule' => $schedule, 'problem' => 'lock_key kosong — flock wajib untuk setiap schedule.'];
            }

            if (! preg_match('/^[A-Za-z0-9._\-]+$/', (string) $schedule->lock_key)) {
                $problems[] = ['schedule' => $schedule, 'problem' => "lock_key '{$schedule->lock_key}' mengandung karakter yang tidak aman untuk nama file."];
            }

            $request = $schedule->resolveRequest();

            if (! filter_var($request['url'], FILTER_VALIDATE_URL)) {
                $problems[] = ['schedule' => $schedule, 'problem' => 'URL hasil resolve tidak valid: '.$request['url']];
            }

            if ($schedule->client->auth_type->value !== 'none' && blank($schedule->client->auth_username)) {
                $problems[] = ['schedule' => $schedule, 'problem' => "Client '{$schedule->client->code}' memakai auth ".$schedule->client->auth_type->value.' tapi username kosong.'];
            }
        }

        return $problems;
    }
}
