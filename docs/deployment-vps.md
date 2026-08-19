# Deployment Production ke VPS

Panduan ini adalah runbook deployment production resmi Opsifin Scheduler tanpa
aaPanel. Contoh memakai Ubuntu/Debian, Nginx, PHP 8.4, MySQL, Supervisor,
system cron, dan domain `scheduler.example.com`. Sesuaikan package, socket, path,
serta kebijakan keamanan dengan standar organisasi.

Deployment yang dipilih untuk project ini menggunakan **database yang sudah
berisi data dari environment sekarang**. Production tidak mengulang import dari
`crontab-legacy`. Prosedur dump/restore lengkap ada di
[database-migration-vps.md](database-migration-vps.md).

## 1. Outcome deployment

Setelah seluruh langkah selesai:

- panel tersedia melalui HTTPS di `/admin`;
- database existing beserta user, client, credential, template, schedule,
  execution log, dan audit history sudah dipindah;
- password/token Client terbaca langsung karena disimpan as-is dan sudah
  dikonversi sebelum dump;
- dua database queue worker dikelola Supervisor;
- satu system cron memanggil Laravel Scheduler setiap menit;
- avatar user tersedia melalui `public/storage`;
- Telescope tersedia di `/telescope` hanya untuk Administrator;
- Nginx, application log, worker log, dan scheduler log ter-rotate;
- backup, smoke test, rollback, dan PIC terdokumentasi.

## 2. Arsitektur production

```text
User / HTTPS monitor
          |
          v
       Nginx :443
          |
          v
     PHP 8.4-FPM -------- Laravel / Filament -------- MySQL
                              |                         ^
                              | database queue          |
                              v                         |
                    Supervisor (2 workers) -------------+
                              |
                              v
                      HTTP endpoint Client

system cron (1 menit) -> artisan schedule:run
                         -> jobs:dispatch-due
                         -> cron:purge-runs
                         -> telescope:prune
```

Komponen yang **tidak** digunakan:

- aaPanel di production;
- Redis/Horizon;
- cron Linux per Client/job;
- automatic HTTP retry;
- `cron:tick` dan `cron:watchdog` lama;
- re-import `crontab-legacy` setelah database dipindah.

## 3. User dan ownership process

| Process | User yang disarankan |
| --- | --- |
| Deploy, Git, Composer, npm, Artisan | `opsifin` |
| Supervisor queue worker | `opsifin` |
| System cron Laravel Scheduler | `opsifin` |
| Nginx dan PHP-FPM | `www-data` |
| MySQL daemon | user service OS bawaan |

`opsifin` memiliki source dan runtime files. `www-data` hanya membutuhkan akses
baca source serta akses tulis ke `storage` dan `bootstrap/cache`.

## 4. Worksheet deployment

Isi sebelum mengeksekusi command:

| Variable | Contoh | Nilai production |
| --- | --- | --- |
| Domain | `scheduler.example.com` | |
| Repository | `git@...:opsifin-crontab.git` | |
| Release tag/commit | `v1.0.0` | |
| Project path | `/var/www/opsifin-scheduler` | |
| App user | `opsifin` | |
| Web user | `www-data` | |
| PHP binary | `/usr/bin/php8.4` | |
| PHP-FPM socket | `/run/php/php8.4-fpm.sock` | |
| Database | `opsifin_scheduler` | |
| Database user | `opsifin_scheduler` | |
| Default timezone | `Asia/Jakarta` | |
| Backup directory | `/secure-backup/opsifin-scheduler` | |
| PIC deploy/rollback | | |

Jangan mulai jika release commit, backup owner, atau rollback owner belum
ditentukan.

## 5. Kapasitas dan prasyarat

Baseline awal yang wajar adalah 2 vCPU, RAM 4 GB, dan disk SSD dengan kapasitas
yang memperhitungkan database, log, backup lokal sementara, serta pertumbuhan
`runs`. Ukuran final harus mengikuti jumlah schedule dan retention.

Kebutuhan software:

- OS Ubuntu/Debian yang masih mendapat security update;
- Nginx;
- PHP CLI/FPM 8.4 beserta extension `bcmath`, `curl`, `fileinfo`, `gd`, `intl`,
  `mbstring`, `mysql`, `openssl`, `tokenizer`, `xml`, dan `zip`;
- MySQL yang kompatibel dengan source;
- Composer 2;
- Node/npm yang kompatibel dengan Vite 8;
- Git, curl, unzip, cron, Supervisor, logrotate, dan CA certificates;
- DNS, sertifikat TLS, SMTP bila nanti dipakai, backup storage, serta monitoring
  dari host lain.

Contoh instalasi package dasar:

```bash
sudo apt update
sudo apt upgrade
sudo apt install nginx mysql-server supervisor cron git curl unzip ca-certificates logrotate
sudo apt install php8.4-cli php8.4-fpm php8.4-bcmath php8.4-curl php8.4-gd \
  php8.4-intl php8.4-mbstring php8.4-mysql php8.4-xml php8.4-zip
```

Jika PHP 8.4 tidak tersedia di repository OS, gunakan repository PHP yang sudah
disetujui organisasi. Jangan menambahkan repository pihak ketiga tanpa review.

Verifikasi:

```bash
php8.4 -v
php8.4 -m | sort
php8.4 -r 'foreach (["bcmath","curl","fileinfo","gd","intl","mbstring","openssl","pdo_mysql","xml","zip"] as $e) { echo $e, extension_loaded($e) ? " OK\n" : " MISSING\n"; }'
composer --version
node --version
npm --version
nginx -v
mysqld --version
supervisord --version
```

## 6. Hardening awal VPS

Sebelum aplikasi:

1. sinkronkan waktu dengan NTP;
2. tentukan timezone OS dan dokumentasikan;
3. gunakan SSH key, nonaktifkan password login bila memungkinkan;
4. batasi SSH berdasarkan IP/VPN;
5. buka hanya port 22 terbatas, 80, dan 443;
6. jangan membuka port MySQL ke internet;
7. aktifkan unattended/security updates sesuai kebijakan;
8. siapkan external uptime monitoring dan backup off-host.

Contoh pemeriksaan:

```bash
timedatectl
ss -lntup
sudo systemctl status nginx mysql supervisor cron php8.4-fpm --no-pager
```

## 7. DNS dan TLS preparation

- arahkan record A/AAAA domain ke VPS;
- turunkan TTL sebelum cutover bila DNS akan dipindah;
- pastikan port 80/443 dapat dicapai;
- siapkan email/owner renewal sertifikat;
- jangan mengubah DNS final sebelum smoke test lokal selesai.

## 8. Membuat service user dan directory

```bash
sudo adduser --system --group --home /var/www/opsifin-scheduler opsifin
sudo mkdir -p /var/www/opsifin-scheduler
sudo mkdir -p /var/log/opsifin-scheduler
sudo mkdir -p /secure-backup/opsifin-scheduler
sudo mkdir -p /secure-import/opsifin-scheduler

sudo chown -R opsifin:opsifin /var/www/opsifin-scheduler
sudo chown -R opsifin:www-data /var/log/opsifin-scheduler
sudo chmod 2775 /var/log/opsifin-scheduler
sudo chmod 700 /secure-backup/opsifin-scheduler /secure-import/opsifin-scheduler
```

Tambahkan web user ke group aplikasi bila kebijakan server mengizinkan:

```bash
sudo usermod -aG opsifin www-data
sudo systemctl restart php8.4-fpm
```

## 9. Membuat database target

Masuk ke MySQL sebagai administrator dan jalankan:

```sql
CREATE DATABASE opsifin_scheduler
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER 'opsifin_scheduler'@'127.0.0.1'
  IDENTIFIED BY '<strong-random-password>';

GRANT ALL PRIVILEGES ON opsifin_scheduler.*
  TO 'opsifin_scheduler'@'127.0.0.1';

FLUSH PRIVILEGES;
```

Simpan password di secret manager. Jangan memasukkan password langsung pada
command line yang dapat terlihat melalui history atau process list.

## 10. Mengambil source release

Clone sebagai user aplikasi:

```bash
sudo -u opsifin git clone <repository-url> /var/www/opsifin-scheduler
cd /var/www/opsifin-scheduler
sudo -u opsifin git fetch --tags --prune
sudo -u opsifin git checkout <release-tag-or-commit>
sudo -u opsifin git rev-parse HEAD
```

Production wajib memakai tag atau commit tetap. Catat hash pada change ticket.
Jangan deploy working tree lokal yang belum ditinjau tanpa membentuk release.

## 11. Install dependency dan build asset

```bash
cd /var/www/opsifin-scheduler
sudo -u opsifin composer install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction

sudo -u opsifin npm ci
sudo -u opsifin npm run build
```

Verifikasi artefak:

```bash
test -f vendor/autoload.php
test -f public/build/manifest.json
```

Jika organisasi membangun asset di CI, transfer release artifact immutable dan
tetap verifikasi checksum. Target production tidak perlu menyimpan npm cache.

## 12. Membuat `.env` production

Salin template lalu edit melalui editor/secret injection yang tidak mencetak
secret:

```bash
cd /var/www/opsifin-scheduler
sudo -u opsifin cp .env.example .env
sudo chown opsifin:www-data .env
sudo chmod 0640 .env
```

Baseline:

```dotenv
APP_NAME="Opsifin Scheduler"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://scheduler.example.com
APP_KEY=

APP_LOCALE=en
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=info
LOG_DAILY_DAYS=14

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=opsifin_scheduler
DB_USERNAME=opsifin_scheduler
DB_PASSWORD=<secret>

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false

CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE=default
DB_QUEUE_RETRY_AFTER=2000

FILESYSTEM_DISK=local

CRON_DEFAULT_TIMEZONE=Asia/Jakarta
CRON_RUNS_RETENTION_DAYS=90
CRON_EXECUTION_MARGIN_SEC=60
CRON_SOURCE_PATH=

TELESCOPE_ENABLED=true
TELESCOPE_JOB_WATCHER=true
TELESCOPE_COMMAND_WATCHER=true
TELESCOPE_EXCEPTION_WATCHER=true
TELESCOPE_LOG_WATCHER=true
TELESCOPE_SCHEDULE_WATCHER=true
TELESCOPE_REQUEST_WATCHER=true
TELESCOPE_CLIENT_REQUEST_WATCHER=true
TELESCOPE_QUERY_WATCHER=false
TELESCOPE_MODEL_WATCHER=false
```

Credential Client tidak lagi menggunakan enkripsi Laravel. Setelah `.env`
dibuat, generate key baru khusus target untuk cookie dan layanan internal
Laravel:

```bash
sudo -u opsifin php8.4 artisan key:generate --force
```

`APP_KEY` source tidak perlu dipindahkan setelah migration konversi credential
berhasil dijalankan pada source. Jangan membagikan key source melalui chat atau
menaruhnya di dump database.

Amankan `.env`:

```bash
sudo chown opsifin:www-data /var/www/opsifin-scheduler/.env
sudo chmod 0640 /var/www/opsifin-scheduler/.env
```

Mode `0640` diperlukan karena PHP-FPM berjalan sebagai `www-data`. Jika pool
PHP-FPM khusus juga berjalan sebagai `opsifin`, mode dapat diperketat menjadi
`0600`.

## 13. Permission runtime dan public storage

```bash
cd /var/www/opsifin-scheduler
sudo chown -R opsifin:opsifin .
sudo chown -R opsifin:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 0664 {} \;

sudo -u opsifin php8.4 artisan storage:link
```

Verifikasi:

```bash
sudo -u opsifin test -w storage/logs
sudo -u www-data test -w storage/logs
sudo -u www-data test -w bootstrap/cache
sudo -u www-data test -r public/index.php
test -L public/storage
```

## 14. Migrasi database existing

Ikuti [database-migration-vps.md](database-migration-vps.md) dari awal sampai
akhir. Ringkasannya:

1. catat commit dan jumlah data source;
2. pause/quiesce source, hentikan scheduler dan worker;
3. jalankan migration release pada source untuk mengubah ciphertext lama menjadi
   plaintext menggunakan `APP_KEY` source satu kali;
4. pastikan `jobs = 0` serta tidak ada Run `queued/running`;
5. buat dump konsisten dan checksum;
6. arsipkan `storage/app/public/avatars`;
7. transfer dan verifikasi checksum;
8. restore ke database target kosong;
9. bersihkan session/cache/queue/Telescope runtime lama;
10. restore avatar dan buat `storage:link`;
11. jalankan migration release target yang masih tertunda;
12. bandingkan jumlah record dan verifikasi credential dari UI.

Command target setelah restore:

```bash
cd /var/www/opsifin-scheduler
sudo -u opsifin php8.4 artisan migrate:status
sudo -u opsifin php8.4 artisan migrate --force
sudo -u opsifin php8.4 artisan optimize
```

Jangan menjalankan:

```text
artisan migrate:fresh
artisan db:wipe
artisan db:seed
artisan cron:import
artisan cron:import --fresh
artisan key:generate
```

User sudah ikut dari database source. `cron:admin-create` hanya digunakan bila
perlu membuat/reset satu Administrator secara sadar.

## 15. Nginx virtual host

Salin template dan sesuaikan domain/socket:

```bash
cd /var/www/opsifin-scheduler
sudo cp deploy/vps/nginx-site.conf.template \
  /etc/nginx/sites-available/opsifin-scheduler

sudo editor /etc/nginx/sites-available/opsifin-scheduler
sudo ln -s /etc/nginx/sites-available/opsifin-scheduler \
  /etc/nginx/sites-enabled/opsifin-scheduler

sudo nginx -t
sudo systemctl reload nginx
```

Kontrak penting:

- `root` wajib `/var/www/opsifin-scheduler/public`;
- PHP hanya diteruskan ke socket PHP 8.4-FPM;
- `location ^~ /livewire-` tetap ada;
- `/build/` dilayani sebagai immutable asset;
- `.env`, log, SQL, backup, dan dotfile tidak dapat diunduh;
- upload maksimum sesuai kebutuhan avatar;
- log Nginx tidak berada di web root.

Smoke test HTTP lokal sebelum DNS/TLS:

```bash
curl -I -H 'Host: scheduler.example.com' http://127.0.0.1/admin/login
curl -I -H 'Host: scheduler.example.com' http://127.0.0.1/livewire/livewire.min.js
```

Respons login `200` atau redirect aplikasi valid. `404` static page biasanya
menandakan root, `try_files`, atau vhost salah.

## 16. TLS

Gunakan Certbot atau mekanisme certificate organisasi. Contoh Certbot:

```bash
sudo certbot --nginx -d scheduler.example.com
sudo certbot renew --dry-run
```

Setelah aktif:

```bash
curl -I https://scheduler.example.com/admin/login
```

Pastikan:

- HTTP redirect ke HTTPS;
- sertifikat dan chain valid;
- renewal timer aktif;
- `APP_URL` memakai HTTPS;
- cookie/session sesuai domain;
- security header ditambahkan sesuai policy organisasi.

## 17. Supervisor database queue worker

Salin template:

```bash
cd /var/www/opsifin-scheduler
sudo cp deploy/vps/supervisor-worker.conf.template \
  /etc/supervisor/conf.d/opsifin-scheduler-worker.conf

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status 'opsifin-scheduler-worker:*'
```

Kontrak worker:

```text
connection             database
queue                  default
numprocs               2
tries                  1
worker timeout         1900 detik
DB_QUEUE_RETRY_AFTER   2000 detik
max-time               3600 detik
stopwaitsecs           2000 detik
```

Timeout queue harus lebih besar dari worker timeout agar payload tidak diambil
worker kedua saat worker pertama masih berjalan. Tidak ada automatic HTTP retry.

Saat deployment kode berikutnya:

```bash
sudo -u opsifin php8.4 artisan queue:restart
sudo supervisorctl status 'opsifin-scheduler-worker:*'
```

## 18. System cron Laravel Scheduler

Hanya satu entry:

```bash
cd /var/www/opsifin-scheduler
sudo cp deploy/vps/opsifin-scheduler.cron /etc/cron.d/opsifin-scheduler
sudo chmod 0644 /etc/cron.d/opsifin-scheduler
sudo systemctl restart cron
```

Isi:

```cron
* * * * * opsifin cd /var/www/opsifin-scheduler && /usr/bin/php8.4 artisan schedule:run >> /var/log/opsifin-scheduler/scheduler.log 2>&1
```

Verifikasi tidak ada task lama:

```bash
sudo grep -R -nE 'cron:tick|cron:watchdog|jobs:dispatch-due|schedule:run|opsifin' \
  /etc/crontab /etc/cron.d /var/spool/cron 2>/dev/null
```

Yang boleh aktif hanya satu `artisan schedule:run` untuk aplikasi ini. Jangan
membuat cron per Client atau per Job Template.

## 19. Log rotation

```bash
cd /var/www/opsifin-scheduler
sudo cp deploy/vps/logrotate.conf.template \
  /etc/logrotate.d/opsifin-scheduler
sudo logrotate -d /etc/logrotate.d/opsifin-scheduler
```

Log map:

| Lokasi | Isi |
| --- | --- |
| UI Execution logs | hasil tiap HTTP execution |
| UI Audit history | perubahan konfigurasi user |
| UI Telescope | request/job/exception teknis |
| `storage/logs/laravel.log` | exception aplikasi |
| `/var/log/opsifin-scheduler/worker.log` | lifecycle worker |
| `/var/log/opsifin-scheduler/scheduler.log` | output system cron |
| `/var/log/nginx/opsifin-scheduler.error.log` | error Nginx/upstream |
| PHP-FPM log/journal | error process FPM |

## 20. Validasi sebelum runtime dinyalakan

Dengan cron dan worker target masih berhenti:

```bash
cd /var/www/opsifin-scheduler
sudo -u opsifin php8.4 artisan about --only=environment
sudo -u opsifin php8.4 artisan migrate:status
sudo -u opsifin php8.4 artisan route:list --path=admin
sudo -u opsifin php8.4 artisan route:list --path=telescope
sudo -u opsifin php8.4 artisan schedule:list
sudo -u opsifin php8.4 artisan queue:failed
curl -I https://scheduler.example.com/admin/login
```

UI smoke test read-only:

1. login dengan Administrator hasil restore;
2. buka Dashboard;
3. buka Client Job Summary dan gunakan Show more;
4. buka Clients dan reveal satu credential secara aman;
5. buka Job Templates dan Schedules;
6. gunakan Inspect request; pastikan secret tetap disamarkan;
7. gunakan filter periode di Execution logs;
8. buka Audit history;
9. buka User Management dan pastikan avatar tampil;
10. buka Telescope sebagai Administrator dan pastikan role lain ditolak.

## 21. Menyalakan worker dan scheduler

Setelah validasi read-only lulus:

```bash
sudo supervisorctl start 'opsifin-scheduler-worker:*'
sudo supervisorctl status 'opsifin-scheduler-worker:*'
sudo systemctl restart cron
```

Lakukan smoke execution:

1. pilih satu Schedule dengan endpoint yang aman;
2. bila perlu biarkan Schedule paused;
3. klik **Run now**;
4. buka Execution logs;
5. pastikan status bergerak `queued -> running -> succeeded/failed`;
6. verifikasi HTTP status, duration, response excerpt, dan error;
7. periksa worker log dan Laravel log;
8. jangan mass-resume sebelum hasil disetujui.

## 22. Cutover dari runtime lama

Karena data sudah berasal dari database source, jangan import legacy lagi.
Cutover hanya memindahkan **runtime owner**:

1. pastikan source scheduler/legacy cron untuk job yang sama berhenti;
2. arahkan DNS/reverse proxy ke VPS baru;
3. aktifkan schedule bertahap melalui UI jika sebelumnya dipause;
4. pantau minimal dua siklus;
5. bandingkan efek bisnis pada endpoint tujuan;
6. pertahankan source dalam keadaan siap rollback selama window yang disepakati.

Jangan menyalakan runtime source dan target untuk Schedule yang sama secara
bersamaan.

## 23. Health check pasca-go-live

```bash
sudo systemctl status nginx php8.4-fpm mysql cron supervisor --no-pager
sudo supervisorctl status 'opsifin-scheduler-worker:*'
sudo -u opsifin php8.4 artisan schedule:list
sudo -u opsifin php8.4 artisan queue:failed
sudo tail -n 100 /var/log/opsifin-scheduler/scheduler.log
sudo tail -n 100 /var/log/opsifin-scheduler/worker.log
sudo tail -n 100 /var/www/opsifin-scheduler/storage/logs/laravel.log
```

Pantau dari luar VPS:

- HTTPS login page;
- certificate expiry;
- CPU, RAM, disk, inode;
- MySQL availability dan backup freshness;
- worker process count;
- umur queue tertua;
- failure rate Execution logs;
- tidak adanya schedule aktif tanpa `next_run_at`.

## 24. Deployment release berikutnya

Urutan aman:

1. umumkan maintenance/change window;
2. backup database dan public uploads;
3. catat commit aktif;
4. ambil release baru;
5. install dependency dan build asset;
6. jalankan migration;
7. rebuild cache;
8. restart worker graceful;
9. reload PHP-FPM/Nginx bila perlu;
10. jalankan smoke test;
11. catat commit, migration, dan hasil validasi.

Contoh:

```bash
cd /var/www/opsifin-scheduler
sudo -u opsifin git fetch --tags --prune
sudo -u opsifin git checkout <new-release-tag>
sudo -u opsifin composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
sudo -u opsifin npm ci
sudo -u opsifin npm run build
sudo -u opsifin php8.4 artisan migrate --force
sudo -u opsifin php8.4 artisan optimize
sudo -u opsifin php8.4 artisan queue:restart
sudo systemctl reload php8.4-fpm
sudo nginx -t
sudo systemctl reload nginx
```

Gunakan `artisan down` bila perubahan schema/asset tidak backward compatible.

## 25. Rollback release

1. Pause Schedule berisiko jika eksekusi harus berhenti.
2. Hentikan worker bila payload tidak kompatibel dengan kode lama.
3. Checkout tag/commit sebelumnya.
4. Jalankan Composer/npm sesuai lock file release lama.
5. Restore database hanya jika migration tidak backward compatible dan backup
   sudah diverifikasi.
6. Jalankan `optimize:clear`, `optimize`, lalu `queue:restart`.
7. Reload service dan smoke test.

Jangan otomatis memakai `migrate:rollback`. Beberapa migration tidak dirancang
sebagai rollback destructive. Gunakan restore backup berdasarkan change plan.

## 26. Backup production

Backup minimum:

- dump MySQL harian dengan enkripsi;
- `APP_KEY`/`.env` melalui secret manager terpisah;
- `storage/app/public` untuk avatar;
- konfigurasi Nginx, Supervisor, cron, TLS, dan logrotate;
- tag/commit serta `composer.lock`/`package-lock.json`;
- backup off-host dengan retention dan restore test.

Backup yang belum pernah direstore belum dapat dianggap valid.

## 27. Troubleshooting cepat

### Login 500 atau asset tidak tampil

```bash
sudo -u opsifin php8.4 artisan optimize:clear
sudo nginx -t
sudo tail -n 100 /var/log/nginx/opsifin-scheduler.error.log
sudo tail -n 100 storage/logs/laravel.log
```

Periksa permission, root `/public`, route Livewire, `APP_URL`, dan PHP-FPM socket.

### Credential masih terlihat seperti ciphertext

Migration `2026_08_19_000005_store_client_credentials_as_plaintext` belum
dijalankan pada source atau dijalankan dengan `APP_KEY` yang salah. Jangan
menyalakan worker. Pulihkan dump source, gunakan key lama satu kali untuk
menjalankan migration konversi, lalu buat ulang dump. Ciphertext lama tidak bisa
dipulihkan tanpa key yang mengenkripsinya.

### Run tertahan di queued

```bash
sudo supervisorctl status 'opsifin-scheduler-worker:*'
sudo -u opsifin php8.4 artisan queue:failed
sudo tail -n 100 /var/log/opsifin-scheduler/worker.log
```

### Schedule tidak dispatch

```bash
sudo systemctl status cron --no-pager
sudo -u opsifin php8.4 artisan schedule:run -v
sudo tail -n 100 /var/log/opsifin-scheduler/scheduler.log
```

### Avatar 404

```bash
test -L public/storage
ls -la storage/app/public/avatars
sudo -u opsifin php8.4 artisan storage:link
```

### Telescope tidak merekam request baru

Clear config cache dan restart long-running queue workers:

```bash
sudo -u opsifin php8.4 artisan optimize:clear
sudo -u opsifin php8.4 artisan queue:restart
```

## 28. Final go-live checklist

### Security

- [ ] SSH, firewall, TLS, renewal, dan security update siap.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] `.env` permission `0640` (`opsifin:www-data`) atau lebih ketat bila user
  PHP-FPM sama dengan app user, dan tidak berada di backup publik.
- [ ] MySQL tidak terekspos internet.
- [ ] database credential menggunakan user khusus.
- [ ] Telescope hanya dapat dibuka Administrator.
- [ ] akses dan backup database dibatasi karena credential Client tersimpan
  plaintext.

### Data

- [ ] dump source dan checksum tersimpan.
- [ ] migration konversi credential berhasil di source sebelum dump.
- [ ] `APP_KEY` baru dibuat khusus target.
- [ ] jumlah Clients, Job Templates, Schedules, Runs, Users, dan Audit cocok.
- [ ] credential Client dapat direveal dan dipakai tanpa ciphertext.
- [ ] avatar telah dipindah dan tampil.
- [ ] migration target selesai.
- [ ] tidak ada payload queue/source session lama.
- [ ] `cron:import` tidak dijalankan di target.

### Runtime

- [ ] Nginx dan PHP-FPM sehat.
- [ ] dua Supervisor worker `RUNNING`.
- [ ] satu system cron `artisan schedule:run` aktif.
- [ ] tidak ada `cron:tick`, `cron:watchdog`, atau cron per job.
- [ ] log dan logrotate tersedia.
- [ ] external monitoring dan backup berjalan.

### Functional

- [ ] login dan role access sesuai.
- [ ] seluruh module dapat dibuka.
- [ ] filter dan Show more bekerja.
- [ ] Inspect request menyamarkan secret.
- [ ] satu Run now berhasil diproses worker.
- [ ] Execution log dan Audit history tampil.
- [ ] source dan target tidak menjalankan job yang sama bersamaan.
- [ ] rollback owner dan deadline tercatat.
