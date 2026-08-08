<?php

namespace App\Console\Commands;

use App\Services\Crontab\CrontabDeployer;
use App\Services\Crontab\CrontabRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class CronRenderCommand extends Command
{
    protected $signature = 'cron:render
        {--validate : Check every enabled schedule without writing anything}
        {--apply : Write to the cron.d file (with an automatic backup)}
        {--output= : Write to this path instead of the real cron.d file}
        {--show : Print the full rendered output}';

    protected $description = 'Generate crontab lines from the schedules table';

    public function handle(CrontabRenderer $renderer, CrontabDeployer $deployer): int
    {
        $target = $this->option('output') ?: $deployer->targetPath();

        $this->line('Target   : '.$target);
        $this->line('Schedules: '.$renderer->enabledSchedules()->count().' enabled');
        $this->newLine();

        $problems = $renderer->validate();

        if ($problems !== []) {
            $this->error('Validation failed for '.count($problems).' schedules:');
            $this->table(
                ['ID', 'Client', 'Task', 'Problem'],
                array_map(fn ($p) => [
                    $p['schedule']->id,
                    $p['schedule']->client->code,
                    $p['schedule']->taskTemplate->key,
                    $p['problem'],
                ], $problems),
            );

            if ($this->option('apply')) {
                $this->error('Deploy cancelled — fix the problems above first.');

                return self::FAILURE;
            }
        } else {
            $this->info('Validation: every enabled schedule passed.');
        }

        if ($this->option('validate')) {
            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->renderDiff($deployer, $target);

        if ($this->option('show')) {
            $this->newLine();
            $this->line($deployer->preview($target));
        }

        if (! $this->option('apply')) {
            $staging = storage_path('app/crontab-staging/opsifin.cron');
            File::ensureDirectoryExists(dirname($staging));
            File::put($staging, $deployer->preview($target));

            $this->newLine();
            $this->info('Staging written to: '.$staging);
            $this->comment('Run again with --apply to deploy.');

            return self::SUCCESS;
        }

        try {
            $result = $deployer->apply($target);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Deployed  : '.$result['path'].' ('.$result['bytes'].' bytes)');
        $this->line('Backup    : '.($result['backup'] ?? '(the previous file was empty)'));
        $this->comment('cron.d is re-read automatically by the cron daemon — no manual reload needed.');

        return self::SUCCESS;
    }

    private function renderDiff(CrontabDeployer $deployer, string $target): void
    {
        $diff = $deployer->diff($target);

        if ($diff === []) {
            $this->info('Diff: no changes.');

            return;
        }

        $added = count(array_filter($diff, fn ($d) => $d['type'] === 'added'));
        $removed = count($diff) - $added;

        $this->line("Diff: <fg=green>+{$added}</> <fg=red>-{$removed}</> lines");
        $this->newLine();

        foreach ($diff as $entry) {
            $entry['type'] === 'added'
                ? $this->line('<fg=green>+ '.$entry['line'].'</>')
                : $this->line('<fg=red>- '.$entry['line'].'</>');
        }
    }
}
