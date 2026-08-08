# Opsifin Cron

Aplikasi pengelola cron job Opsifin — menggantikan 478 file `.sh` dan 26 `.conf`
dengan database sebagai *source of truth*. Rencana lengkap ada di
[`PLAN_CRON_UI_REFACTOR.md`](PLAN_CRON_UI_REFACTOR.md).

**Status: Fase 1–3 selesai** — fondasi + importer legacy, runner & render crontab,
serta UI operasional. Berikutnya Fase 4 (alerting & observability).

---

## Stack

| Komponen | Versi |
| --- | --- |
| PHP | 8.5 (Laravel Herd) |
| Laravel | 13.x |
| Filament | 5.x |
| MySQL | 8.0 (Docker, port 3308) |

## Setup lokal (Laravel Herd)

```bash
# 1. Database
docker run -d --name opsifin-cron-mysql \
  -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=opsifin_cron \
  -p 3308:3306 --restart unless-stopped mysql:8.0

# 2. Dependency & konfigurasi
composer install
cp .env.example .env && php artisan key:generate
# isi CRON_SOURCE_PATH di .env dengan folder repo cron legacy

# 3. Skema & user
php artisan migrate --seed

# 4. Serve
herd link opsifin-crontab   # http://opsifin-crontab.test/admin
```

User seed (`database/seeders/UserSeeder.php`), password semuanya `password`:

| Email | Role | Kewenangan |
| --- | --- | --- |
| `admin@opsifin.local` | admin | CRUD data master + deploy crontab |
| `operator@opsifin.local` | operator | enable/disable schedule + run manual |
| `viewer@opsifin.local` | viewer | baca saja |

## Perintah

```bash
# Impor & verifikasi (Fase 1)
php artisan cron:import --dry-run     # parse & laporkan, tanpa menulis DB
php artisan cron:import --fresh       # impor ulang dari nol
php artisan cron:import --report=path # tentukan lokasi laporan rekonsiliasi
php artisan cron:verify-import        # bandingkan hasil impor dengan script legacy

# Eksekusi (Fase 2)
php artisan cron:run 142              # jalankan satu schedule
php artisan cron:run gn/repost        # boleh juga pakai <client>/<task>
php artisan cron:run gn/repost --dry-run   # tampilkan request tanpa mengirim

# Render & deploy crontab (Fase 2)
php artisan cron:render --validate    # cek semua schedule aktif
php artisan cron:render               # tulis staging + tampilkan diff
php artisan cron:render --apply       # deploy ke cron.d (backup otomatis)
php artisan cron:render --output=/tmp/x.cron --apply   # deploy ke path lain
php artisan cron:rollback --list      # daftar backup
php artisan cron:rollback             # kembalikan ke backup terakhir
```

Laporan rekonsiliasi default ditulis ke `storage/app/import-reports/`,
backup crontab ke `storage/app/crontab-backups/`.

## Panel admin

| Halaman | Isi |
| --- | --- |
| **Matrix** | Grid client × task, satu klik untuk menyalakan/mematikan, plus aktifkan/matikan satu baris atau satu kolom sekaligus |
| **Schedules** | Cron builder dengan terjemahan bahasa Indonesia + preview 5 jadwal berikutnya, `Run now`, `Dry run`, bulk enable/disable |
| **Runs** | Riwayat eksekusi read-only, filter per client/task/status/pemicu/rentang waktu, detail request–response |
| **Deploy crontab** | Validasi, diff, preview file, tombol deploy & rollback |
| **Clients** | CRUD, tes koneksi, kredensial terenkripsi |
| **Task templates** | Editor path/method/body/header + preview perintah `curl` yang dihasilkan |

## Cara lock bekerja

Ada dua lapis, sengaja memakai file berbeda:

1. **Baris crontab** dibungkus `flock -n <lock_key>.cron.lock` — mencegah proses
   PHP menumpuk kalau runner menggantung (mis. MySQL lambat). Murah, tidak butuh
   bootstrap Laravel.
2. **Di dalam runner** lock sebenarnya diambil pada `<lock_key>.lock`. Lapis ini
   yang mencatat status `skipped_lock` ke tabel `runs` sehingga terlihat di UI,
   dan yang membuat tombol "Run now" ikut menghormati lock.

Kalau keduanya memakai file yang sama, runner akan bentrok dengan `flock` induknya
sendiri — karena itu file-nya dipisah.

## Model data

```
clients ──┬── schedules ──── runs
          ├── client_task_overrides ──┐
task_templates ────────────────────────┘

import_runs ──── import_findings      audit_logs
```

- Kredensial (`auth_secret`, `auth_secret_key`) di-cast `encrypted` — tidak pernah
  tersimpan plaintext di DB.
- Setiap schedule wajib punya `lock_key`; `flockArguments()` menghasilkan `-n`
  (skip) atau `-w <detik>` (antre).
- Kolom `legacy_*` menyimpan asal-usul tiap baris agar bisa dibandingkan ulang
  saat shadow run Fase 2.
- Header per-client memakai placeholder: `{{client.secret_key}}`,
  `{{client.username}}`, `{{client.code}}` — diisi runner saat eksekusi.

## Cara kerja importer

`php artisan cron:import` membaca `CRON_SOURCE_PATH` (read-only, tidak pernah
menulis ke repo legacy) lalu:

1. `opsifin_env.sh` + `configs/*.conf` → variabel & kredensial.
2. `gateway.sh` → tabel routing task; `jobs/*.sh` → definisi endpoint gateway.
3. Semua `<client>/*.sh` → satu `ParsedCurl` per script.
4. Script dikelompokkan per nama, lalu grup dengan endpoint identik digabung
   menjadi `task_templates`. Path/method/body/host yang menyimpang dari mayoritas
   disimpan sebagai `client_task_overrides`.
5. `opsifin_crontab` → `schedules`, termasuk baris yang di-comment
   (`is_enabled = false`, `legacy_was_commented = true`).

Setiap ketidakcocokan tidak ditebak diam-diam melainkan dicatat sebagai
`import_findings` dan muncul di laporan rekonsiliasi.

## Hasil impor terakhir

| | |
| --- | ---: |
| Script `.sh` diparse | 476 |
| Clients | 40 |
| Task templates | 27 |
| Client task overrides | 81 |
| Schedules | 493 (236 aktif, 257 di-comment) |
| Verifikasi round-trip cocok persis | 383 dari 391 |

Temuan: 12 error, 81 warning, 42 info — rinciannya di laporan rekonsiliasi.

## Deploy ke server

1. Set `CRON_DEPLOY_BASE_DIR` ke lokasi aplikasi di server (default `/opt/opsifin-cron`),
   `CRON_PHP_BINARY`, `CRON_DEPLOY_USER`, `CRON_LOCK_DIR`, dan `CRON_LOG_DIR`.
2. Pastikan direktori lock dan log ada serta writable oleh user cron.
3. `php artisan cron:render --validate`, lalu `--apply`. File `cron.d` dibaca ulang
   otomatis oleh daemon cron; tidak perlu reload manual.

Baris manual di luar blok `# BEGIN/END OPSIFIN-CRON MANAGED BLOCK` tidak akan
tersentuh, sehingga crontab lama boleh hidup berdampingan selama masa migrasi.

## Selanjutnya

Fase 4 — alert rule engine (on_failure, on_timeout, N gagal berturut-turut,
missed run), channel Slack/email, dead man's switch, dashboard, dan purge
tabel `runs` > 90 hari. Lihat §5 di `PLAN_CRON_UI_REFACTOR.md`.
