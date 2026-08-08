# Opsifin Cron

Aplikasi pengelola cron job Opsifin. Menggantikan 478 file `.sh` dan 26 `.conf`
dengan **database sebagai satu-satunya sumber kebenaran** — sementara **cron OS
tetap yang mengeksekusi**, sehingga risiko migrasinya rendah dan rollback-nya
satu perintah.

```
  DATABASE  ──render──►  /etc/cron.d/opsifin  ──trigger──►  cron daemon
     ▲                                                          │
     └────────────── php artisan cron:run <id> ◄────────────────┘
```

---

## Mulai dari mana

| Kalau Anda ingin… | Baca |
| --- | --- |
| Memahami cara kerjanya | [`docs/architecture.md`](docs/architecture.md) |
| Memasang di VPS dari nol | [`docs/installation.md`](docs/installation.md) |
| Memakai panel adminnya | [`docs/user-guide.md`](docs/user-guide.md) |
| Memindahkan client dari crontab lama | [`docs/cutover.md`](docs/cutover.md) |
| Menangani incident / pemeliharaan | [`docs/operations.md`](docs/operations.md) |
| Mencari perintah, kolom, atau konfigurasi | [`docs/reference.md`](docs/reference.md) |
| Melihat rencana & audit aslinya | [`PLAN_CRON_UI_REFACTOR.md`](PLAN_CRON_UI_REFACTOR.md) |
| **Membaca usulan arsitektur berikutnya** | [`docs/architecture-v2.md`](docs/architecture-v2.md) |

---

## Status

| Fase | Isi | Status |
| --- | --- | --- |
| 0 | Stabilisasi darurat crontab produksi | **belum dikerjakan** |
| 1 | Fondasi aplikasi + importer legacy | selesai |
| 2 | Runner & render crontab | selesai |
| 3 | UI operasional | selesai |
| 4 | Alerting & observability | selesai |
| 5 | Migrasi & pembersihan | perkakas & dokumen siap, eksekusi belum |

90 test lolos. Dua hal yang sengaja belum ada:

- **Channel alert keluar.** Alert mengendap di tabel `alerts` dan lonceng
  notifikasi. Titik tambahnya sudah disiapkan di `AlertDispatcher::deliver()`.
- **Pemantau di luar server** (mis. Healthchecks.io). `cron:check-missed`
  mendeteksi cron mati, tapi berjalan di dalam VPS yang sama — kalau seluruh VPS
  mati, tidak ada yang melapor.

---

## Stack

| Komponen | Versi |
| --- | --- |
| PHP | 8.3+ |
| Laravel | 13.x |
| Filament | 5.x |
| MySQL | 8.0 |

---

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

# 3. Skema & user contoh
php artisan migrate --seed

# 4. Asset — wajib, theme Filament di-compile dari app/Filament dan resources/views/filament
npm install && npm run build

# 5. Serve
herd link opsifin-crontab   # http://opsifin-crontab.test/admin
```

User seed (`database/seeders/UserSeeder.php`), password semuanya `password` —
**jangan dipakai di produksi**:

| Email | Role |
| --- | --- |
| `admin@opsifin.local` | admin |
| `operator@opsifin.local` | operator |
| `viewer@opsifin.local` | viewer |

---

## Perintah yang paling sering dipakai

```bash
# Impor legacy
php artisan cron:import --dry-run      # parse & laporkan, tanpa menulis DB
php artisan cron:verify-import         # bandingkan hasil impor dengan script asli

# Eksekusi
php artisan cron:run gn/repost --dry-run   # tampilkan request tanpa mengirim
php artisan cron:run gn/repost             # jalankan sekarang

# Render & deploy
php artisan cron:render --validate     # cek semua schedule aktif
sudo php artisan cron:render --apply    # deploy ke cron.d (backup otomatis)
sudo php artisan cron:rollback          # kembalikan ke backup terakhir

# Migrasi & pemeliharaan
php artisan cron:cutover-status         # kesiapan cutover per client
php artisan cron:check-missed           # cari job yang seharusnya jalan tapi tidak
php artisan cron:purge-runs --dry-run   # lihat berapa baris yang akan dibersihkan
```

Daftar lengkap beserta opsinya ada di
[`docs/reference.md`](docs/reference.md) §1.

---

## Panel admin

| Halaman | Isi |
| --- | --- |
| **Dashboard** | Success rate 24 jam, schedule terlambat, kesehatan per client, task terlambat |
| **Matrix** | Grid client × task; klik sel untuk menyalakan/mematikan, menu ⋮ untuk satu baris atau satu kolom sekaligus |
| **Schedules** | Cron builder dengan preview 5 jadwal berikutnya, `Run now`, `Dry run`, bulk enable/disable |
| **Runs** | Riwayat eksekusi read-only, filter per client/task/status/pemicu/rentang waktu |
| **Alerts** | Alert yang terbit, dengan alur acknowledge → resolve |
| **Deploy crontab** | Validasi, diff, preview file, daftar backup |
| **Clients** | CRUD, tes koneksi, kredensial terenkripsi |
| **Task templates** | Editor path/method/body/header + preview perintah `curl` |
| **Alert rules** | Kapan alert berbunyi, dengan cakupan dan cooldown |

---

## Tiga hal yang paling sering menjebak

1. **Perubahan di panel tidak berlaku sampai crontab di-deploy.** Menekan toggle
   hanya mengubah baris database.
2. **Sebuah job berjalan hanya kalau tiga gerbang aktif semua** —
   `schedules.is_enabled`, `clients.is_active`, dan `task_templates.is_active`.
3. **Tombol Deploy di panel tidak berfungsi di VPS**, dan itu memang
   diharapkan — cron menolak file `/etc/cron.d` yang tidak dimiliki root.
   Penulisan lewat SSH dengan `sudo`. Lihat
   [`docs/installation.md`](docs/installation.md) §9.2.

---

## Hasil impor terakhir

| | |
| --- | ---: |
| Script `.sh` diparse | 476 |
| Clients | 40 |
| Task templates | 27 |
| Client task overrides | 81 |
| Schedules | 493 |
| Verifikasi round-trip cocok persis | 383 dari 391 |

Temuan: 12 error, 81 warning, 42 info. Rinciannya di laporan rekonsiliasi
(`storage/app/import-reports/`). **Client tidak boleh diaktifkan sebelum error
yang menyangkutnya diselesaikan** — `php artisan cron:cutover-status`
menunjukkan mana yang masih terblokir.

---

## Kontribusi

```bash
./vendor/bin/pint     # format kode
php artisan test      # 90 test, SQLite in-memory
npm run build         # setelah mengubah blade atau kelas di app/Filament
```

Seluruh teks yang dilihat pengguna berbahasa **Inggris**; komentar dan docblock
di dalam kode berbahasa **Indonesia**. Itu keputusan sadar, bukan sisa yang
belum diterjemahkan.
