<?php

namespace App\Services\LegacyImport;

use App\Enums\FindingSeverity;
use App\Models\Client;
use App\Models\ImportRun;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use Illuminate\Support\Str;

class ReconciliationReport
{
    public function write(ImportRun $importRun, string $path): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, $this->render($importRun));
    }

    public function render(ImportRun $importRun): string
    {
        $stats = $importRun->stats ?? [];
        $findings = $importRun->findings;
        $out = [
            '# Lean Scheduler Legacy Import Report',
            '',
            '**Run at:** '.$importRun->started_at?->format('d M Y H:i:s T'),
            '**Source:** `'.$importRun->source_path.'`',
            '**Mode:** '.($importRun->dry_run ? 'dry run' : 'apply'),
            '',
            'Every imported schedule remains disabled. Resolve every error and review every warning before cutover.',
            '',
            '## 1. Summary',
            '',
            '| Metric | Value |',
            '| --- | ---: |',
            '| Client folders scanned | '.($stats['client_folders'] ?? 0).' |',
            '| Client scripts parsed | '.($stats['client_scripts'] ?? 0).' |',
            '| Crontab entries read | '.($stats['crontab_entries'] ?? 0).' |',
            '| Entries not mapped | '.($stats['entries_skipped'] ?? 0).' |',
            '| Canonical jobs in `jobs/` | '.($stats['canonical_job_templates'] ?? 0).' |',
            '| **Clients** | '.Client::count().' |',
            '| **Job templates** | '.TaskTemplate::count().' |',
            '| **Schedules** | '.Schedule::count().' |',
            '| **Enabled after import** | '.Schedule::where('is_enabled', true)->count().' |',
            '',
            '## 2. Findings',
            '',
        ];

        foreach ([FindingSeverity::Error, FindingSeverity::Warning, FindingSeverity::Info] as $severity) {
            $subset = $findings->where('severity', $severity);
            $out[] = '### '.ucfirst($severity->value).' ('.$subset->count().')';
            $out[] = '';

            if ($subset->isEmpty()) {
                $out[] = '_None._';
                $out[] = '';

                continue;
            }

            $out[] = '| Category | Location | Detail |';
            $out[] = '| --- | --- | --- |';
            foreach ($subset as $item) {
                $location = $item->source_file ?? '—';
                if ($item->source_line) {
                    $location .= ':'.$item->source_line;
                }
                $out[] = '| `'.$item->category.'` | `'.$location.'` | '.$this->escape($item->message).' |';
            }
            $out[] = '';
        }

        $out[] = '## 3. Client and assignment inventory';
        $out[] = '';
        $out[] = '| Client | Base URL | Active | Enabled/total schedules | Review |';
        $out[] = '| --- | --- | :---: | ---: | :---: |';
        $clients = Client::withCount([
            'schedules',
            'schedules as enabled_schedules_count' => fn ($query) => $query->where('is_enabled', true),
        ])->orderBy('code')->get();
        foreach ($clients as $client) {
            $out[] = sprintf(
                '| `%s` | %s | %s | %d/%d | %s |',
                $client->code,
                $client->base_url,
                $client->is_active ? 'yes' : 'no',
                $client->enabled_schedules_count,
                $client->schedules_count,
                $client->needs_review ? 'yes' : 'no',
            );
        }

        $out[] = '';
        $out[] = '## 4. Job template inventory';
        $out[] = '';
        $out[] = '| Key | Method | Path | Assignments | Review |';
        $out[] = '| --- | --- | --- | ---: | :---: |';
        foreach (TaskTemplate::withCount('schedules')->orderBy('key')->get() as $template) {
            $out[] = sprintf(
                '| `%s` | %s | `%s` | %d | %s |',
                $template->key,
                $template->config['method'] ?? 'POST',
                $template->config['path'] ?? '',
                $template->schedules_count,
                $template->needs_review ? 'yes' : 'no',
            );
        }

        $out[] = '';
        $out[] = '## 5. Pre-cutover checklist';
        $out[] = '';
        $out[] = '- [ ] Every error is resolved.';
        $out[] = '- [ ] Every warning has a written decision.';
        $out[] = '- [ ] Client credentials and final request previews are verified.';
        $out[] = '- [ ] Imported schedules are still disabled.';
        $out[] = '- [ ] One harmless Run now succeeds before scheduled cutover.';
        $out[] = '- [ ] Only the legacy line matching the selected assignment is disabled.';

        return implode("\n", $out)."\n";
    }

    private function escape(string $text): string
    {
        return Str::of($text)->replace('|', '\\|')->replace("\n", ' ')->toString();
    }
}
