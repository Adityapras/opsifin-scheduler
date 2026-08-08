<?php

namespace App\Services\LegacyImport;

use App\Enums\FindingSeverity;
use App\Models\Client;
use App\Models\ClientTaskOverride;
use App\Models\ImportRun;
use App\Models\Schedule;
use App\Models\TaskTemplate;
use Illuminate\Support\Str;

/**
 * Laporan rekonsiliasi importer — dokumen yang wajib direview manual
 * sebelum client mana pun boleh diaktifkan di sistem baru (§5 Fase 1, §7).
 */
class ReconciliationReport
{
    private const CATEGORY_LABELS = [
        'credential_drift' => 'Credentials differ between sources',
        'cross_client_host' => 'Script targets another client\'s host',
        'host_mismatch' => 'Host differs from the client base URL',
        'base_url_conflict' => 'Config base URL conflicts with the script folder',
        'gateway_route_missing_file' => 'Gateway route points to a missing file',
        'gateway_route_remapped' => 'Gateway route was remapped automatically',
        'gateway_task_unknown' => 'Unrecognised gateway task',
        'gateway_client_unknown' => 'Gateway client without a config file',
        'job_not_routed' => 'Orphan job (never routed)',
        'job_no_curl' => 'Job without a curl command',
        'script_missing' => 'Crontab calls a script that does not exist',
        'script_not_in_client_folder' => 'Script is not in its client folder',
        'script_no_curl' => 'Script without a curl command',
        'script_url_unresolved' => 'Script URL could not be resolved',
        'script_url_dangling' => 'URL on a separate line — curl runs without a URL',
        'client_folder_missing' => 'Crontab calls a client folder that does not exist',
        'script_without_template' => 'Script without a template',
        'suspicious_interval' => 'Cron interval is likely misread',
        'invalid_cron_expression' => 'Invalid cron expression',
        'duplicate_schedule' => 'Duplicate schedule in the crontab',
        'template_merged' => 'Templates merged because the endpoints are identical',
        'override_collision' => 'Two client scripts for the same task with different configs',
        'conf_no_url' => 'Config without API_URL',
        'conf_matches_secondary_host' => 'Config matches the folder\'s secondary host',
        'not_a_job' => 'Cron line is not an Opsifin job',
    ];

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

        $out = [];
        $out[] = '# Legacy Cron Import Reconciliation Report';
        $out[] = '';
        $out[] = '**Run at:** '.$importRun->started_at?->format('d M Y H:i:s T');
        $out[] = '**Source:** `'.$importRun->source_path.'`';
        $out[] = '**Mode:** '.($importRun->dry_run ? 'dry run (the database was not changed)' : 'apply');
        $out[] = '';
        $out[] = 'This document must be reviewed before any client is enabled in the new system. ';
        $out[] = 'Every **error** row must be resolved; every **warning** must have a written decision.';
        $out[] = '';

        // --- Ringkasan angka ---
        $out[] = '## 1. Summary';
        $out[] = '';
        $out[] = '| Metric | Value |';
        $out[] = '| --- | ---: |';
        $out[] = '| Client folders scanned | '.($stats['client_folders'] ?? 0).' |';
        $out[] = '| Client `.sh` files parsed | '.($stats['client_scripts'] ?? 0).' |';
        $out[] = '| `.conf` files | '.($stats['config_files'] ?? 0).' |';
        $out[] = '| Gateway jobs (`jobs/*.sh`) | '.($stats['gateway_jobs'] ?? 0).' |';
        $out[] = '| Routes registered in `gateway.sh` | '.($stats['gateway_routes'] ?? 0).' |';
        $out[] = '| Crontab entries read | '.($stats['crontab_entries'] ?? 0).' |';
        $out[] = '| — active (not commented out) | '.($stats['entries_active'] ?? 0).' |';
        $out[] = '| — commented out | '.($stats['entries_commented'] ?? 0).' |';
        $out[] = '| — could not be mapped | '.($stats['entries_skipped'] ?? 0).' |';
        $out[] = '| **Clients** | '.Client::count().' |';
        $out[] = '| **Task templates** | '.TaskTemplate::count().' |';
        $out[] = '| **Client task overrides** | '.ClientTaskOverride::count().' |';
        $out[] = '| **Schedules** | '.Schedule::count().' |';
        $out[] = '| — enabled | '.Schedule::where('is_enabled', true)->count().' |';
        $out[] = '';

        $activeEntries = $stats['entries_active'] ?? 0;
        $withFlock = $stats['legacy_active_with_flock'] ?? 0;
        $noMaxTime = $stats['scripts_without_max_time'] ?? 0;

        $out[] = '### Comparison with the old setup';
        $out[] = '';
        $out[] = '| Aspect | Old crontab | After import |';
        $out[] = '| --- | --- | --- |';
        $out[] = '| `flock` coverage | '.$withFlock.' of '.$activeEntries.
            ' active entries ('.$this->percent($withFlock, $activeEntries).') | '.
            Schedule::count().' of '.Schedule::count().' (100%) — a lock_key is generated for every schedule |';
        $out[] = '| HTTP timeout | '.$noMaxTime.' scripts without `--max-time` | every template has `default_timeout_sec` & `default_connect_timeout_sec` |';
        $out[] = '| Credential source | `.sh` scripts + `.conf` + `opsifin_env.sh` | encrypted columns in the `clients` table |';
        $out[] = '';

        // --- Temuan ---
        $out[] = '## 2. Findings that need follow-up';
        $out[] = '';

        foreach ([FindingSeverity::Error, FindingSeverity::Warning, FindingSeverity::Info] as $severity) {
            $subset = $findings->where('severity', $severity);

            $out[] = '### '.ucfirst($severity->value).' ('.$subset->count().')';
            $out[] = '';

            if ($subset->isEmpty()) {
                $out[] = '_None._';
                $out[] = '';

                continue;
            }

            foreach ($subset->groupBy('category') as $category => $items) {
                $out[] = '#### '.(self::CATEGORY_LABELS[$category] ?? $category).' — '.$items->count().'x';
                $out[] = '';
                $out[] = '| # | Location | Detail |';
                $out[] = '| ---: | --- | --- |';

                foreach ($items->values() as $i => $item) {
                    $location = $item->source_file ?? '—';

                    if ($item->source_line) {
                        $location .= ':'.$item->source_line;
                    }

                    $out[] = '| '.($i + 1).' | `'.$location.'` | '.$this->escape($item->message).' |';
                }

                $out[] = '';
            }
        }

        // --- Client yang butuh review ---
        $needsReview = Client::where('needs_review', true)->orderBy('code')->get();

        $out[] = '## 3. Clients needing manual verification';
        $out[] = '';

        if ($needsReview->isEmpty()) {
            $out[] = '_None._';
        } else {
            $out[] = '| Client | Base URL | Config | Folder | Notes |';
            $out[] = '| --- | --- | --- | --- | --- |';

            foreach ($needsReview as $client) {
                $out[] = sprintf(
                    '| `%s` | %s | %s | %s | %s |',
                    $client->code,
                    $client->base_url,
                    $client->legacy_config_file ?: '—',
                    $client->legacy_script_dir ?: '—',
                    $this->escape((string) $client->review_notes) ?: '—',
                );
            }
        }

        $out[] = '';

        // --- Inventaris client ---
        $out[] = '## 4. Client inventory';
        $out[] = '';
        $out[] = '| Client | Name | Base URL | Auth | Schedules (enabled/total) | Overrides |';
        $out[] = '| --- | --- | --- | --- | ---: | ---: |';

        $clients = Client::withCount([
            'schedules',
            'schedules as active_schedules_count' => fn ($q) => $q->where('is_enabled', true),
            'overrides',
        ])->orderBy('code')->get();

        foreach ($clients as $client) {
            $out[] = sprintf(
                '| `%s` | %s | %s | %s | %d/%d | %d |',
                $client->code,
                $this->escape($client->name),
                $client->base_url,
                $client->auth_type->value.($client->auth_username ? ' ('.$client->auth_username.')' : ''),
                $client->active_schedules_count,
                $client->schedules_count,
                $client->overrides_count,
            );
        }

        $out[] = '';

        // --- Inventaris template ---
        $out[] = '## 5. Task template inventory';
        $out[] = '';
        $out[] = '| Key | Method | Path | Gateway | Schedules | Overrides | Legacy script |';
        $out[] = '| --- | --- | --- | :---: | ---: | ---: | --- |';

        $templates = TaskTemplate::withCount(['schedules', 'overrides'])->orderBy('key')->get();

        foreach ($templates as $template) {
            $out[] = sprintf(
                '| `%s` | %s | `%s` | %s | %d | %d | %s |',
                $template->key,
                $template->http_method->value,
                $template->path_template,
                $template->legacy_gateway_routed ? '✓' : '—',
                $template->schedules_count,
                $template->overrides_count,
                $template->legacy_script_names
                    ? implode(', ', array_map(fn ($n) => '`'.$n.'.sh`', $template->legacy_script_names))
                    : '—',
            );
        }

        $out[] = '';

        // --- Variasi path per template ---
        $out[] = '## 6. Path variations per template (§2.5 of the plan)';
        $out[] = '';
        $out[] = 'The rows below are clients that deviate from their template default path. ';
        $out[] = 'All of them are stored as `client_task_overrides`, but it still needs confirming that each deviation is intentional.';
        $out[] = '';

        $overrides = ClientTaskOverride::with(['client', 'taskTemplate'])
            ->whereNotNull('path_override')
            ->orderBy('task_template_id')
            ->get();

        if ($overrides->isEmpty()) {
            $out[] = '_None._';
        } else {
            $out[] = '| Client | Task | Default path | Override path |';
            $out[] = '| --- | --- | --- | --- |';

            foreach ($overrides as $override) {
                $out[] = sprintf(
                    '| `%s` | `%s` | `%s` | `%s` |',
                    $override->client?->code,
                    $override->taskTemplate?->key,
                    $override->taskTemplate?->path_template,
                    $override->path_override,
                );
            }
        }

        $out[] = '';
        $out[] = '## 7. Pre-cutover checklist';
        $out[] = '';
        $out[] = '- [ ] Every **error** finding in section 2 is resolved.';
        $out[] = '- [ ] Every client in section 3 has had its credentials verified (connection test).';
        $out[] = '- [ ] Every path override in section 6 is confirmed intentional.';
        $out[] = '- [ ] The number of enabled schedules matches the number of active entries in the production crontab.';
        $out[] = '- [ ] The 3–5 day shadow run is finished and the results compared.';
        $out[] = '';

        return implode("\n", $out);
    }

    private function percent(int $part, int $total): string
    {
        return $total === 0 ? '0%' : round($part / $total * 100).'%';
    }

    private function escape(string $text): string
    {
        return Str::of($text)->replace('|', '\\|')->replace("\n", ' ')->toString();
    }
}
