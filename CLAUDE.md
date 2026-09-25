# CLAUDE.md

Panduan kerja untuk Claude Code pada repo **Opsifin Scheduler**.
Baca file ini lebih dulu, lalu [`docs/handoff.md`](docs/handoff.md) untuk state
pekerjaan terbaru dan [`docs/architecture.md`](docs/architecture.md) untuk desain.

## Aturan keras

Langgar salah satu dari ini dan data produksi/development bisa hilang:

1. **Jangan** `migrate:fresh`, `migrate:refresh`, `db:wipe`, atau `cron:import --fresh`
   pada database berisi data. Pernah terjadi kehilangan data (11 Sep 2026) dan
   MySQL binary log sedang OFF, jadi tidak ada point-in-time recovery.
   Untuk migration pending cukup `php artisan migrate`.
2. **Jangan** enable Schedule (`is_enabled=true`) tanpa persetujuan eksplisit user.
   Seluruh 470 schedule sengaja paused.
3. **Jangan** mengubah crontab legacy di `/home/aditya_prasetyo/project/crontab-legacy`.
   Folder itu read-only source untuk importer.
4. **Jangan** commit, push, atau deploy tanpa persetujuan user.
5. **Jangan** mengirim HTTP ke endpoint Client asli untuk pengujian. Pakai loopback
   fixture (`tests/Support/direct_http_fixture.py`). Smoke test ke endpoint nyata
   hanya setelah user menyetujui endpoint mana yang harmless.
6. **Jangan** menulis credential, URL ber-secret, isi `.env`, atau dump database
   ke dokumentasi, commit message, atau output yang dibagikan.
7. File di `storage/app/backups/` dan `storage/app/import-reports/` berisi data
   sensitif (credential plaintext). Jangan di-commit atau dibagikan.

## Perintah

PHP yang dipakai adalah **PHP 8.4**, bukan `php` default:

```bash
PHP=/www/server/php/84/bin/php
```

| Tujuan | Perintah |
| --- | --- |
| Test suite | `$PHP artisan test` (harapan: 120 passed, 447 assertions, 1 skipped) |
| Satu file test | `$PHP artisan test --filter=DirectPoolExecutorTest` |
| Capacity test opt-in | `DIRECT_HTTP_LOAD_RUNS=123 DIRECT_HTTP_LOAD_DELAY=5 DIRECT_HTTP_LOAD_CONCURRENCY=20 $PHP artisan test --filter=test_opt_in_capacity_scenario` |
| Code style | `$PHP vendor/bin/pint` (preset Laravel default, tanpa `pint.json`) |
| Cek style saja | `$PHP vendor/bin/pint --test` |
| Build aset | `npm run build` |
| Whitespace diff | `git diff --check` |
| Migration | `$PHP artisan migrate` |
| Status migration | `$PHP artisan migrate:status` |
| Dispatcher manual | `$PHP artisan jobs:dispatch-due` |
| Direct executor | `$PHP artisan jobs:work-direct [--once] [--max-seconds=60]` |
| Health direct | `$PHP artisan jobs:direct-status [--json]` |
| Browser regression | `npm run build && PHP_BINARY=$PHP PLAYWRIGHT_MODULE=... node tests/Browser/user-guide.cjs` |

Sebelum menyatakan pekerjaan selesai: **Pint + `artisan test` + `npm run build` +
`git diff --check`** harus lulus. Untuk perubahan tampilan User Guide/diagram,
build dan PHP test saja tidak cukup — jalankan regression Chromium.

## Arsitektur

```
system cron 1 menit → artisan schedule:run → jobs:dispatch-due → Run
   ├─ driver "queue"  : Redis → Horizon → ExecuteRun → RunWorker → HttpExecutor
   └─ driver "direct" : Run pending → jobs:work-direct (rolling cURL/Guzzle pool)
```

Data model: `clients 1—* schedules *—1 task_templates`, `schedules 1—* runs`.
Unik per `client_id + task_template_id + cron_expression`.

Prinsip yang tidak boleh dilanggar tanpa keputusan user baru:

- satu occurrence gagal bersifat **terminal**; tidak ada automatic retry;
- tidak ada catch-up occurrence yang terlewat;
- overlap dijaga slot atomic `schedules.running_run_id`, bukan file lock;
- concurrency direct wajib bounded (`CRON_DIRECT_CONCURRENCY`, default 20);
- response/error wajib melewati redaction credential sebelum disimpan;
- tidak ada incident engine, blackout, runtime override per client, atau watchdog
  internal. Folder `app/Filament/Resources/{Alerts,AlertRules,BlackoutWindows,
  ClientTaskOverrides,Incidents}` kosong — sisa arsitektur lama, jangan diisi ulang.

### File kunci

```text
routes/console.php                                 jadwal Laravel Scheduler
app/Console/Commands/DispatchDueJobsCommand.php    entry dispatcher
app/Console/Commands/WorkDirectRunsCommand.php     daemon direct
app/Services/Scheduling/DueScheduleDispatcher.php  materialisasi occurrence
app/Services/Scheduling/RunExecutionLifecycle.php  claim/validasi/overlap/completion (queue + direct)
app/Services/Scheduling/DirectPoolExecutor.php     rolling pool bounded
app/Services/Scheduling/DirectExecutorLease.php    lease DB, daemon kedua standby
app/Services/Scheduling/DirectExecutionHealth.php  metrik dan alert
app/Services/Execution/HttpExecutor.php            resolve + kirim HTTP (queue)
app/Services/Execution/DirectHttpTransport.php     transport async (direct)
config/opsifin_cron.php                            seluruh knob scheduler
```

## Status pekerjaan saat ini

Branch kerja utama adalah `master`. Migrasi Redis/Horizon → Direct Bounded HTTP
dan setup Docker development sudah di-merge dan di-push ke `origin/master`
(25 September 2026); branch `feat/direct-bounded-http-migration` sudah dihapus.

- Fase 0–4 rencana migrasi **selesai** dan tervalidasi lokal (peak 123 @ C=20 dan
  246 @ C=40, 0 missed window). Bukti di `docs/direct-http-validation.md`.
- Fase 5 (pilot + cutover production) dan Fase 6 (decommission Horizon)
  **belum dikerjakan**.
- Default deployment tetap `CRON_EXECUTION_DRIVER=queue` supaya rollback tersedia.
  `.env` development lokal memakai `direct`.
- Database development: 41 client, 20 template, 470 schedule (0 enabled), 5 run.
- aaPanel di WSL sudah di-uninstall (25 Sep 2026) dan cron `schedule:run`-nya
  mati. Development berjalan lewat Docker Compose (`docs/docker.md`); scheduler
  dan direct executor hanya aktif bila profile `workers` dinyalakan.

Pekerjaan berikutnya menurut handoff: ukur durasi endpoint dan resource pada
VPS/MySQL target, siapkan pilot dan cutover sesuai runbook, baru decommission
Redis/Horizon di release terpisah.

## Konfigurasi dan environment

Seluruh knob ada di `config/opsifin_cron.php`; tabel penjelasannya di
`docs/direct-http-operations.md`. Yang sering dipakai:

```text
CRON_EXECUTION_DRIVER        queue | direct   (restart proses setelah diubah)
CRON_DIRECT_CONCURRENCY      20               request aktif maksimum
CRON_DIRECT_START_WINDOW_SEC 55               wajib < 60
CRON_DIRECT_RESPONSE_MAX_BYTES 65536          prefix body di memori
CRON_RESPONSE_EXCERPT_LENGTH 2000             batas karakter di DB setelah redaction
```

`phpunit.xml` mem-pin `CRON_EXECUTION_DRIVER=queue` supaya test tidak ikut
berubah mengikuti `.env` developer. Test jalur direct meng-override sendiri
lewat `config([...])` atau env child process. Jangan menghapus pin ini.

```text
Project        /home/aditya_prasetyo/project/opsifin-crontab
Legacy source  /home/aditya_prasetyo/project/crontab-legacy
PHP            /www/server/php/84/bin/php
Panel URL      http://opsifin-cron.local/admin
Database       mysql opsifin_cron
Development    WSL2 + aaPanel
Production     VPS manual, Apache2 + PHP 8.4-FPM, user opsifin_admin, tanpa aaPanel
```

## Konvensi kode

- Ikuti gaya file di sekitarnya. Pint preset Laravel adalah formatter resmi.
- Komentar menjelaskan **kenapa**, bukan apa. Repo ini memakai komentar singkat
  satu-dua baris pada keputusan non-obvious (lihat `RunExecutionLifecycle`).
- Kode aplikasi, nama kolom, label UI, pesan error, dan komentar ditulis dalam
  **bahasa Inggris**. Dokumentasi di `docs/` ditulis dalam **bahasa Indonesia**.
- Panel memakai tampilan bawaan Filament. Jangan menambah restyle chrome
  (sidebar, topbar, stat card, layout login) atau `->colors()` di
  `AdminPanelProvider`; satu-satunya penyimpangan yang disengaja adalah ramp
  `--primary-*` yang ditukar appearance switcher di `theme.css`.
- Hati-hati menulis komentar CSS: urutan `*/` di tengah teks (misalnya
  `--info-*/--success-*`) menutup blok komentar lebih awal dan menelan rule
  berikutnya tanpa error build.
- Jangan menaruh state/method Alpine di atribut `x-data`/`x-init` Blade. Factory
  harus berada di file Blade terpisah sebagai `window.opsifinAppearance(...)`;
  browser menormalisasi newline dan pernah memecahkan implementasi sebelumnya.
- Setelah mengubah Blade di server, jalankan `artisan optimize:clear` lalu
  `artisan optimize`.
- Credential Client disimpan plaintext/as-is dan tidak bergantung `APP_KEY`.
  Secret disembunyikan dari serialisasi model; jangan menambahkannya ke log,
  exception message, atau Telescope.

## Dokumentasi

Source of truth ada di `docs/`. Index: [`docs/README.md`](docs/README.md).

| Kebutuhan | Dokumen |
| --- | --- |
| State pekerjaan lintas sesi | `docs/handoff.md` |
| Arsitektur ringkas | `docs/architecture.md` |
| Desain teknis end-to-end | `docs/artifact-teknis-opsifin-scheduler.md` |
| Rencana + checklist migrasi direct | `docs/direct-bounded-http-migration-plan.md` |
| Bukti pengujian direct | `docs/direct-http-validation.md` |
| Deployment/operasi/rollback direct | `docs/direct-http-operations.md` |
| Panduan pengguna UI | `docs/user-guide.md` + `docs/user-guide/*.md` |
| Deployment VPS | `docs/deployment-vps.md`, `docs/database-migration-vps.md` |
| Runbook umum | `docs/operations.md` |

Aturan maintenance dokumen ada di `docs/README.md`. Perubahan label/field/action
UI memperbarui file module terkait; perubahan lifecycle/driver/data model
memperbarui Artifact Teknis dan Architecture.

`PLAN_CRON_UI_REFACTOR.md` di root adalah draft 3 Agustus 2026 dan sudah
digantikan dokumen di `docs/`. Jangan dipakai sebagai sumber instruksi.

## Setelah menyelesaikan sesi

Perbarui `docs/handoff.md`: apa yang berubah, hasil verifikasi beserta tanggal
dan batasnya, serta apa yang belum dikerjakan. Handoff adalah memory utama
lintas sesi, jadi tulis fakta yang terverifikasi saja — jangan klaim hasil test
atau cutover yang belum benar-benar dijalankan.
