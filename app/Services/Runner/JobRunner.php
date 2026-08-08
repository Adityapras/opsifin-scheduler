<?php

namespace App\Services\Runner;

use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\Run;
use App\Models\Schedule;
use App\Services\Alerting\AlertEvaluator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Satu titik eksekusi untuk seluruh job — menggantikan 478 script curl.
 *
 * Selalu menulis satu baris ke tabel `runs`, apa pun hasilnya, termasuk saat
 * job dilewati karena lock atau karena schedule dinonaktifkan.
 */
class JobRunner
{
    public function run(
        Schedule $schedule,
        RunTrigger $trigger = RunTrigger::Cron,
        bool $dryRun = false,
    ): Run {
        $schedule->loadMissing(['client', 'taskTemplate']);

        if (! $schedule->is_enabled && $trigger === RunTrigger::Cron) {
            return $this->record($schedule, $trigger, RunStatus::SkippedDisabled, [
                'error_message' => 'The schedule is disabled.',
            ]);
        }

        if (! $schedule->client->is_active && $trigger === RunTrigger::Cron) {
            return $this->record($schedule, $trigger, RunStatus::SkippedDisabled, [
                'error_message' => "Client '{$schedule->client->code}' is inactive.",
            ]);
        }

        $request = $schedule->resolveRequest();

        if ($dryRun) {
            return $this->record($schedule, RunTrigger::DryRun, RunStatus::Success, [
                'request_method' => $request['method']->value,
                'request_url' => $request['url'],
                'response_excerpt' => $this->describeRequest($request),
                'duration_ms' => 0,
                'finished_at' => now(),
            ]);
        }

        $lock = JobLock::acquire($schedule);

        if ($lock === null) {
            return $this->record($schedule, $trigger, RunStatus::SkippedLock, [
                'request_method' => $request['method']->value,
                'request_url' => $request['url'],
                'error_message' => 'The previous execution is still running (lock: '.$schedule->lock_key.').',
            ]);
        }

        try {
            return $this->execute($schedule, $trigger, $request);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function execute(Schedule $schedule, RunTrigger $trigger, array $request): Run
    {
        $run = $this->record($schedule, $trigger, RunStatus::Running, [
            'request_method' => $request['method']->value,
            'request_url' => $request['url'],
            'finished_at' => null,
        ]);

        $startedAt = microtime(true);
        $excerptLength = (int) config('opsifin_cron.response_excerpt_length');

        try {
            $pending = Http::withHeaders(array_merge([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], $request['headers']))
                ->timeout($request['timeout'])
                ->connectTimeout($request['connect_timeout']);

            if ($request['retries'] > 0) {
                $pending = $pending->retry($request['retries'] + 1, 1000, throw: false);
            }

            if ($request['body'] !== null && $request['body'] !== '') {
                $pending = $pending->withBody($request['body'], 'application/json');
            }

            $response = $pending->send($request['method']->value, $request['url']);

            $run->fill([
                'status' => $response->successful() ? RunStatus::Success : RunStatus::Failed,
                'http_status' => $response->status(),
                'response_excerpt' => Str::limit($response->body(), $excerptLength),
                'error_message' => $response->successful()
                    ? null
                    : 'HTTP '.$response->status().' '.$response->reason(),
            ]);
        } catch (ConnectionException $e) {
            $run->fill([
                'status' => RunStatus::Timeout,
                'error_message' => Str::limit($e->getMessage(), 1000),
            ]);
        } catch (Throwable $e) {
            $run->fill([
                'status' => RunStatus::Failed,
                'error_message' => Str::limit($e::class.': '.$e->getMessage(), 1000),
            ]);
        }

        $run->finished_at = now();
        $run->duration_ms = (int) round((microtime(true) - $startedAt) * 1000);
        $run->save();

        $schedule->last_run_at = $run->started_at;
        $schedule->recalculateNextRun();
        $schedule->saveQuietly();

        // Alerting tidak boleh menjatuhkan eksekusi job: hasil run sudah
        // tersimpan di atas, jadi kegagalan di sini hanya dicatat ke log.
        try {
            app(AlertEvaluator::class)->evaluateRun($run);
        } catch (Throwable $e) {
            report($e);
        }

        return $run;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(Schedule $schedule, RunTrigger $trigger, RunStatus $status, array $attributes = []): Run
    {
        return Run::create(array_merge([
            'schedule_id' => $schedule->id,
            'client_id' => $schedule->client_id,
            'task_template_id' => $schedule->task_template_id,
            'trigger' => $trigger,
            'status' => $status,
            'started_at' => now(),
            'finished_at' => now(),
            'duration_ms' => 0,
            'host' => gethostname() ?: null,
        ], $attributes));
    }

    /**
     * Ringkasan request yang akan dikirim — dipakai tombol "Dry run" di UI.
     *
     * Kredensial disamarkan secara default karena hasil dry run ikut tersimpan
     * di `runs.response_excerpt`, yang bisa dibaca semua user aktif dan bertahan
     * selama masa retensi. Pemanggil yang berhak boleh meminta nilai aslinya
     * untuk ditampilkan tanpa ikut disimpan.
     *
     * @param  array<string, mixed>  $request
     */
    public function describeRequest(array $request, bool $maskSecrets = true): string
    {
        $lines = ['(dry run — the request is not sent)', ''];
        $lines[] = $request['method']->value.' '.$request['url'];

        $headers = $maskSecrets ? $this->maskHeaders($request['headers']) : $request['headers'];

        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        $lines[] = '';
        $lines[] = $request['body'] ?? '(no body)';
        $lines[] = '';
        $lines[] = sprintf('timeout=%ds connect_timeout=%ds retries=%d',
            $request['timeout'], $request['connect_timeout'], $request['retries']);

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function maskHeaders(array $headers): array
    {
        $secret = ['authorization', 'secretkey'];
        $masked = [];

        foreach ($headers as $name => $value) {
            $masked[$name] = in_array(strtolower($name), $secret, true)
                ? Str::limit($value, 12, '… (masked)')
                : $value;
        }

        return $masked;
    }
}
