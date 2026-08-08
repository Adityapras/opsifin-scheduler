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
        {--validate : Cek kelayakan tiap schedule aktif, tanpa menulis apa pun}
        {--apply : Tulis ke file cron.d (dengan backup otomatis)}
        {--output= : Tulis ke path ini alih-alih file cron.d sebenarnya}
        {--show : Tampilkan isi lengkap hasil render}';

    protected $description = 'Generate baris crontab dari tabel schedules';

    public function handle(CrontabRenderer $renderer, CrontabDeployer $deployer): int
    {
        $target = $this->option('output') ?: $deployer->targetPath();

        $this->line('Target   : '.$target);
        $this->line('Schedule : '.$renderer->enabledSchedules()->count().' aktif');
        $this->newLine();

        $problems = $renderer->validate();

        if ($problems !== []) {
            $this->error('Validasi gagal untuk '.count($problems).' schedule:');
            $this->table(
                ['ID', 'Client', 'Task', 'Masalah'],
                array_map(fn ($p) => [
                    $p['schedule']->id,
                    $p['schedule']->client->code,
                    $p['schedule']->taskTemplate->key,
                    $p['problem'],
                ], $problems),
            );

            if ($this->option('apply')) {
                $this->error('Deploy dibatalkan — perbaiki dulu masalah di atas.');

                return self::FAILURE;
            }
        } else {
            $this->info('Validasi: semua schedule aktif lolos.');
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
            $this->info('Staging ditulis ke: '.$staging);
            $this->comment('Jalankan ulang dengan --apply untuk men-deploy.');

            return self::SUCCESS;
        }

        try {
            $result = $deployer->apply($target);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Deployed  : '.$result['path'].' ('.$result['bytes'].' byte)');
        $this->line('Backup    : '.($result['backup'] ?? '(file sebelumnya kosong)'));
        $this->comment('cron.d dibaca ulang otomatis oleh daemon cron — tidak perlu reload manual.');

        return self::SUCCESS;
    }

    private function renderDiff(CrontabDeployer $deployer, string $target): void
    {
        $diff = $deployer->diff($target);

        if ($diff === []) {
            $this->info('Diff: tidak ada perubahan.');

            return;
        }

        $added = count(array_filter($diff, fn ($d) => $d['type'] === 'added'));
        $removed = count($diff) - $added;

        $this->line("Diff: <fg=green>+{$added}</> <fg=red>-{$removed}</> baris");
        $this->newLine();

        foreach ($diff as $entry) {
            $entry['type'] === 'added'
                ? $this->line('<fg=green>+ '.$entry['line'].'</>')
                : $this->line('<fg=red>- '.$entry['line'].'</>');
        }
    }
}
