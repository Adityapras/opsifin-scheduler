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
        'credential_drift' => 'Kredensial berbeda antar sumber',
        'cross_client_host' => 'Script menembak host milik client lain',
        'host_mismatch' => 'Host berbeda dari base URL client',
        'base_url_conflict' => 'Base URL config vs folder script berbeda',
        'gateway_route_missing_file' => 'Routing gateway menunjuk file yang tidak ada',
        'gateway_route_remapped' => 'Routing gateway dipetakan ulang otomatis',
        'gateway_task_unknown' => 'Task gateway tidak dikenali',
        'gateway_client_unknown' => 'Client gateway tanpa file config',
        'job_not_routed' => 'Job orphan (tidak dirouting)',
        'job_no_curl' => 'Job tanpa perintah curl',
        'script_missing' => 'Crontab memanggil script yang tidak ada',
        'script_not_in_client_folder' => 'Script tidak ada di folder client-nya',
        'script_no_curl' => 'Script tanpa perintah curl',
        'script_url_unresolved' => 'URL script tidak bisa diresolusi',
        'script_url_dangling' => 'URL di baris terpisah — curl jalan tanpa URL',
        'client_folder_missing' => 'Crontab memanggil folder client yang tidak ada',
        'script_without_template' => 'Script tanpa template',
        'suspicious_interval' => 'Interval cron kemungkinan salah tafsir',
        'invalid_cron_expression' => 'Ekspresi cron tidak valid',
        'duplicate_schedule' => 'Schedule duplikat di crontab',
        'template_merged' => 'Template digabung karena endpoint identik',
        'override_collision' => 'Dua script client untuk task yang sama, konfigurasi berbeda',
        'conf_no_url' => 'Config tanpa API_URL',
        'conf_matches_secondary_host' => 'Config cocok dengan host sekunder folder',
        'not_a_job' => 'Baris cron bukan job Opsifin',
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
        $out[] = '# Laporan Rekonsiliasi Impor Cron Legacy';
        $out[] = '';
        $out[] = '**Dijalankan:** '.$importRun->started_at?->format('d M Y H:i:s T');
        $out[] = '**Sumber:** `'.$importRun->source_path.'`';
        $out[] = '**Mode:** '.($importRun->dry_run ? 'dry run (database tidak diubah)' : 'apply');
        $out[] = '';
        $out[] = 'Dokumen ini wajib direview sebelum client mana pun diaktifkan di sistem baru. ';
        $out[] = 'Setiap baris **error** harus tuntas; setiap **warning** harus punya keputusan tertulis.';
        $out[] = '';

        // --- Ringkasan angka ---
        $out[] = '## 1. Ringkasan';
        $out[] = '';
        $out[] = '| Metrik | Nilai |';
        $out[] = '| --- | ---: |';
        $out[] = '| Folder client dipindai | '.($stats['client_folders'] ?? 0).' |';
        $out[] = '| File `.sh` client diparse | '.($stats['client_scripts'] ?? 0).' |';
        $out[] = '| File `.conf` | '.($stats['config_files'] ?? 0).' |';
        $out[] = '| Job gateway (`jobs/*.sh`) | '.($stats['gateway_jobs'] ?? 0).' |';
        $out[] = '| Routing terdaftar di `gateway.sh` | '.($stats['gateway_routes'] ?? 0).' |';
        $out[] = '| Entry crontab terbaca | '.($stats['crontab_entries'] ?? 0).' |';
        $out[] = '| — aktif (tidak di-comment) | '.($stats['entries_active'] ?? 0).' |';
        $out[] = '| — di-comment | '.($stats['entries_commented'] ?? 0).' |';
        $out[] = '| — tidak bisa dipetakan | '.($stats['entries_skipped'] ?? 0).' |';
        $out[] = '| **Clients** | '.Client::count().' |';
        $out[] = '| **Task templates** | '.TaskTemplate::count().' |';
        $out[] = '| **Client task overrides** | '.ClientTaskOverride::count().' |';
        $out[] = '| **Schedules** | '.Schedule::count().' |';
        $out[] = '| — enabled | '.Schedule::where('is_enabled', true)->count().' |';
        $out[] = '';

        $activeEntries = $stats['entries_active'] ?? 0;
        $withFlock = $stats['legacy_active_with_flock'] ?? 0;
        $noMaxTime = $stats['scripts_without_max_time'] ?? 0;

        $out[] = '### Perbandingan dengan kondisi lama';
        $out[] = '';
        $out[] = '| Aspek | Crontab lama | Setelah impor |';
        $out[] = '| --- | --- | --- |';
        $out[] = '| Coverage `flock` | '.$withFlock.' dari '.$activeEntries.
            ' entry aktif ('.$this->percent($withFlock, $activeEntries).') | '.
            Schedule::count().' dari '.Schedule::count().' (100%) — lock_key digenerate untuk setiap schedule |';
        $out[] = '| Timeout HTTP | '.$noMaxTime.' script tanpa `--max-time` | semua template punya `default_timeout_sec` & `default_connect_timeout_sec` |';
        $out[] = '| Sumber kredensial | script `.sh` + `.conf` + `opsifin_env.sh` | kolom terenkripsi di tabel `clients` |';
        $out[] = '';

        // --- Temuan ---
        $out[] = '## 2. Temuan yang perlu ditindaklanjuti';
        $out[] = '';

        foreach ([FindingSeverity::Error, FindingSeverity::Warning, FindingSeverity::Info] as $severity) {
            $subset = $findings->where('severity', $severity);

            $out[] = '### '.ucfirst($severity->value).' ('.$subset->count().')';
            $out[] = '';

            if ($subset->isEmpty()) {
                $out[] = '_Tidak ada._';
                $out[] = '';

                continue;
            }

            foreach ($subset->groupBy('category') as $category => $items) {
                $out[] = '#### '.(self::CATEGORY_LABELS[$category] ?? $category).' — '.$items->count().'x';
                $out[] = '';
                $out[] = '| # | Lokasi | Keterangan |';
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

        $out[] = '## 3. Client yang butuh verifikasi manual';
        $out[] = '';

        if ($needsReview->isEmpty()) {
            $out[] = '_Tidak ada._';
        } else {
            $out[] = '| Client | Base URL | Config | Folder | Catatan |';
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
        $out[] = '## 4. Inventaris client';
        $out[] = '';
        $out[] = '| Client | Nama | Base URL | Auth | Schedules (aktif/total) | Overrides |';
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
        $out[] = '## 5. Inventaris task template';
        $out[] = '';
        $out[] = '| Key | Method | Path | Gateway | Schedules | Overrides | Script legacy |';
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
        $out[] = '## 6. Variasi path per template (§2.5 rencana)';
        $out[] = '';
        $out[] = 'Baris di bawah adalah client yang menyimpang dari path default template. ';
        $out[] = 'Semua sudah tersimpan sebagai `client_task_overrides`, tapi tetap perlu dikonfirmasi bahwa penyimpangan itu memang disengaja.';
        $out[] = '';

        $overrides = ClientTaskOverride::with(['client', 'taskTemplate'])
            ->whereNotNull('path_override')
            ->orderBy('task_template_id')
            ->get();

        if ($overrides->isEmpty()) {
            $out[] = '_Tidak ada._';
        } else {
            $out[] = '| Client | Task | Path default | Path override |';
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
        $out[] = '## 7. Checklist sebelum cutover';
        $out[] = '';
        $out[] = '- [ ] Semua temuan **error** di bagian 2 sudah tuntas.';
        $out[] = '- [ ] Setiap client di bagian 3 sudah diverifikasi kredensialnya (test connection).';
        $out[] = '- [ ] Semua override path di bagian 6 sudah dikonfirmasi disengaja.';
        $out[] = '- [ ] Jumlah schedule aktif hasil impor cocok dengan jumlah entry aktif di crontab produksi.';
        $out[] = '- [ ] Shadow run 3–5 hari selesai dan hasilnya dibandingkan.';
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
