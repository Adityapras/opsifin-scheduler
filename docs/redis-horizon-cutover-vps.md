# Cutover Database Queue ke Redis dan Laravel Horizon

Dokumen ini adalah runbook untuk memindahkan Opsifin Scheduler yang sudah
berjalan di VPS dari Laravel database queue ke Redis Queue dan Laravel Horizon.
Prosedur ini bukan instalasi aplikasi dari awal.

## 1. Target akhir

Setelah cutover selesai:

- data aplikasi tetap disimpan di MySQL;
- session tetap menggunakan MySQL;
- cache Laravel tetap menggunakan MySQL;
- failed jobs tetap dicatat di MySQL;
- payload queue disimpan di Redis;
- metadata dan metrics Horizon disimpan di Redis;
- Horizon dikelola oleh Supervisor;
- Laravel Scheduler tetap dipanggil satu kali setiap menit oleh system cron;
- dashboard Horizon tersedia di `/horizon` hanya untuk Administrator aktif.

Arsitektur akhirnya:

```text
System cron
    |
    v
Laravel Scheduler -> membuat Run di MySQL
    |
    v
Redis Queue -> Horizon/Supervisor -> ExecuteRun -> endpoint Client
                   |
                   v
             status dan log di MySQL
```

Redis logical database yang digunakan:

| Redis DB | Kegunaan |
| --- | --- |
| DB 0 | Metadata, metrics, dan status internal Horizon |
| DB 2 | Payload queue `default` |

Redis DB tersebut bukan pengganti database MySQL. Redis hanya dipakai oleh
subsistem queue dan Horizon.

## 2. Variabel deployment

Isi nilai berikut sebelum deployment:

| Variabel | Contoh | Nilai VPS |
| --- | --- | --- |
| Project path | `/var/www/opsifin-scheduler` | |
| App user | `opsifin_admin` | |
| PHP binary | `/usr/bin/php8.4` | |
| Database | `opsifin_scheduler` | |
| Database user | `opsifin_scheduler` | |
| Supervisor worker lama | `opsifin-scheduler-worker` | |
| Release commit/tag | `v1.1.0` | |
| Backup directory | `/secure-backup/opsifin-scheduler` | |
| PIC deployment | | |
| PIC rollback | | |

Command dalam dokumen ini menggunakan contoh:

```text
Project path : /var/www/opsifin-scheduler
App user     : opsifin_admin
PHP binary   : php8.4
```

Sesuaikan jika VPS menggunakan path atau binary yang berbeda. Untuk aaPanel,
PHP binary biasanya seperti `/www/server/php/84/bin/php`.

## 3. Persiapan resource dan akses

Siapkan sebelum maintenance window:

- akses `sudo` atau root ke VPS;
- maintenance window sekitar 15 sampai 30 menit;
- backup MySQL yang sudah diverifikasi;
- Redis Server dan `redis-cli`;
- Supervisor;
- commit atau release tag yang akan di-deploy;
- satu Schedule dengan endpoint aman untuk smoke test;
- akses Administrator ke aplikasi;
- monitoring CPU, RAM, disk, Redis, Horizon, dan failed jobs.

Baseline resource awal yang disarankan:

- 2 vCPU;
- RAM 4 GB;
- disk SSD dengan ruang untuk MySQL, Redis AOF, log, dan backup;
- Horizon minimum 2 worker dan maksimum 10 worker.

Jika VPS hanya memiliki RAM 2 GB, gunakan konfigurasi lebih kecil:

```dotenv
HORIZON_MIN_PROCESSES=2
HORIZON_MAX_PROCESSES=4
```

Jangan membuka port Redis `6379` ke internet. Redis sebaiknya hanya listen di
localhost jika Laravel dan Redis berjalan pada VPS yang sama.

## 4. Commit dan release aplikasi

Perubahan Redis dan Horizon harus sudah di-commit dan push sebelum deployment.
Catat commit yang akan dipakai:

```bash
git status
git log -1 --oneline
```

Untuk production, gunakan commit atau release tag yang eksplisit. Jangan deploy
dari working tree yang masih memiliki perubahan lokal.

## 5. Install Redis Server

Untuk Ubuntu atau Debian:

```bash
sudo apt update
sudo apt install redis-server redis-tools supervisor
sudo systemctl enable redis-server supervisor
sudo systemctl start redis-server supervisor
```

Edit konfigurasi Redis:

```bash
sudo nano /etc/redis/redis.conf
```

Pastikan minimal memiliki konfigurasi berikut:

```conf
bind 127.0.0.1 ::1
protected-mode yes

appendonly yes
appendfsync everysec

maxmemory-policy noeviction
```

Gunakan password atau ACL jika diwajibkan kebijakan server. Jangan memasukkan
password Redis ke repository.

Restart dan periksa Redis:

```bash
sudo systemctl restart redis-server
sudo systemctl status redis-server --no-pager
redis-cli ping
```

Hasil yang diharapkan:

```text
PONG
```

Project menggunakan `predis/predis`, sehingga extension PhpRedis tidak wajib
untuk cutover pertama. Predis tetap membutuhkan Redis Server yang aktif.

## 6. Backup sebelum cutover

Buat nama backup yang menyertakan tanggal dan waktu deployment. Contoh:

```bash
mysqldump --single-transaction --routines --triggers --no-tablespaces \
  -u <db-user> -p <database> \
  > /secure-backup/opsifin-scheduler/before-redis-cutover.sql
```

Backup juga file konfigurasi runtime:

```bash
cd /var/www/opsifin-scheduler
sudo cp .env /secure-backup/opsifin-scheduler/env.before-redis
sudo cp -a /etc/supervisor/conf.d \
  /secure-backup/opsifin-scheduler/supervisor-conf.before-redis
```

Pastikan backup tidak disimpan di dalam `public/` dan hanya dapat dibaca oleh
administrator yang berwenang.

## 7. Hentikan dispatch job baru

Nonaktifkan hanya cron milik aplikasi. Jangan menghentikan seluruh service cron
jika VPS menjalankan aplikasi lain.

Jika menggunakan `/etc/cron.d/opsifin-scheduler`:

```bash
sudo mv /etc/cron.d/opsifin-scheduler \
  /etc/cron.d/opsifin-scheduler.disabled
```

Jika menggunakan aaPanel, nonaktifkan job `artisan schedule:run` melalui menu
Cron aaPanel.

Pastikan command scheduler tidak lagi dijalankan:

```bash
sudo grep -R -nE 'artisan schedule:run|jobs:dispatch-due|opsifin' \
  /etc/crontab /etc/cron.d /var/spool/cron 2>/dev/null
```

## 8. Kosongkan database queue lama

Biarkan worker database lama tetap hidup sementara agar job yang sudah masuk
dapat diselesaikan.

Periksa jumlah payload di queue database:

```sql
SELECT COUNT(*) AS pending_jobs FROM jobs;
```

Periksa Run yang belum selesai:

```sql
SELECT status, COUNT(*) AS total
FROM runs
WHERE status IN ('queued', 'running')
GROUP BY status;
```

Kondisi ideal sebelum melanjutkan:

```text
pending_jobs = 0
queued runs  = 0
running runs = 0
```

Jika masih ada `running`, tunggu sampai selesai. Jika ada job macet, periksa
Execution Logs dan log Laravel sebelum memutuskan tindakan.

Setelah queue kosong, cari dan hentikan worker database lama:

```bash
sudo supervisorctl status
sudo supervisorctl stop <nama-program-worker-lama>:*
sudo supervisorctl status
```

Jangan mengaktifkan kembali worker `queue:work database` setelah Redis Queue
aktif.

## 9. Deploy release Redis dan Horizon

```bash
cd /var/www/opsifin-scheduler

sudo -u opsifin_admin git fetch --tags --prune
sudo -u opsifin_admin git checkout <commit-atau-release-tag>

sudo -u opsifin_admin composer install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction
```

Jika asset frontend dibangun di VPS:

```bash
sudo -u opsifin_admin npm ci
sudo -u opsifin_admin npm run build
```

Pastikan dependency tersedia:

```bash
sudo -u opsifin_admin composer show laravel/horizon
sudo -u opsifin_admin composer show predis/predis
```

## 10. Update `.env` production

Pastikan `.env` sudah dibackup, kemudian isi konfigurasi berikut:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Asia/Jakarta
DB_TIMEZONE=+07:00

DB_CONNECTION=mysql
SESSION_DRIVER=database
CACHE_STORE=database

QUEUE_CONNECTION=redis
QUEUE_FAILED_DRIVER=database-uuids

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=<redis-password-atau-null>
REDIS_PORT=6379

# Redis DB 0: metadata dan metrics Horizon.
REDIS_DB=0

# Redis DB 2: payload queue.
REDIS_QUEUE_DB=2
REDIS_QUEUE_CONNECTION=queue
REDIS_QUEUE=default
REDIS_QUEUE_RETRY_AFTER=2000
REDIS_QUEUE_BLOCK_FOR=5

HORIZON_NAME="Opsifin Scheduler Production"
HORIZON_REDIS_CONNECTION=default
HORIZON_MIN_PROCESSES=2
HORIZON_MAX_PROCESSES=10
HORIZON_TIMEOUT=1900
```

Cache dan session tetap menggunakan MySQL. `REDIS_CACHE_DB` tidak diperlukan
selama `CACHE_STORE=database`.

Amankan `.env` sesuai user web server yang digunakan:

```bash
sudo chown opsifin_admin:www-data .env
sudo chmod 0640 .env
```

Bersihkan konfigurasi lama:

```bash
sudo -u opsifin_admin php8.4 artisan optimize:clear
```

Tes koneksi Laravel ke Redis Queue:

```bash
sudo -u opsifin_admin php8.4 artisan tinker \
  --execute="dump(Illuminate\\Support\\Facades\\Redis::connection('queue')->ping());"
```

Hasilnya harus berupa `PONG`. Jangan melanjutkan migration atau menyalakan
Horizon jika koneksi Redis gagal.

## 11. Jalankan migration

Periksa migration:

```bash
sudo -u opsifin_admin php8.4 artisan migrate:status
```

Jalankan migration production:

```bash
sudo -u opsifin_admin php8.4 artisan migrate --force
```

Migration Redis akan:

- mengubah `runs.queue_job_id` dari integer menjadi string;
- mengosongkan ID queue lama untuk Run yang masih berstatus `queued`;
- memungkinkan penyimpanan UUID job Redis.

Migration tidak menghapus Client, Schedule, Job Template, User, Execution Log,
atau Audit Log.

Rebuild cache aplikasi:

```bash
sudo -u opsifin_admin php8.4 artisan optimize
```

Jangan menjalankan:

```text
artisan migrate:fresh
artisan db:wipe
artisan db:seed
artisan cron:import --fresh
```

## 12. Install konfigurasi Supervisor Horizon

Template production tersedia di:

```text
deploy/vps/supervisor-worker.conf.template
```

Pasang template:

```bash
cd /var/www/opsifin-scheduler

sudo cp deploy/vps/supervisor-worker.conf.template \
  /etc/supervisor/conf.d/opsifin-scheduler-horizon.conf

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start opsifin-scheduler-horizon
```

Periksa Supervisor dan Horizon:

```bash
sudo supervisorctl status opsifin-scheduler-horizon
sudo -u opsifin_admin php8.4 artisan horizon:status
```

Hasil yang diharapkan:

```text
opsifin-scheduler-horizon   RUNNING
Horizon is running
```

Periksa log:

```bash
sudo tail -n 100 /var/log/opsifin-scheduler/horizon.log
```

Supervisor hanya menjalankan satu master Horizon. Horizon akan mengelola 2
sampai 10 worker berdasarkan beban queue.

## 13. Rekonsiliasi queued Run

Jalankan sekali setelah Horizon aktif:

```bash
sudo -u opsifin_admin php8.4 artisan jobs:reconcile-queued
```

Command ini memublikasikan ulang Run berstatus `queued` yang sudah tercatat di
MySQL tetapi belum memiliki payload queue.

Periksa failed jobs:

```bash
sudo -u opsifin_admin php8.4 artisan queue:failed
```

## 14. Aktifkan kembali Laravel Scheduler

Jika sebelumnya file cron dipindahkan:

```bash
sudo mv /etc/cron.d/opsifin-scheduler.disabled \
  /etc/cron.d/opsifin-scheduler
```

Pastikan hanya ada satu entry scheduler untuk aplikasi:

```cron
* * * * * opsifin_admin cd /var/www/opsifin-scheduler && /usr/bin/php8.4 artisan schedule:run >> /var/log/opsifin-scheduler/scheduler.log 2>&1
```

Periksa daftar schedule:

```bash
sudo -u opsifin_admin php8.4 artisan schedule:list
```

Daftar minimal harus berisi:

- `jobs:dispatch-due` setiap menit;
- `jobs:reconcile-queued` setiap menit;
- `horizon:snapshot` setiap lima menit.

## 15. Smoke test

Jangan langsung mengaktifkan semua Schedule yang berisiko. Lakukan pengujian
bertahap:

1. Login sebagai Administrator.
2. Buka `/horizon` dan pastikan dashboard dapat diakses.
3. Pilih satu Schedule dengan endpoint yang aman.
4. Klik **Run now**.
5. Buka Execution Logs.
6. Pastikan status bergerak `queued -> running -> succeeded/failed`.
7. Pastikan job terlihat pada Horizon.
8. Periksa HTTP status, duration, response excerpt, dan error.
9. Periksa log Horizon, scheduler, dan Laravel.

Command pemeriksaan:

```bash
sudo supervisorctl status opsifin-scheduler-horizon
sudo -u opsifin_admin php8.4 artisan horizon:status
sudo -u opsifin_admin php8.4 artisan queue:failed
sudo tail -n 100 /var/log/opsifin-scheduler/horizon.log
sudo tail -n 100 /var/log/opsifin-scheduler/scheduler.log
sudo tail -n 100 /var/www/opsifin-scheduler/storage/logs/laravel.log
```

## 16. Health check pasca-cutover

```bash
sudo systemctl status redis-server supervisor cron --no-pager
sudo supervisorctl status opsifin-scheduler-horizon
sudo -u opsifin_admin php8.4 artisan horizon:status
sudo -u opsifin_admin php8.4 artisan schedule:list
sudo -u opsifin_admin php8.4 artisan queue:failed
redis-cli ping
```

Pantau minimal dua siklus schedule:

- CPU dan RAM VPS;
- disk dan inode;
- Redis uptime dan AOF;
- status Horizon dan jumlah worker;
- antrean tertua;
- jumlah failed jobs;
- Run yang terlalu lama berada di `queued` atau `running`;
- failure rate pada Execution Logs;
- pertumbuhan log Horizon dan Laravel.

## 17. Deployment release berikutnya

Untuk release selanjutnya, gunakan urutan berikut:

```bash
cd /var/www/opsifin-scheduler

sudo -u opsifin_admin git fetch --tags --prune
sudo -u opsifin_admin git checkout <release-baru>

sudo -u opsifin_admin composer install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction

sudo -u opsifin_admin npm ci
sudo -u opsifin_admin npm run build
sudo -u opsifin_admin php8.4 artisan migrate --force
sudo -u opsifin_admin php8.4 artisan optimize
sudo -u opsifin_admin php8.4 artisan horizon:terminate
```

`horizon:terminate` menghentikan master Horizon secara graceful. Supervisor akan
menjalankannya kembali menggunakan kode release terbaru.

## 18. Rollback sebelum scheduler diaktifkan

Rollback paling aman dilakukan sebelum ada job baru yang masuk ke Redis:

1. Pastikan cron aplikasi tetap nonaktif.
2. Hentikan program Horizon.
3. Kembalikan `.env` sebelum Redis.
4. Bersihkan cache konfigurasi.
5. Aktifkan kembali database worker lama.
6. Aktifkan kembali cron setelah aplikasi diverifikasi.

Contoh:

```bash
sudo supervisorctl stop opsifin-scheduler-horizon

cd /var/www/opsifin-scheduler
sudo cp /secure-backup/opsifin-scheduler/env.before-redis .env
sudo -u opsifin_admin php8.4 artisan optimize:clear

sudo supervisorctl start <nama-program-worker-lama>:*
sudo supervisorctl status
```

Jangan otomatis menjalankan `migrate:rollback`. Kolom string
`runs.queue_job_id` tetap dapat menyimpan ID database queue berbentuk angka.

## 19. Rollback setelah Redis menerima job

Jika Redis sudah menerima job, jangan langsung mengganti
`QUEUE_CONNECTION=database` karena payload masih berada di Redis.

Urutan aman jika Redis dan Horizon masih sehat:

1. nonaktifkan cron aplikasi;
2. tunggu Redis Queue kosong dan semua Run selesai;
3. hentikan Horizon;
4. ubah queue kembali ke database;
5. jalankan `optimize:clear`;
6. hidupkan database worker;
7. aktifkan kembali cron aplikasi.

Jika Redis rusak atau tidak dapat diakses sementara masih ada Run berstatus
`queued`, lakukan recovery di maintenance window. Jangan menyalakan Redis worker
dan database worker untuk Run yang sama secara bersamaan. Backup database dahulu,
identifikasi Run terdampak, lalu publikasikan ulang melalui database queue sesuai
change plan yang disetujui.

## 20. Checklist persetujuan go-live

### Sebelum cutover

- [ ] Perubahan Redis/Horizon sudah di-commit dan push.
- [ ] Commit atau release tag sudah dicatat.
- [ ] Backup MySQL berhasil dibuat.
- [ ] Backup `.env` dan Supervisor berhasil dibuat.
- [ ] Redis hanya listen di localhost atau private network.
- [ ] Redis AOF aktif.
- [ ] `maxmemory-policy noeviction` aktif.
- [ ] `redis-cli ping` menghasilkan `PONG`.
- [ ] Cron aplikasi sudah dinonaktifkan sementara.
- [ ] Database queue lama sudah kosong.
- [ ] Tidak ada Run `queued` atau `running` yang tidak diketahui.
- [ ] Worker database lama sudah dihentikan.

### Saat deployment

- [ ] Release yang benar sudah di-checkout.
- [ ] Composer install berhasil.
- [ ] Konfigurasi `.env` sudah diverifikasi.
- [ ] Laravel berhasil terhubung ke Redis connection `queue`.
- [ ] Migration berhasil.
- [ ] `artisan optimize` berhasil.
- [ ] Supervisor Horizon berstatus `RUNNING`.
- [ ] `artisan horizon:status` menunjukkan Horizon aktif.
- [ ] `jobs:reconcile-queued` berhasil.
- [ ] Scheduler sudah diaktifkan kembali.

### Setelah cutover

- [ ] `/horizon` dapat dibuka oleh Administrator.
- [ ] User non-Administrator ditolak dari `/horizon`.
- [ ] Smoke Run bergerak dari `queued` ke status terminal.
- [ ] HTTP request tujuan hanya terkirim satu kali.
- [ ] Tidak ada failed job yang tidak dijelaskan.
- [ ] Log Horizon dan scheduler bersih dari error berulang.
- [ ] CPU, RAM, dan disk berada dalam batas aman.
- [ ] Hasil deployment dan PIC dicatat.

