# Rujukan Teknis

Daftar lengkap perintah, konfigurasi, tabel, dan enum. Untuk memahami cara
kerjanya, baca [`architecture.md`](architecture.md).

---

## 1. Perintah artisan

### `cron:import` — impor repo cron legacy

```bash
php artisan cron:import [--source=PATH] [--dry-run] [--fresh] [--report=PATH]
```

| Opsi | Arti |
| --- | --- |
| `--source=` | Folder repo legacy. Default `CRON_SOURCE_PATH` |
| `--dry-run` | Parse dan laporkan saja; seluruh perubahan database di-rollback |
| `--fresh` | Kosongkan clients/task_templates/overrides/schedules sebelum impor |
| `--report=` | Lokasi laporan rekonsiliasi. Default `storage/app/import-reports/` |

Tidak pernah menulis ke repo legacy. `--fresh` memakai `DELETE`, bukan
`TRUNCATE`, karena `TRUNCATE` memicu implicit commit di MySQL dan akan membatalkan
efek `--dry-run`.

### `cron:verify-import` — bandingkan hasil impor dengan script asli

```bash
php artisan cron:verify-import [--source=PATH] [--limit=0]
```

Membaca ulang setiap `.sh` legacy dan membandingkan URL, method, body,
Authorization, dan SecretKey dengan yang tersimpan di database. Keluar dengan
status gagal bila ada perbedaan.

### `cron:render` — susun & deploy crontab

```bash
php artisan cron:render [--validate] [--apply] [--output=PATH] [--show]
```

| Opsi | Arti |
| --- | --- |
| `--validate` | Cek semua schedule aktif, tidak menulis apa pun |
| `--apply` | Tulis ke file `cron.d` dengan backup otomatis. **Butuh `sudo` di VPS** |
| `--output=` | Tulis ke path lain, bukan file `cron.d` sebenarnya |
| `--show` | Tampilkan isi lengkap hasil render |

Tanpa `--apply`, hasilnya ditulis ke `storage/app/crontab-staging/opsifin.cron`
dan diff-nya ditampilkan.

### `cron:rollback` — kembalikan crontab

```bash
php artisan cron:rollback [--backup=PATH] [--output=PATH] [--list]
```

Isi file saat ini di-backup lebih dulu, jadi rollback bisa dibatalkan lagi.

### `cron:run` — jalankan satu schedule

```bash
php artisan cron:run <schedule> [--trigger=cron] [--dry-run]
```

`<schedule>` boleh berupa ID atau `<client_code>/<task_key>`.
`--trigger` menerima `cron`, `manual`, atau `shadow` — hanya label yang dicatat
di tabel `runs`.

`--dry-run` menampilkan request final tanpa memanggil endpoint. Kredensial
disamarkan pada baris yang tersimpan di `runs`.

### `cron:check-missed` — cari job yang tidak jalan

```bash
php artisan cron:check-missed
```

Dijalankan penjadwal Laravel tiap 5 menit. Menilai rule berkondisi `missed_run`
terhadap seluruh schedule yang lolos tiga gerbang.

### `cron:purge-runs` — retensi riwayat

```bash
php artisan cron:purge-runs [--days=N] [--dry-run] [--chunk=1000]
```

Default `CRON_RUNS_RETENTION_DAYS` (90). Menghapus baris `runs` dan alert yang
**sudah ditutup**; alert yang masih `open` tidak pernah dihapus. Penghapusan
dilakukan bertahap supaya tidak mengunci tabel lama-lama.

### `cron:cutover-status` — kesiapan migrasi per client

```bash
php artisan cron:cutover-status [client] [--ready] [--blocked]
```

Sebuah client dianggap siap bila: tidak ada error impor yang belum diselesaikan,
tidak ada penanda `needs_review`, kredensialnya lengkap, dan punya minimal satu
schedule. Beri kode client untuk melihat daftar blocker-nya.

### Lain-lain

```bash
php artisan schedule:list      # lihat pekerjaan internal aplikasi
php artisan migrate --force    # jalankan migrasi di produksi
```

---

## 2. Variabel lingkungan

| Variabel | Default | Keterangan |
| --- | --- | --- |
| `APP_KEY` | — | **Mengenkripsi kredensial client.** Kalau hilang, semua kredensial tidak bisa dipulihkan |
| `APP_LOCALE` | `en` | Harus `en`. Nilai `id` membuat label bawaan Laravel/Filament berbahasa Indonesia |
| `CRON_SOURCE_PATH` | — | Folder repo cron legacy. Dibaca read-only oleh `cron:import` |
| `CRON_DEPLOY_BASE_DIR` | `/opt/opsifin-cron` | Lokasi aplikasi di server; dipakai menyusun path `artisan` di baris crontab |
| `CRON_DEPLOY_USER` | `ubuntu` | User yang menjalankan job di baris crontab |
| `CRON_D_FILE` | `/etc/cron.d/opsifin` | File target deploy |
| `CRON_PHP_BINARY` | `/usr/bin/php` | Binary PHP di baris crontab |
| `CRON_FLOCK_BINARY` | `/usr/bin/flock` | Binary flock di baris crontab |
| `CRON_LOCK_DIR` | `/var/lock/opsifin` | Direktori lock. Harus milik `CRON_DEPLOY_USER` |
| `CRON_LOG_DIR` | `/var/log/opsifin-cron` | Tujuan `runner.log` |
| `CRON_DEFAULT_TIMEZONE` | `Asia/Jakarta` | Default timezone schedule baru |
| `CRON_RUNS_RETENTION_DAYS` | `90` | Umur maksimum riwayat run |

Nilai default lain ada di `config/opsifin_cron.php`:

```php
'defaults' => [
    'timeout_sec'         => 60,
    'connect_timeout_sec' => 10,
    'retries'             => 0,
    'lock_mode'           => 'skip',
],
'response_excerpt_length' => 2000,
```

---

## 3. Tabel database

### `clients`

`id`, `code`, `name`, `base_url`, `timezone`, `is_active`, `auth_type`,
`auth_username`, `auth_secret`, `auth_secret_key`, `legacy_config_file`,
`legacy_script_dir`, `needs_review`, `review_notes`, `notes`, `created_at`,
`updated_at`

`auth_secret` dan `auth_secret_key` di-cast `encrypted`.

### `task_templates`

`id`, `key`, `name`, `description`, `http_method`, `path_template`,
`body_template`, `headers`, `default_timeout_sec`,
`default_connect_timeout_sec`, `default_retries`, `is_active`,
`legacy_gateway_routed`, `legacy_job_file`, `legacy_script_names`,
`needs_review`, `review_notes`, `created_at`, `updated_at`

`headers` berupa JSON; nilainya boleh memakai placeholder
`{{client.secret_key}}`, `{{client.username}}`, `{{client.code}}`.

### `client_task_overrides`

`id`, `client_id`, `task_template_id`, `method_override`, `path_override`,
`body_override`, `headers_override`, `timeout_override`,
`connect_timeout_override`, `base_url_override`, `legacy_script_file`, `notes`,
`created_at`, `updated_at`

### `schedules`

`id`, `client_id`, `task_template_id`, `cron_expression`, `timezone`,
`lock_key`, `lock_mode`, `lock_wait_sec`, `is_enabled`, `catchup_policy`,
`last_run_at`, `next_run_at`, `legacy_pattern`, `legacy_line_no`,
`legacy_command`, `legacy_was_commented`, `legacy_had_flock`,
`legacy_lock_file`, `needs_review`, `review_notes`, `created_by`, `updated_by`,
`created_at`, `updated_at`

Unik pada `(client_id, task_template_id, cron_expression)`.
`next_run_at` disimpan dalam timezone aplikasi (UTC).

### `runs`

`id`, `schedule_id`, `client_id`, `task_template_id`, `trigger`, `status`,
`attempt`, `started_at`, `finished_at`, `duration_ms`, `request_method`,
`request_url`, `http_status`, `response_excerpt`, `error_message`, `host`,
`created_at`, `updated_at`

`client_id` dan `task_template_id` didenormalisasi agar riwayat tetap terbaca
setelah schedule dihapus.

### `alert_rules`

`id`, `name`, `condition`, `client_id`, `task_template_id`, `schedule_id`,
`threshold`, `grace_minutes`, `cooldown_minutes`, `is_active`, `notes`,
`created_at`, `updated_at`

Kolom cakupan yang `null` berarti "semua".

### `alerts`

`id`, `alert_rule_id`, `schedule_id`, `client_id`, `task_template_id`, `run_id`,
`condition`, `status`, `title`, `body`, `fired_at`, `acknowledged_at`,
`acknowledged_by`, `resolved_at`, `created_at`, `updated_at`

`run_id` kosong untuk alert `missed_run` — memang tidak ada run yang terjadi.

### `import_runs` / `import_findings`

`import_runs`: `id`, `source_path`, `started_at`, `finished_at`, `stats`,
`dry_run`, `user_id`, `created_at`, `updated_at`

`import_findings`: `id`, `import_run_id`, `severity`, `category`, `source_file`,
`source_line`, `message`, `context`, `resolved`, `created_at`, `updated_at`

### `audit_logs`

`id`, `user_id`, `action`, `entity_type`, `entity_id`, `before`, `after`, `ip`,
`created_at`

Mencatat `crontab_deployed` dan `crontab_rollback`.

### `users`

`id`, `name`, `email`, `role`, `is_active`, `email_verified_at`, `password`,
`remember_token`, `created_at`, `updated_at`

---

## 4. Enum

| Enum | Nilai |
| --- | --- |
| `AuthType` | `basic`, `bearer`, `none` |
| `HttpMethod` | `GET`, `POST`, `PUT`, `PATCH`, `DELETE` |
| `LockMode` | `skip` (flock `-n`), `wait` (flock `-w <detik>`) |
| `RunStatus` | `running`, `success`, `failed`, `timeout`, `skipped_lock`, `skipped_disabled` |
| `RunTrigger` | `cron`, `manual`, `shadow`, `dry_run` |
| `AlertCondition` | `on_failure`, `on_timeout`, `consecutive_failures`, `missed_run` |
| `AlertStatus` | `open`, `acknowledged`, `resolved` |
| `FindingSeverity` | `info`, `warning`, `error` |
| `LegacyPattern` | `direct_script`, `gateway`, `manual` |
| `UserRole` | `admin`, `operator`, `viewer` |

---

## 5. Kategori temuan importer

| Kategori | Arti |
| --- | --- |
| `credential_drift` | Kredensial berbeda antar sumber |
| `cross_client_host` | Script menembak host milik client lain |
| `host_mismatch` | Host berbeda dari base URL client |
| `base_url_conflict` | Base URL config bertentangan dengan folder script |
| `gateway_route_missing_file` | Routing gateway menunjuk file yang tidak ada |
| `gateway_route_remapped` | Routing dipetakan ulang otomatis berdasarkan kemiripan nama |
| `gateway_task_unknown` | Task gateway tidak dikenali |
| `gateway_client_unknown` | Client gateway tanpa file config |
| `job_not_routed` | Job ada di `jobs/` tapi tidak pernah dirouting |
| `job_no_curl` / `script_no_curl` | File tidak berisi perintah curl |
| `script_missing` | Crontab memanggil script yang tidak ada |
| `script_not_in_client_folder` | Script tidak ada di folder client-nya |
| `script_url_unresolved` | URL tidak bisa diresolusi |
| `script_url_dangling` | URL di baris terpisah — curl jalan tanpa URL |
| `client_folder_missing` | Crontab memanggil folder client yang tidak ada |
| `script_without_template` | Script tidak terpetakan ke template mana pun |
| `suspicious_interval` | Interval cron kemungkinan salah tafsir |
| `invalid_cron_expression` | Ekspresi cron tidak valid |
| `duplicate_schedule` | Schedule duplikat di crontab |
| `template_merged` | Template digabung karena endpoint identik |
| `override_collision` | Dua script client untuk task yang sama, konfigurasi berbeda |
| `conf_no_url` | Config tanpa `API_URL` |
| `conf_matches_secondary_host` | Config cocok dengan host sekunder folder |
| `not_a_job` | Baris cron bukan job Opsifin |

---

## 6. Lokasi berkas

| Isi | Path |
| --- | --- |
| Laporan rekonsiliasi | `storage/app/import-reports/` |
| Backup crontab | `storage/app/crontab-backups/` |
| Staging crontab | `storage/app/crontab-staging/opsifin.cron` |
| Log runner | `/var/log/opsifin-cron/runner.log` |
| Lock file | `/var/lock/opsifin/<lock_key>.lock` dan `.cron.lock` |
| File crontab hasil deploy | `/etc/cron.d/opsifin` |

---

## 7. Pengembangan

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed        # seeder membuat 3 user contoh, password: password
npm install && npm run dev

./vendor/bin/pint                 # format kode
php artisan test                  # 90 test
```

Test memakai SQLite in-memory (`phpunit.xml`), terisolasi penuh dari database
pengembangan.

**Konvensi bahasa:** seluruh teks yang dilihat pengguna berbahasa **Inggris** —
label, notifikasi, pesan exception, output artisan, laporan rekonsiliasi.
Komentar dan docblock di dalam kode tetap **bahasa Indonesia**.

**Setelah mengubah blade atau kelas di `app/Filament/`**, jalankan
`npm run build` — theme Filament di-compile dari sumber-sumber itu.
