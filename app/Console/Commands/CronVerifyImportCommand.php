<?php

namespace App\Console\Commands;

use App\Models\TaskTemplate;
use App\Services\LegacyImport\CurlParser;
use App\Services\LegacyImport\Dto\ParsedCurl;
use Illuminate\Console\Command;

class CronVerifyImportCommand extends Command
{
    protected $signature = 'cron:verify-import
        {--source= : Legacy cron repo folder (default: config opsifin_cron.source_path)}
        {--limit=0 : Limit how many differences are shown (0 = all)}';

    protected $description = 'Compare imported task templates against the canonical jobs/*.sh catalog';

    public function handle(CurlParser $parser): int
    {
        $source = rtrim($this->option('source') ?: config('opsifin_cron.source_path'), '/');

        if (! is_dir($source)) {
            $this->error("Source path not found: {$source}");

            return self::FAILURE;
        }

        $variables = [
            'API_URL' => 'https://__base__',
            'AUTH_TOKEN' => '__auth__',
            'API_SECRET_KEY' => '{{client.secret_key}}',
            'API_USERNAME' => '{{client.username}}',
            'API_PASSWORD' => '{{client.secret}}',
            'CLIENT_NAME' => '',
        ];
        $matched = 0;
        $skipped = 0;
        $differences = [];
        $templates = TaskTemplate::query()->orderBy('key')->get();

        $bar = $this->output->createProgressBar($templates->count());
        $bar->start();

        foreach ($templates as $template) {
            $bar->advance();

            if ($template->legacy_job_file === null) {
                $skipped++;

                continue;
            }

            $file = $source.'/'.$template->legacy_job_file;
            if (! is_file($file)) {
                $skipped++;

                continue;
            }

            $curl = $parser->parseFile(file_get_contents($file) ?: '', $variables);
            if ($curl === null || $curl->path === null) {
                $skipped++;

                continue;
            }

            $diffs = $this->compare($template, $curl);
            if ($diffs === []) {
                $matched++;

                continue;
            }

            $differences[] = [$template->legacy_job_file, implode('; ', $diffs)];
        }

        $bar->finish();
        $this->newLine(2);
        $this->table(['Result', 'Count'], [
            ['Exact match with jobs/', $matched],
            ['Different', count($differences)],
            ['Skipped (job source missing / could not be parsed)', $skipped],
        ]);

        if ($differences !== []) {
            $limit = (int) $this->option('limit');
            $this->newLine();
            $this->warn('Differences:');
            $this->table(['Canonical job', 'Difference'], $limit > 0 ? array_slice($differences, 0, $limit) : $differences);
        }

        return $differences === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<int, string> */
    private function compare(TaskTemplate $template, ParsedCurl $curl): array
    {
        $config = $template->config ?? [];
        $diffs = [];

        if (($config['path'] ?? null) !== $curl->path) {
            $diffs[] = 'path differs';
        }
        if (($config['method'] ?? 'POST') !== $curl->method) {
            $diffs[] = 'method differs';
        }
        if (($config['body'] ?? null) !== $curl->body) {
            $diffs[] = 'body differs';
        }

        $headers = $curl->extraHeaders();
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'SecretKey') === 0) {
                $headers[$name] = '{{client.secret_key}}';
            }
        }
        if (($config['headers'] ?? []) !== $headers) {
            $diffs[] = 'headers differ';
        }

        return $diffs;
    }
}
