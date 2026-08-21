# Deployment Production ke VPS

Panduan ini adalah runbook deployment production resmi Opsifin Scheduler tanpa
aaPanel. Contoh memakai Ubuntu/Debian, Apache2, PHP 8.4-FPM, MySQL, Redis,
Supervisor, dan system cron. Instalasi awal boleh memakai IP/forwarder; domain final contoh
adalah `scheduler.example.com`. Sesuaikan package, socket, path, serta kebijakan
keamanan dengan standar organisasi.

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
- Redis queue dan Laravel Horizon dikelola Supervisor;
- satu system cron memanggil Laravel Scheduler setiap menit;
- avatar user tersedia melalui `public/storage`;
- Telescope tersedia di `/telescope` dan Horizon di `/horizon`, hanya untuk Administrator;
- Apache2, application log, Horizon log, dan scheduler log ter-rotate;
- backup, smoke test, rollback, dan PIC terdokumentasi.

## 2. Arsitektur production

```text
User / HTTPS monitor
          |
          v
      Apache2 :443
          |
          v
     PHP 8.4-FPM -------- Laravel / Filament -------- MySQL
                              |
                              | Redis queue
                              v
                   Redis <--- Horizon <--- Supervisor
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
- cron Linux per Client/job;
- automatic HTTP retry;
- `cron:tick` dan `cron:watchdog` lama;
- re-import `crontab-legacy` setelah database dipindah.

## 3. User dan ownership process

| Process | User yang disarankan |
| --- | --- |
| Deploy, Git, Composer, npm, Artisan | `opsifin_admin` |
| Supervisor Horizon | `opsifin_admin` |
| System cron Laravel Scheduler | `opsifin_admin` |
| Apache2 dan PHP-FPM | `www-data` |
| MySQL daemon | user service OS bawaan |

`opsifin_admin` memiliki source dan runtime files. `www-data` hanya membutuhkan
akses baca source serta akses tulis ke `storage` dan `bootstrap/cache`.

## 4. Worksheet deployment

Isi sebelum mengeksekusi command:

| Variable | Contoh | Nilai production |
| --- | --- | --- |
| URL awal | `http://10.10.20.15` | |
| Domain final (boleh pending) | `scheduler.example.com` | |
| Repository | `git@...:opsifin-crontab.git` | |
| Release tag/commit | `v1.0.0` | |
| Project path | `/var/www/opsifin-scheduler` | |
| App user | `opsifin_admin` | |
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
- Apache2 dengan `mod_rewrite`, `mod_proxy_fcgi`, `mod_setenvif`,
  `mod_headers`, dan TLS;
- PHP CLI/FPM 8.4 beserta extension `bcmath`, `curl`, `fileinfo`, `gd`, `intl`,
  `mbstring`, `mysql`, `openssl`, `tokenizer`, `xml`, dan `zip`;
- MySQL yang kompatibel dengan source;
- Redis server dengan AOF, listener privat, dan `maxmemory-policy noeviction`;
- Composer 2;
- Node/npm yang kompatibel dengan Vite 8;
- Git, curl, unzip, cron, Supervisor, logrotate, dan CA certificates;
- DNS, sertifikat TLS, SMTP bila nanti dipakai, backup storage, serta monitoring
  dari host lain.

Contoh instalasi package dasar:

```bash
sudo apt update
sudo apt upgrade
sudo apt install apache2 mysql-server redis-server redis-tools supervisor cron git curl unzip ca-certificates logrotate
sudo apt install php8.4-cli php8.4-fpm php8.4-bcmath php8.4-curl php8.4-gd \
  php8.4-intl php8.4-mbstring php8.4-mysql php8.4-xml php8.4-zip

sudo a2enmod rewrite proxy proxy_fcgi setenvif headers ssl
sudo systemctl restart apache2 php8.4-fpm
```

Edit `/etc/redis/redis.conf` agar Redis hanya listen pada interface privat,
memakai authentication/ACL, `appendonly yes`, `appendfsync everysec`, dan
`maxmemory-policy noeviction`. Setelah itu restart Redis dan pastikan `redis-cli
ping` menghasilkan `PONG`.

Gunakan PHP-FPM, bukan `mod_php`. Bila server lama masih memakai
`mpm_prefork`/`libapache2-mod-php`, rencanakan perpindahan ke `mpm_event` dan
PHP-FPM sebelum mengaktifkan site ini. Verifikasi module aktif dengan
`apache2ctl -M`.

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
apache2ctl -v
sudo apache2ctl configtest
sudo apache2ctl -M | grep -E 'headers|proxy_fcgi|rewrite|setenvif|ssl'
mysqld --version
supervisord --version
redis-server --version
redis-cli ping
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
sudo systemctl status apache2 mysql redis-server supervisor cron php8.4-fpm --no-pager
```

## 7. Akses awal tanpa domain dan persiapan domain final

Domain tidak diperlukan untuk menjalankan scheduler, Redis queue, Horizon,
atau request HTTP ke endpoint Client. Domain hanya menjadi alamat stabil untuk
user yang membuka panel.

Gunakan salah satu mode akses awal berikut:

| Mode | Contoh `APP_URL` | Catatan |
| --- | --- | --- |
| LAN/VPN | `http://10.10.20.15` | Pilihan paling sederhana untuk smoke test internal |
| NAT/port forward | `http://203.0.113.10:8080` | Forward port luar ke Apache `:80`; batasi source IP |
| HTTPS forwarder/tunnel | `https://temporary-url.example` | Tidak perlu DNS organisasi; URL diberikan forwarder |

Untuk LAN atau NAT TCP biasa:

- isi `ServerName` template Apache dengan IP server;
- isi `APP_URL` dengan URL yang benar-benar dibuka user, termasuk port;
- biarkan `ASSET_URL=` kosong atau samakan dengan `APP_URL`;
- biarkan `TRUSTED_PROXIES=` kosong;
- gunakan `SESSION_DOMAIN=null`;
- gunakan `SESSION_SECURE_COOKIE=false` hanya selama akses masih HTTP internal.

Untuk forwarder yang menghentikan HTTPS lalu meneruskan HTTP ke Apache:

- forwarder wajib mengirim `Host`, `X-Forwarded-For`, `X-Forwarded-Host`,
  `X-Forwarded-Port`, dan `X-Forwarded-Proto`;
- isi `APP_URL` dengan URL HTTPS sementara;
- isi `ASSET_URL` dengan origin HTTPS yang sama, tanpa slash di belakang;
- isi `ServerName` dengan hostname sementara dari forwarder;
- isi `TRUSTED_PROXIES` hanya dengan IP/CIDR forwarder, misalnya
  `127.0.0.1,::1` jika connector berjalan pada host yang sama;
- gunakan `SESSION_SECURE_COOKIE=true`;
- jangan memakai `TRUSTED_PROXIES=*`.

Jangan membuka panel HTTP polos ke internet umum. Login, session, dan nilai
credential yang direveal dapat disadap. Untuk akses online sementara, gunakan
HTTPS forwarder yang memiliki access control, VPN, atau firewall allowlist.

Sambil aplikasi diuji, infra dapat menyiapkan fase final:

- record A/AAAA domain ke VPS atau load balancer;
- port 80/443;
- email/owner renewal sertifikat;
- external uptime monitoring;
- waktu cutover dan rollback.

Untuk staged deployment, selesaikan instalasi sampai Apache VirtualHost, lewati
sementara langkah 16, lalu lanjutkan Supervisor, cron, dan smoke test memakai
URL sementara. Kembali ke langkah 16 setelah domain siap.

## 8. Membuat service user dan directory

```bash
sudo adduser --system --group --home /var/www/opsifin-scheduler opsifin_admin
sudo mkdir -p /var/www/opsifin-scheduler
sudo mkdir -p /var/log/opsifin-scheduler
sudo mkdir -p /secure-backup/opsifin-scheduler
sudo mkdir -p /secure-import/opsifin-scheduler

sudo chown -R opsifin_admin:opsifin_admin /var/www/opsifin-scheduler
sudo chown -R opsifin_admin:www-data /var/log/opsifin-scheduler
sudo chmod 2775 /var/log/opsifin-scheduler
sudo chmod 700 /secure-backup/opsifin-scheduler /secure-import/opsifin-scheduler
```

Tambahkan web user ke group aplikasi bila kebijakan server mengizinkan:

```bash
sudo usermod -aG opsifin_admin www-data
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
sudo -u opsifin_admin git clone <repository-url> /var/www/opsifin-scheduler
cd /var/www/opsifin-scheduler
sudo -u opsifin_admin git fetch --tags --prune
sudo -u opsifin_admin git checkout <release-tag-or-commit>
sudo -u opsifin_admin git rev-parse HEAD
```

Production wajib memakai tag atau commit tetap. Catat hash pada change ticket.
Jangan deploy working tree lokal yang belum ditinjau tanpa membentuk release.

## 11. Install dependency dan build asset

```bash
cd /var/www/opsifin-scheduler
sudo -u opsifin_admin composer install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction

sudo -u opsifin_admin npm ci
sudo -u opsifin_admin npm run build
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
sudo -u opsifin_admin cp .env.example .env
sudo chown opsifin_admin:www-data .env
sudo chmod 0640 .env
```

Baseline:

```dotenv
APP_NAME="Opsifin Scheduler"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://10.10.20.15
ASSET_URL=
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
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false

# Kosong untuk akses langsung/NAT. Isi IP/CIDR reverse proxy, bukan IP user.
TRUSTED_PROXIES=

CACHE_STORE=database
QUEUE_CONNECTION=redis
QUEUE_FAILED_DRIVER=database-uuids

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=<redis-secret>
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
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
sudo -u opsifin_admin php8.4 artisan key:generate --force
```

`APP_KEY` source tidak perlu dipindahkan setelah migration konversi credential
berhasil dijalankan pada source. Jangan membagikan key source melalui chat atau
menaruhnya di dump database.

Amankan `.env`:

```bash
sudo chown opsifin_admin:www-data /var/www/opsifin-scheduler/.env
sudo chmod 0640 /var/www/opsifin-scheduler/.env
```

Mode `0640` diperlukan karena PHP-FPM berjalan sebagai `www-data`. Jika pool
PHP-FPM khusus juga berjalan sebagai `opsifin_admin`, mode dapat diperketat
menjadi `0600`.

## 13. Permission runtime dan public storage

```bash
cd /var/www/opsifin-scheduler
sudo chown -R opsifin_admin:opsifin_admin .
sudo chown -R opsifin_admin:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 0664 {} \;

sudo -u opsifin_admin php8.4 artisan storage:link
```

Verifikasi:

```bash
sudo -u opsifin_admin test -w storage/logs
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
5. buat full SQL dump konsisten melalui `mysqldump` atau SQLyog dan catat
   checksum;
6. arsipkan `storage/app/public/avatars`;
7. transfer dan verifikasi checksum;
8. restore ke database target kosong melalui CLI atau SQLyog;
9. bersihkan session/cache/queue/Telescope runtime lama;
10. restore avatar dan buat `storage:link`;
11. jalankan migration release target yang masih tertunda;
12. bandingkan jumlah record dan verifikasi credential dari UI.

Command target setelah restore:

```bash
cd /var/www/opsifin-scheduler
sudo -u opsifin_admin php8.4 artisan migrate:status
sudo -u opsifin_admin php8.4 artisan migrate --force
sudo -u opsifin_admin php8.4 artisan optimize
```

Jangan menjalankan:

```text
artisan migrate:fresh
artisan db:wipe
artisan db:seed
artisan cron:import
artisan cron:import --fresh
```

User sudah ikut dari database source. `cron:admin-create` hanya digunakan bila
perlu membuat/reset satu Administrator secara sadar. `artisan key:generate`
hanya dijalankan satu kali saat membuat `.env` target pada langkah 12; jangan
menjalankannya ulang setelah aplikasi aktif.

## 15. Apache2 VirtualHost

Salin template dan sesuaikan `ServerName` serta socket. Pada fase awal,
`ServerName` boleh berupa IP server atau hostname sementara dari forwarder:

```bash
cd /var/www/opsifin-scheduler
sudo cp deploy/vps/apache-vhost.conf.template \
  /etc/apache2/sites-available/opsifin-scheduler.conf

sudo editor /etc/apache2/sites-available/opsifin-scheduler.conf
sudo a2dissite 000-default.conf
sudo a2ensite opsifin-scheduler.conf

sudo apache2ctl configtest
sudo systemctl reload apache2
```

Kontrak penting:

- `DocumentRoot` wajib `/var/www/opsifin-scheduler/public`;
- `AllowOverride FileInfo Options` mengaktifkan routing dari `public/.htaccess`;
- `mod_rewrite`, `mod_proxy_fcgi`, `mod_setenvif`, dan `mod_headers` aktif;
- PHP hanya diteruskan ke socket `/run/php/php8.4-fpm.sock`;
- request Laravel dan Livewire yang bukan file fisik masuk ke `index.php`;
- `/build/` mendapat cache immutable;
- `.env`, log, SQL, backup, dan dotfile tidak dapat diunduh;
- upload maksimum sesuai kebutuhan avatar;
- log Apache2 tidak berada di web root.

Smoke test dari server dan jaringan internal sebelum domain/TLS final:

```bash
curl -I http://127.0.0.1/admin/login
curl -I http://10.10.20.15/admin/login
curl -I http://10.10.20.15/livewire/livewire.min.js
```

Respons login `200` atau redirect aplikasi valid. `404` static page biasanya
menandakan `DocumentRoot`, rewrite, atau VirtualHost salah. Respons `503`
biasanya menandakan socket PHP-FPM tidak tersedia atau path socket tidak cocok.

## 16. Mengganti akses sementara menjadi domain dan TLS final

Setelah aplikasi stabil dan domain telah diarahkan:

1. ubah `ServerName` menjadi domain production;
2. ubah `APP_URL` menjadi URL HTTPS final;
3. ubah `ASSET_URL` menjadi origin HTTPS final tanpa slash di belakang;
4. set `SESSION_SECURE_COOKIE=true`;
5. kosongkan `TRUSTED_PROXIES` bila Apache menerima koneksi langsung, atau isi
   hanya IP load balancer/reverse proxy final;
6. clear cache konfigurasi dan reload Apache;
7. terbitkan sertifikat.

```bash
sudo editor /etc/apache2/sites-available/opsifin-scheduler.conf
sudo editor /var/www/opsifin-scheduler/.env
cd /var/www/opsifin-scheduler
sudo -u opsifin_admin php8.4 artisan optimize:clear
sudo -u opsifin_admin php8.4 artisan optimize
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Gunakan Certbot atau mekanisme certificate organisasi. Contoh Certbot:

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d scheduler.example.com
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
- `APP_URL` dan `ASSET_URL` memakai origin HTTPS final;
- cookie/session sesuai domain;
- security header ditambahkan sesuai policy organisasi.

Perubahan hostname membuat session browser lama tidak berlaku pada hostname
baru; user cukup login kembali. Scheduler dan worker tidak perlu dihentikan
hanya karena alamat panel berubah.

## 17. Supervisor Laravel Horizon

Salin template:

```bash
cd /var/www/opsifin-scheduler
sudo cp deploy/vps/supervisor-worker.conf.template \
  /etc/supervisor/conf.d/opsifin-scheduler-horizon.conf

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status opsifin-scheduler-horizon
```

Kontrak worker:

```text
connection             redis
queue                  default
Supervisor processes   1 master Horizon
Horizon workers        2 minimum, 10 maximum
tries                  1
worker timeout         1900 detik
REDIS_QUEUE_RETRY_AFTER 2000 detik
max-time               3600 detik
stopwaitsecs           2000 detik
```

Timeout queue harus lebih besar dari worker timeout agar payload tidak diambil
worker kedua saat worker pertama masih berjalan. Tidak ada automatic HTTP retry.

Saat deployment kode berikutnya:

```bash
sudo -u opsifin_admin php8.4 artisan horizon:terminate
sudo supervisorctl status opsifin-scheduler-horizon
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
* * * * * opsifin_admin cd /var/www/opsifin-scheduler && /usr/bin/php8.4 artisan schedule:run >> /var/log/opsifin-scheduler/scheduler.log 2>&1
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
| `/var/log/opsifin-scheduler/horizon.log` | lifecycle Horizon dan worker |
| `/var/log/opsifin-scheduler/scheduler.log` | output system cron |
| `/var/log/apache2/opsifin-scheduler.error.log` | error Apache2/PHP upstream |
| PHP-FPM log/journal | error process FPM |

## 20. Validasi sebelum runtime dinyalakan

Dengan cron dan worker target masih berhenti:

```bash
cd /var/www/opsifin-scheduler
sudo -u opsifin_admin php8.4 artisan about --only=environment
sudo -u opsifin_admin php8.4 artisan migrate:status
sudo -u opsifin_admin php8.4 artisan route:list --path=admin
sudo -u opsifin_admin php8.4 artisan route:list --path=telescope
sudo -u opsifin_admin php8.4 artisan route:list --path=horizon
sudo -u opsifin_admin php8.4 artisan schedule:list
sudo -u opsifin_admin php8.4 artisan queue:failed
OPSIFIN_APP_URL=http://10.10.20.15
curl -I "${OPSIFIN_APP_URL}/admin/login"
```

Ganti `OPSIFIN_APP_URL` dengan URL sementara atau domain final yang sedang
dipakai. Bila URL sementara berasal dari HTTPS forwarder, gunakan URL HTTPS itu.

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
10. buka Telescope dan Horizon sebagai Administrator dan pastikan role lain ditolak.

## 21. Menyalakan Horizon dan scheduler

Setelah validasi read-only lulus:

```bash
sudo supervisorctl start opsifin-scheduler-horizon
sudo supervisorctl status opsifin-scheduler-horizon
sudo systemctl restart cron
```

Lakukan smoke execution:

1. pilih satu Schedule dengan endpoint yang aman;
2. bila perlu biarkan Schedule paused;
3. klik **Run now**;
4. buka Execution logs;
5. pastikan status bergerak `queued -> running -> succeeded/failed`;
6. verifikasi HTTP status, duration, response excerpt, dan error;
7. periksa Horizon dashboard, Horizon log, dan Laravel log;
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
sudo systemctl status apache2 php8.4-fpm mysql redis-server cron supervisor --no-pager
sudo supervisorctl status opsifin-scheduler-horizon
sudo -u opsifin_admin php8.4 artisan horizon:status
sudo -u opsifin_admin php8.4 artisan schedule:list
sudo -u opsifin_admin php8.4 artisan queue:failed
sudo tail -n 100 /var/log/opsifin-scheduler/scheduler.log
sudo tail -n 100 /var/log/opsifin-scheduler/horizon.log
sudo tail -n 100 /var/www/opsifin-scheduler/storage/logs/laravel.log
```

Pantau dari luar VPS:

- HTTPS login page;
- certificate expiry;
- CPU, RAM, disk, inode;
- MySQL availability dan backup freshness;
- status Redis, Horizon, dan worker process count;
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
8. terminate Horizon secara graceful agar Supervisor memuat release baru;
9. reload PHP-FPM/Apache2 bila perlu;
10. jalankan smoke test;
11. catat commit, migration, dan hasil validasi.

Contoh:

```bash
cd /var/www/opsifin-scheduler
sudo -u opsifin_admin git fetch --tags --prune
sudo -u opsifin_admin git checkout <new-release-tag>
sudo -u opsifin_admin composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
sudo -u opsifin_admin npm ci
sudo -u opsifin_admin npm run build
sudo -u opsifin_admin php8.4 artisan migrate --force
sudo -u opsifin_admin php8.4 artisan optimize
sudo -u opsifin_admin php8.4 artisan horizon:terminate
sudo systemctl reload php8.4-fpm
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Gunakan `artisan down` bila perubahan schema/asset tidak backward compatible.

## 25. Rollback release

1. Pause Schedule berisiko jika eksekusi harus berhenti.
2. Hentikan Horizon bila payload tidak kompatibel dengan kode lama.
3. Checkout tag/commit sebelumnya.
4. Jalankan Composer/npm sesuai lock file release lama.
5. Restore database hanya jika migration tidak backward compatible dan backup
   sudah diverifikasi.
6. Jalankan `optimize:clear`, `optimize`, lalu `horizon:terminate`.
7. Reload service dan smoke test.

Jangan otomatis memakai `migrate:rollback`. Beberapa migration tidak dirancang
sebagai rollback destructive. Gunakan restore backup berdasarkan change plan.

## 26. Backup production

Backup minimum:

- dump MySQL harian dengan enkripsi;
- `APP_KEY`/`.env` melalui secret manager terpisah;
- `storage/app/public` untuk avatar;
- konfigurasi Apache2, Supervisor, cron, TLS, dan logrotate;
- tag/commit serta `composer.lock`/`package-lock.json`;
- backup off-host dengan retention dan restore test.

Backup yang belum pernah direstore belum dapat dianggap valid.

## 27. Troubleshooting cepat

### Login 500 atau asset tidak tampil

```bash
sudo -u opsifin_admin php8.4 artisan optimize:clear
sudo apache2ctl configtest
sudo tail -n 100 /var/log/apache2/opsifin-scheduler.error.log
sudo tail -n 100 storage/logs/laravel.log
```

Periksa permission, `DocumentRoot` `/public`, module rewrite/proxy_fcgi,
`APP_URL`, `ASSET_URL`, dan socket PHP-FPM.

Jika halaman terbuka melalui HTTPS tetapi browser melaporkan **Mixed Content**,
sesuaikan `.env` dengan URL yang benar-benar dibuka user:

```dotenv
APP_URL=https://temporary-url.example
ASSET_URL=https://temporary-url.example
SESSION_SECURE_COOKIE=true
TRUSTED_PROXIES=127.0.0.1,::1
```

Nilai `TRUSTED_PROXIES` di atas hanya benar jika forwarder berjalan pada host
yang sama. Jika tidak, gunakan IP/CIDR sumber reverse proxy yang sebenarnya.
Pastikan forwarder mengirim `X-Forwarded-Proto: https`, lalu muat ulang cache
konfigurasi dan service web:

```bash
cd /var/www/opsifin-scheduler
sudo -u opsifin_admin php8.4 artisan optimize:clear
sudo -u opsifin_admin php8.4 artisan optimize
sudo systemctl reload php8.4-fpm
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Verifikasi `public/build/manifest.json` tersedia. Di DevTools browser, URL CSS,
JavaScript, Livewire, dan gambar harus memakai `https://` atau path relatif,
bukan `http://`. Jika skemanya sudah HTTPS tetapi respons asset `404`, periksa
hasil `npm run build`, Apache `DocumentRoot`, dan permission; kasus itu bukan
masalah trusted proxy.

### Credential masih terlihat seperti ciphertext

Migration `2026_08_19_000005_store_client_credentials_as_plaintext` belum
dijalankan pada source atau dijalankan dengan `APP_KEY` yang salah. Jangan
menyalakan worker. Pulihkan dump source, gunakan key lama satu kali untuk
menjalankan migration konversi, lalu buat ulang dump. Ciphertext lama tidak bisa
dipulihkan tanpa key yang mengenkripsinya.

### Run tertahan di queued

```bash
sudo supervisorctl status opsifin-scheduler-horizon
sudo -u opsifin_admin php8.4 artisan horizon:status
sudo -u opsifin_admin php8.4 artisan queue:failed
sudo tail -n 100 /var/log/opsifin-scheduler/horizon.log
```

### Schedule tidak dispatch

```bash
sudo systemctl status cron --no-pager
sudo -u opsifin_admin php8.4 artisan schedule:run -v
sudo tail -n 100 /var/log/opsifin-scheduler/scheduler.log
```

### Avatar 404

```bash
test -L public/storage
ls -la storage/app/public/avatars
sudo -u opsifin_admin php8.4 artisan storage:link
```

### Telescope tidak merekam request baru

Clear config cache dan restart Horizon:

```bash
sudo -u opsifin_admin php8.4 artisan optimize:clear
sudo -u opsifin_admin php8.4 artisan horizon:terminate
```

## 28. Final go-live checklist

### Security

- [ ] SSH, firewall, TLS, renewal, dan security update siap.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] `.env` permission `0640` (`opsifin_admin:www-data`) atau lebih ketat bila user
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

- [ ] Apache2 dan PHP-FPM sehat.
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
