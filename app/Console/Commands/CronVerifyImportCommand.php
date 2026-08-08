<?php

namespace App\Console\Commands;

use App\Enums\LegacyPattern;
use App\Models\ClientTaskOverride;
use App\Models\Schedule;
use App\Services\LegacyImport\CurlParser;
use App\Services\LegacyImport\Dto\ParsedCurl;
use App\Services\LegacyImport\ShellConfigParser;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Verifikasi round-trip: untuk setiap schedule hasil impor, susun ulang request
 * dari database lalu bandingkan dengan hasil parse langsung script legacy-nya.
 *
 * Ini jaring pengaman terhadap risiko "importer salah parse salah satu dari 478
 * script" (§7 rencana) — dijalankan sebelum shadow run, bukan menggantikannya.
 */
class CronVerifyImportCommand extends Command
{
    protected $signature = 'cron:verify-import
        {--source= : Folder repo cron legacy (default: config opsifin_cron.source_path)}
        {--limit=0 : Batasi jumlah perbedaan yang ditampilkan (0 = semua)}';

    protected $description = 'Bandingkan request hasil impor dengan script legacy aslinya';

    public function handle(CurlParser $parser, ShellConfigParser $configParser): int
    {
        $source = rtrim($this->option('source') ?: config('opsifin_cron.source_path'), '/');

        if (! is_dir($source)) {
            $this->error("Source path tidak ditemukan: {$source}");

            return self::FAILURE;
        }

        $envFile = $source.'/opsifin_env.sh';
        $env = is_file($envFile) ? $configParser->parse(file_get_contents($envFile) ?: '') : [];

        $matched = 0;
        $skipped = 0;
        $differences = [];

        $schedules = Schedule::with(['client', 'taskTemplate'])
            ->where('legacy_pattern', LegacyPattern::DirectScript->value)
            ->get();

        $overrides = ClientTaskOverride::all()
            ->keyBy(fn ($o) => $o->client_id.':'.$o->task_template_id);

        $bar = $this->output->createProgressBar($schedules->count());
        $bar->start();

        foreach ($schedules as $schedule) {
            $bar->advance();

            if (! preg_match('#/cron/([\w\-]+)/([\w\-.]+)\.sh#', (string) $schedule->legacy_command, $m)) {
                $skipped++;

                continue;
            }

            $file = $source.'/'.$m[1].'/'.$m[2].'.sh';

            if (! is_file($file)) {
                $skipped++;

                continue;
            }

            $curl = $parser->parseFile(file_get_contents($file) ?: '', $env);

            if ($curl === null || $curl->host === null) {
                $skipped++;

                continue;
            }

            $diffs = $this->compare($schedule, $curl, $overrides);

            if ($diffs === []) {
                $matched++;

                continue;
            }

            $differences[] = [$m[1].'/'.$m[2].'.sh', implode('; ', $diffs)];
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(['Hasil', 'Jumlah'], [
            ['Cocok persis dengan script legacy', $matched],
            ['Berbeda', count($differences)],
            ['Dilewati (script tidak ada / tidak bisa diparse)', $skipped],
        ]);

        if ($differences !== []) {
            $limit = (int) $this->option('limit');
            $shown = $limit > 0 ? array_slice($differences, 0, $limit) : $differences;

            $this->newLine();
            $this->warn('Perbedaan:');
            $this->table(['Script', 'Beda'], $shown);
        }

        return $differences === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  Collection<string, ClientTaskOverride>  $overrides
     * @return array<int, string>
     */
    private function compare(Schedule $schedule, ParsedCurl $curl, $overrides): array
    {
        $template = $schedule->taskTemplate;
        $client = $schedule->client;
        $override = $overrides->get($schedule->client_id.':'.$schedule->task_template_id);

        $baseUrl = rtrim($override?->base_url_override ?: $client->base_url, '/');
        $path = $override?->path_override ?: $template->path_template;
        $method = $override?->method_override ?: $template->http_method->value;
        $body = $override?->body_override ?? $template->body_template;

        $headers = array_merge($template->headers ?? [], $override?->headers_override ?? []);

        $diffs = [];
        $expectedUrl = $curl->baseUrl().$curl->path;
        $actualUrl = $baseUrl.'/'.ltrim((string) $path, '/');

        if ($actualUrl !== $expectedUrl) {
            $diffs[] = "url {$actualUrl} != {$expectedUrl}";
        }

        if ($method !== $curl->method) {
            $diffs[] = "method {$method} != {$curl->method}";
        }

        if ($body !== $curl->body) {
            $diffs[] = 'body '.var_export($body, true).' != '.var_export($curl->body, true);
        }

        if ($curl->authScheme === 'Basic') {
            $expected = 'Basic '.base64_encode($curl->authUsername.':'.$curl->authPassword);

            if ($client->authorizationHeader() !== $expected) {
                $diffs[] = 'Authorization berbeda';
            }
        }

        if ($curl->secretKey !== null) {
            $resolved = strtr($headers['SecretKey'] ?? '', ['{{client.secret_key}}' => (string) $client->auth_secret_key]);

            if ($resolved !== $curl->secretKey) {
                $diffs[] = 'SecretKey berbeda';
            }
        }

        return $diffs;
    }
}
