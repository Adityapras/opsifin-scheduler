# Deployment VPS 2 GB — Nginx + PHP 8.4-FPM + MySQL Lokal

Panduan step by step memasang Opsifin Scheduler di **satu VPS RAM 2 GB**
(Ubuntu 24.04 LTS) dengan **Nginx**, **PHP 8.4-FPM**, dan **MySQL 8.0 di server
yang sama**, memakai driver eksekusi **direct** (tanpa Redis/Horizon).

Dokumen ini melengkapi, bukan menggantikan:

- [database-migration-vps.md](database-migration-vps.md) — detail dump/restore
  database existing (konversi credential, quiesce, verifikasi);
- [direct-http-operations.md](direct-http-operations.md) — knob direct, health,
  rollback;
- [deployment-vps.md](deployment-vps.md) — varian Apache2 + Redis/Horizon
  (baseline 4 GB).

Ditulis 7 Oktober 2026. Angka memori di bawah adalah **estimasi** dari ukuran
container development (idle) dan belum diukur di VPS target; ukur ulang saat
pilot.

---

## 0. Aturan keras (baca dulu)

Pada 11 September dan 7 Oktober 2026 database development terhapus karena
perintah destruktif. Di server ini:

1. **Jangan pernah** menjalankan `migrate:fresh`, `migrate:refresh`,
   `migrate:reset`, `db:wipe`, atau `cron:import --fresh`.
2. **Jangan pernah** menjalankan `php artisan test` / `phpunit` di server.
   Test memakai `RefreshDatabase` yang mengosongkan database. Server tidak
   perlu dev dependency (`composer install --no-dev`), sehingga PHPUnit memang
   tidak terpasang.
3. **Binary log MySQL wajib ON** (langkah 5) dan **backup harian wajib aktif**
   (langkah 14) sebelum data production masuk.
4. User database aplikasi **tidak punya hak DROP/ALTER** (langkah 5.4). Migration
   dijalankan dengan user terpisah. Akibatnya `migrate:fresh` yang tidak sengaja
   akan gagal, bukan menghapus data.
5. Jangan enable Schedule tanpa keputusan eksplisit; seluruh Schedule datang
   dalam keadaan paused.

---

## 1. Gambaran akhir

```text
Internet ──HTTPS──> Nginx :443 ──fastcgi──> PHP 8.4-FPM (www-data) ──> Laravel/Filament
                                                                          │
cron (1 menit) ──> artisan schedule:run ──> jobs:dispatch-due ──> runs ───┤──> MySQL 8.0 (lokal)
                                                                          │
systemd: opsifin-direct-executor ──> jobs:work-direct (pool 30) ──HTTP──> endpoint Client
```

Budget RAM (estimasi):

| Komponen | Perkiraan |
| --- | ---: |
| OS + agent (contoh VM Google Cloud) | ~300 MB |
| MySQL 8.0 (buffer pool 256 MB, perf schema off) | 350–450 MB |
| Nginx | ~15 MB |
| PHP-FPM (`pm=ondemand`, max 5 child × ~60–90 MB) | 0–450 MB |
| Direct executor (`jobs:work-direct`) | ~80–100 MB |
| `schedule:run` per menit (beberapa detik) | ~70 MB |
| **Total puncak** | **~1,3–1,4 GB** dari 2 GB |
| Swap | 2 GB (pengaman, bukan kapasitas) |

Tidak memasang Redis, Horizon, Supervisor, maupun Node.js di server.

---

## 2. Worksheet

Isi sebelum mulai (jangan simpan password di dokumen ini):

| Item | Contoh |
| --- | --- |
| OS | Ubuntu 24.04 LTS, 2 vCPU, RAM 2 GB, disk SSD ≥ 25 GB |
| Domain | `scheduler.example.com` (A record → IP VPS) |
| Service user | `opsifin_admin` |
| Path aplikasi | `/var/www/opsifin-scheduler` |
| Log | `/var/log/opsifin-scheduler` |
| Backup | `/var/backups/opsifin-scheduler` |
| Database | `opsifin_cron` |
| User DB runtime | `opsifin_app` (DML saja) |
| User DB migration | `opsifin_migrator` (DDL) |
| Repo | URL git repo aplikasi, branch `master` |

Semua perintah dijalankan sebagai user sudo, kecuali yang diawali
`sudo -u opsifin_admin`.

---

## 3. Persiapan OS

### 3.1 Update dan paket dasar

```bash
sudo apt update && sudo apt -y upgrade
sudo apt -y install software-properties-common ca-certificates curl gnupg \
  unzip git acl ufw chrony logrotate cron
sudo timedatectl set-timezone Asia/Jakarta
sudo systemctl enable --now chrony cron
timedatectl   # pastikan "System clock synchronized: yes"
```

Jam yang sinkron penting: start window direct hanya 55 detik.

### 3.2 Swap 2 GB

```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab

# Pakai swap hanya bila benar-benar perlu.
echo 'vm.swappiness=10'          | sudo tee /etc/sysctl.d/60-opsifin.conf
echo 'vm.vfs_cache_pressure=50'  | sudo tee -a /etc/sysctl.d/60-opsifin.conf
sudo sysctl --system
free -h
```

### 3.3 Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'   # baru ada setelah Nginx terpasang; ulangi di langkah 6 bila gagal
sudo ufw enable
sudo ufw status
```

MySQL **tidak** dibuka ke luar (hanya `127.0.0.1`).

### 3.4 Service user dan directory

```bash
sudo adduser --system --group --shell /bin/bash --home /var/www/opsifin-scheduler opsifin_admin
sudo mkdir -p /var/www/opsifin-scheduler /var/log/opsifin-scheduler /var/backups/opsifin-scheduler
sudo chown -R opsifin_admin:opsifin_admin /var/www/opsifin-scheduler
sudo chown opsifin_admin:www-data /var/log/opsifin-scheduler
sudo chmod 2775 /var/log/opsifin-scheduler
sudo chown root:root /var/backups/opsifin-scheduler
sudo chmod 700 /var/backups/opsifin-scheduler
```

---

## 4. PHP 8.4-FPM

### 4.1 Install

Ubuntu 24.04 bawaan PHP 8.3, jadi pakai PPA `ondrej/php`:

```bash
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt -y install php8.4-fpm php8.4-cli php8.4-common php8.4-mysql \
  php8.4-curl php8.4-mbstring php8.4-xml php8.4-zip php8.4-bcmath \
  php8.4-intl php8.4-gd php8.4-opcache php8.4-readline
php8.4 -v
php8.4 -m | grep -E -i 'pdo_mysql|curl|mbstring|intl|gd|pcntl|posix|opcache|zip|bcmath'
```

`pcntl` dan `posix` sudah termasuk di `php8.4-cli`; keduanya dipakai
`jobs:work-direct` untuk menangani SIGTERM. Ekstensi `redis` tidak diperlukan.

### 4.2 php.ini (FPM dan CLI)

```bash
sudo tee /etc/php/8.4/mods-available/opsifin.ini >/dev/null <<'EOF'
; Opsifin Scheduler — VPS 2 GB
memory_limit = 256M
max_execution_time = 120
upload_max_filesize = 10M
post_max_size = 12M
expose_php = Off
date.timezone = Asia/Jakarta

opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 96
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
EOF
sudo phpenmod -v 8.4 opsifin
```

`opcache.validate_timestamps = 0` berarti setiap deploy **wajib**
`systemctl reload php8.4-fpm` (langkah 15).

### 4.3 Pool FPM hemat memori

```bash
sudo cp /etc/php/8.4/fpm/pool.d/www.conf /etc/php/8.4/fpm/pool.d/www.conf.orig
sudo tee /etc/php/8.4/fpm/pool.d/www.conf >/dev/null <<'EOF'
[www]
user = www-data
group = www-data
listen = /run/php/php8.4-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

; ondemand: tidak ada child menganggur yang memakan RAM.
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 20s
pm.max_requests = 300

request_terminate_timeout = 120s
catch_workers_output = yes
EOF
sudo usermod -aG opsifin_admin www-data
sudo php-fpm8.4 -t && sudo systemctl restart php8.4-fpm
sudo systemctl enable php8.4-fpm
```

### 4.4 Composer

```bash
cd /tmp
curl -sS https://getcomposer.org/installer -o composer-setup.php
sudo php8.4 composer-setup.php --install-dir=/usr/local/bin --filename=composer
composer --version
```

---

## 5. MySQL 8.0 lokal

### 5.1 Install dan amankan

```bash
sudo apt -y install mysql-server
sudo systemctl enable --now mysql
sudo mysql_secure_installation   # hapus anonymous user, test DB, disable remote root
```

### 5.2 Tuning 2 GB + binary log ON

```bash
sudo tee /etc/mysql/mysql.conf.d/zz-opsifin.cnf >/dev/null <<'EOF'
[mysqld]
bind-address              = 127.0.0.1
mysqlx                    = OFF
default-time-zone         = '+07:00'
character-set-server      = utf8mb4
collation-server          = utf8mb4_unicode_ci

# Memori — total MySQL ~350–450 MB.
innodb_buffer_pool_size   = 256M
innodb_log_buffer_size    = 16M
innodb_redo_log_capacity  = 256M
max_connections           = 40
table_open_cache          = 400
tmp_table_size            = 32M
max_heap_table_size       = 32M
performance_schema        = OFF
key_buffer_size           = 8M

# Binary log WAJIB ON: satu-satunya jalan point-in-time recovery.
server-id                 = 1
log_bin                   = /var/lib/mysql/binlog
binlog_format             = ROW
binlog_expire_logs_seconds = 604800
max_binlog_size           = 100M
sync_binlog               = 1
EOF
sudo systemctl restart mysql
sudo mysql -e "SHOW VARIABLES WHERE Variable_name IN ('log_bin','innodb_buffer_pool_size','performance_schema','time_zone');"
```

Pastikan `log_bin = ON`.

### 5.3 Database

```bash
sudo mysql -e "CREATE DATABASE opsifin_cron CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 5.4 Dua user: runtime dan migration

Runtime **tidak** punya `DROP`/`ALTER`/`CREATE`, sehingga perintah destruktif
yang tidak sengaja dijalankan aplikasi akan ditolak MySQL.

```bash
# Ganti <PASS_APP> dan <PASS_MIG> dengan password kuat; jangan disimpan di dokumen.
sudo mysql <<'SQL'
CREATE USER 'opsifin_app'@'localhost'      IDENTIFIED BY '<PASS_APP>';
CREATE USER 'opsifin_migrator'@'localhost' IDENTIFIED BY '<PASS_MIG>';

GRANT SELECT, INSERT, UPDATE, DELETE, LOCK TABLES, CREATE TEMPORARY TABLES
  ON opsifin_cron.* TO 'opsifin_app'@'localhost';

GRANT ALL PRIVILEGES ON opsifin_cron.* TO 'opsifin_migrator'@'localhost';
FLUSH PRIVILEGES;
SQL
```

Simpan password migrator di password manager, **bukan** di `.env`.

---

## 6. Nginx

### 6.1 Install

```bash
sudo apt -y install nginx
sudo systemctl enable --now nginx
sudo ufw allow 'Nginx Full'
sudo rm -f /etc/nginx/sites-enabled/default
```

### 6.2 Global tuning ringan

```bash
sudo sed -i 's/^\s*worker_processes .*/worker_processes auto;/' /etc/nginx/nginx.conf
sudo sed -i 's/^\s*# server_tokens off;/server_tokens off;/' /etc/nginx/nginx.conf
```

### 6.3 Virtual host

```bash
sudo tee /etc/nginx/sites-available/opsifin-scheduler >/dev/null <<'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name scheduler.example.com;

    root /var/www/opsifin-scheduler/public;
    index index.php;
    charset utf-8;
    client_max_body_size 12M;

    access_log /var/log/nginx/opsifin-scheduler.access.log;
    error_log  /var/log/nginx/opsifin-scheduler.error.log warn;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml;

    # Livewire menyajikan JS lewat route Laravel berakhiran .js; jangan biarkan
    # rule aset statis mengembalikan 404.
    location ^~ /livewire- {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ^~ /build/ {
        expires 30d;
        access_log off;
        try_files $uri =404;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 120s;
    }

    location ~ /\.(?!well-known).* { deny all; }
    location ~* \.(?:env|log|sql|sqlite|lock|md)$ { deny all; }
}
EOF
sudo ln -s /etc/nginx/sites-available/opsifin-scheduler /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 6.4 HTTPS (setelah DNS mengarah ke VPS)

```bash
sudo apt -y install certbot python3-certbot-nginx
sudo certbot --nginx -d scheduler.example.com --redirect -m <email-ops> --agree-tos
sudo systemctl status certbot.timer   # renew otomatis
```

Bila sementara memakai IP tanpa domain, lewati langkah ini dan set
`APP_URL=http://<IP>`; kembali ke sini setelah domain siap.

---

## 7. Kode aplikasi

### 7.1 Clone

```bash
sudo -u opsifin_admin git clone -b master <URL_REPO> /var/www/opsifin-scheduler
cd /var/www/opsifin-scheduler
sudo -u opsifin_admin git log --oneline -1
```

Untuk repo private, pasang deploy key read-only di
`/var/www/opsifin-scheduler/.ssh` milik `opsifin_admin`.

### 7.2 Dependency PHP (tanpa dev)

```bash
cd /var/www/opsifin-scheduler
sudo -u opsifin_admin composer install --no-dev --prefer-dist \
  --optimize-autoloader --no-interaction
```

`--no-dev` sekaligus memastikan PHPUnit tidak ada di server (aturan keras #2).

### 7.3 Aset frontend — build di luar server

Node.js tidak dipasang di VPS 2 GB. Build di laptop/CI dari commit yang sama:

```bash
# di mesin development, pada commit yang sama dengan server
npm ci && npm run build
tar czf build.tgz -C public build
scp build.tgz <user>@<ip-vps>:/tmp/
```

Di VPS:

```bash
cd /var/www/opsifin-scheduler
sudo -u opsifin_admin tar xzf /tmp/build.tgz -C public
ls public/build/manifest.json
```

### 7.4 Permission

```bash
cd /var/www/opsifin-scheduler
sudo chown -R opsifin_admin:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 0664 {} \;
sudo setfacl -R  -m u:www-data:rwX -m u:opsifin_admin:rwX storage bootstrap/cache
sudo setfacl -dR -m u:www-data:rwX -m u:opsifin_admin:rwX storage bootstrap/cache
```

ACL default menjaga file log/cache baru tetap bisa ditulis oleh FPM
(`www-data`) maupun worker/cron (`opsifin_admin`).

---

## 8. Konfigurasi `.env`

```bash
cd /var/www/opsifin-scheduler
sudo -u opsifin_admin cp .env.example .env
sudo chown opsifin_admin:www-data .env
sudo chmod 0640 .env
sudo -u opsifin_admin php8.4 artisan key:generate --force
sudo -u opsifin_admin nano .env
```

Nilai yang diubah (sisanya biarkan default `.env.example`):

```dotenv
APP_NAME="Opsifin Scheduler"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://scheduler.example.com

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=opsifin_cron
DB_USERNAME=opsifin_app
DB_PASSWORD=<PASS_APP>
DB_TIMEZONE=+07:00

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true      # false bila masih HTTP/IP
CACHE_STORE=database
QUEUE_CONNECTION=database       # driver direct tidak memakai queue; tanpa Redis
TRUSTED_PROXIES=                # isi IP load balancer bila ada di depan Nginx

# Telescope menulis banyak row; matikan di VPS 2 GB kecuali sedang debugging.
TELESCOPE_ENABLED=false

CRON_SOURCE_PATH=
CRON_DEFAULT_TIMEZONE=Asia/Jakarta
CRON_RUNS_RETENTION_DAYS=90
CRON_EXECUTION_DRIVER=direct
CRON_DIRECT_CONCURRENCY=30
CRON_DIRECT_START_WINDOW_SEC=55
CRON_DIRECT_RESPONSE_MAX_BYTES=65536
CRON_RESPONSE_EXCERPT_LENGTH=2000
```

Catatan:

- `CRON_DIRECT_CONCURRENCY=30` adalah default baru (7 Okt 2026). Nilai 30 belum
  di-capacity test; yang diuji 20 dan 40. Dengan 123 Run peak, durasi request
  rata-rata harus < ~13,75 detik agar semua mulai dalam 55 detik.
- `REDIS_*` dan `HORIZON_*` boleh dibiarkan; tidak dipakai pada driver direct.
  Halaman `/horizon` akan error karena Redis tidak ada — itu wajar.
- Jangan menyalin `.env` development; buat dari `.env.example`.

---

## 9. Database

Pilih **satu**.

### Opsi A — memindahkan database development yang sudah ada (disarankan)

Isi development per 7 Oktober 2026: 33 client, 20 template, 687 schedule
(0 enabled). Prosedur lengkap ada di
[database-migration-vps.md](database-migration-vps.md); ringkasannya:

1. **Di mesin development**, hentikan worker lalu dump:

   ```bash
   docker compose stop scheduler direct-executor
   docker exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" \
     --single-transaction --routines --triggers --no-tablespaces \
     --set-gtid-purged=OFF opsifin_cron' > opsifin_cron-$(date +%Y%m%d-%H%M).sql
   tail -1 opsifin_cron-*.sql   # harus "-- Dump completed on ..."
   ```

   Dump berisi credential Client plaintext: transfer hanya lewat `scp`, simpan
   `chmod 600`, hapus setelah restore.

2. **Di VPS**, restore memakai user migrator:

   ```bash
   sudo install -m 600 -o root -g root /tmp/opsifin_cron-*.sql /var/backups/opsifin-scheduler/
   mysql -u opsifin_migrator -p opsifin_cron < /var/backups/opsifin-scheduler/opsifin_cron-*.sql
   ```

3. Jalankan migration yang belum ada (langkah 9.1).

### Opsi B — database kosong

Hanya untuk instalasi baru tanpa data: jalankan langkah 9.1, lalu buat
Administrator:

```bash
sudo -u opsifin_admin php8.4 artisan cron:admin-create --email=<email-admin>
```

### 9.1 Migration — selalu lewat user migrator

Config belum di-cache saat ini, sehingga env override di bawah terbaca.

```bash
cd /var/www/opsifin-scheduler
sudo -u opsifin_admin php8.4 artisan config:clear
sudo -u opsifin_admin php8.4 artisan migrate:status
sudo -u opsifin_admin env DB_USERNAME=opsifin_migrator DB_PASSWORD='<PASS_MIG>' \
  php8.4 artisan migrate --force
```

Hanya `migrate` (tanpa `fresh`/`refresh`/`reset`).

### 9.2 Verifikasi data

```bash
sudo -u opsifin_admin php8.4 artisan tinker --execute='echo "clients=".App\Models\Client::count()." schedules=".App\Models\Schedule::count()." enabled=".App\Models\Schedule::where("is_enabled",true)->count();'
```

Opsi A: `clients=33 schedules=687 enabled=0`.

---

## 10. Optimasi Laravel

```bash
cd /var/www/opsifin-scheduler
sudo -u opsifin_admin php8.4 artisan storage:link
sudo -u opsifin_admin php8.4 artisan optimize
sudo -u opsifin_admin php8.4 artisan filament:optimize
sudo systemctl reload php8.4-fpm
```

Buka `https://scheduler.example.com/admin`, login, cek dashboard.

---

## 11. Direct executor (systemd)

Satu proses daemon; daemon kedua otomatis standby lewat lease DB. Pakai
systemd (tanpa Supervisor) supaya hemat RAM dan otomatis hidup lagi setelah
reboot/OOM.

```bash
sudo tee /etc/systemd/system/opsifin-direct-executor.service >/dev/null <<'EOF'
[Unit]
Description=Opsifin Scheduler direct HTTP executor
After=network-online.target mysql.service
Wants=network-online.target
Requires=mysql.service

[Service]
User=opsifin_admin
Group=www-data
WorkingDirectory=/var/www/opsifin-scheduler
ExecStart=/usr/bin/php8.4 artisan jobs:work-direct
Restart=always
RestartSec=5
# SIGTERM: request aktif diselesaikan, pending tetap untuk proses berikutnya.
KillSignal=SIGTERM
TimeoutStopSec=90
MemoryMax=300M
StandardOutput=append:/var/log/opsifin-scheduler/direct-executor.log
StandardError=append:/var/log/opsifin-scheduler/direct-executor.log

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl daemon-reload
sudo systemctl enable --now opsifin-direct-executor
sudo systemctl status opsifin-direct-executor --no-pager
sudo -u opsifin_admin php8.4 artisan jobs:direct-status
```

`TimeoutStopSec=90` lebih besar dari timeout HTTP 60 detik supaya request
aktif sempat selesai saat restart.

---

## 12. Cron Laravel Scheduler

```bash
sudo tee /etc/cron.d/opsifin-scheduler >/dev/null <<'EOF'
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

* * * * * opsifin_admin cd /var/www/opsifin-scheduler && /usr/bin/php8.4 artisan schedule:run >> /var/log/opsifin-scheduler/scheduler.log 2>&1
EOF
sudo chmod 644 /etc/cron.d/opsifin-scheduler
sudo systemctl restart cron
```

`schedule:run` menjalankan `jobs:dispatch-due` tiap menit, `cron:purge-runs`
pukul 03:00, dan `telescope:prune` pukul 02:30. Dua menit kemudian:

```bash
tail -n 20 /var/log/opsifin-scheduler/scheduler.log
sudo -u opsifin_admin php8.4 artisan schedule:list
```

---

## 13. Logrotate

```bash
sudo tee /etc/logrotate.d/opsifin-scheduler >/dev/null <<'EOF'
/var/log/opsifin-scheduler/*.log /var/www/opsifin-scheduler/storage/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
    su opsifin_admin www-data
}
EOF
sudo logrotate -d /etc/logrotate.d/opsifin-scheduler
```

---

## 14. Backup otomatis (wajib sebelum data production)

### 14.1 Dump harian + retensi 14 hari

```bash
sudo tee /root/.my-opsifin-backup.cnf >/dev/null <<'EOF'
[client]
user=opsifin_migrator
password=<PASS_MIG>
EOF
sudo chmod 600 /root/.my-opsifin-backup.cnf

sudo tee /usr/local/sbin/opsifin-db-backup >/dev/null <<'EOF'
#!/bin/bash
set -euo pipefail
dir=/var/backups/opsifin-scheduler
file="$dir/opsifin_cron-$(date +%Y%m%d-%H%M).sql.gz"
mysqldump --defaults-extra-file=/root/.my-opsifin-backup.cnf \
  --single-transaction --routines --triggers --no-tablespaces \
  --flush-logs opsifin_cron | gzip -6 > "$file.tmp"
mv "$file.tmp" "$file"
chmod 600 "$file"
find "$dir" -name 'opsifin_cron-*.sql.gz' -mtime +14 -delete
EOF
sudo chmod 700 /usr/local/sbin/opsifin-db-backup
```

`--flush-logs` membutuhkan hak `RELOAD`:

```bash
sudo mysql -e "GRANT RELOAD ON *.* TO 'opsifin_migrator'@'localhost';"
```

Jadwalkan pukul 01:30 dan jalankan sekali sekarang:

```bash
echo '30 1 * * * root /usr/local/sbin/opsifin-db-backup >> /var/log/opsifin-scheduler/backup.log 2>&1' \
  | sudo tee /etc/cron.d/opsifin-db-backup
sudo /usr/local/sbin/opsifin-db-backup
sudo ls -lh /var/backups/opsifin-scheduler/
zcat /var/backups/opsifin-scheduler/opsifin_cron-*.sql.gz | tail -1   # "-- Dump completed"
```

### 14.2 Salinan di luar VPS

Backup di disk yang sama ikut hilang bila VPS rusak. Minimal salah satu:

- snapshot disk terjadwal di Google Cloud (Compute Engine → Snapshot schedule);
- salin `/var/backups/opsifin-scheduler/*.sql.gz` ke bucket GCS privat
  (`gcloud storage cp`) lewat cron setelah 01:30.

### 14.3 Point-in-time recovery (ringkas)

1. Restore dump harian terakhir ke database baru (`opsifin_cron_restore`).
2. Putar binlog dari posisi dump sampai sebelum kejadian:
   `mysqlbinlog --stop-datetime="YYYY-MM-DD HH:MM:SS" /var/lib/mysql/binlog.0000NN | mysql -u opsifin_migrator -p opsifin_cron_restore`.
3. Verifikasi, lalu tukar nama database saat aplikasi dihentikan.

Latih prosedur ini sekali sebelum go-live.

---

## 15. Prosedur update (redeploy)

```bash
cd /var/www/opsifin-scheduler
sudo /usr/local/sbin/opsifin-db-backup                 # backup dulu, selalu
sudo -u opsifin_admin php8.4 artisan down
sudo -u opsifin_admin git pull --ff-only
sudo -u opsifin_admin composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
# upload public/build hasil build commit yang sama (langkah 7.3)
sudo -u opsifin_admin php8.4 artisan config:clear
sudo -u opsifin_admin env DB_USERNAME=opsifin_migrator DB_PASSWORD='<PASS_MIG>' php8.4 artisan migrate --force
sudo -u opsifin_admin php8.4 artisan optimize
sudo -u opsifin_admin php8.4 artisan filament:optimize
sudo systemctl reload php8.4-fpm
sudo systemctl restart opsifin-direct-executor
sudo -u opsifin_admin php8.4 artisan up
```

Setelah mengubah `.env`: `optimize:clear`, `optimize`, reload FPM, restart
executor.

---

## 16. Smoke test

| Cek | Perintah / cara | Harapan |
| --- | --- | --- |
| Web | buka `/admin`, login | dashboard tampil |
| Health | `curl -I https://scheduler.example.com/up` | `200` |
| Executor | `php8.4 artisan jobs:direct-status` | executor online, capacity 30 |
| Scheduler | `tail scheduler.log` | `Running scheduled tasks` tiap menit |
| Schedule aktif | tinker count (9.2) | `enabled=0` sampai diputuskan |
| Memori | `free -h`, `ps -eo rss,cmd --sort=-rss \| head` | available > 400 MB, swap ~0 |
| MySQL | `SHOW VARIABLES LIKE 'log_bin'` | `ON` |
| Backup | `ls /var/backups/opsifin-scheduler` | ada dump hari ini |
| Hak DB runtime | `mysql -u opsifin_app -p -e "SHOW GRANTS"` lalu `mysql -u opsifin_app -p -e "CREATE TABLE opsifin_cron.zz_grant_check (id INT)"` | grant hanya DML; CREATE **ditolak** (ERROR 1142) |
| Test connection | menu Clients → Test connection pada client yang disetujui | HTTP 200 |

Jangan menekan Run Now / enable Schedule ke endpoint Client nyata sebelum
endpoint itu disetujui sebagai harmless.

---

## 17. Mengaktifkan Schedule (keputusan terpisah)

1. Pilih satu client pilot dan beberapa Schedule; enable lewat menu Schedules.
2. Pantau satu peak penuh: start lag (`jobs:direct-status`), missed window,
   `free -h`, `top`.
3. Matikan crontab legacy untuk Schedule yang sama agar tidak dobel kirim.
4. Perluas bertahap. Rollback: pause Schedule; untuk berhenti total
   `sudo systemctl stop opsifin-direct-executor` dan nonaktifkan
   `/etc/cron.d/opsifin-scheduler`.

---

## 18. Troubleshooting cepat

| Gejala | Penyebab umum | Tindakan |
| --- | --- | --- |
| 502 Bad Gateway | FPM mati / socket salah | `systemctl status php8.4-fpm`, cek path socket di vhost |
| 500 setelah deploy | cache lama / permission | `optimize:clear`, ulangi 7.4, cek `storage/logs` |
| JS Livewire 404 | blok `^~ /livewire-` hilang | cek vhost 6.3 |
| Run `skipped` (missed start window) | endpoint lambat / executor mati | `jobs:direct-status`, `journalctl -u opsifin-direct-executor` |
| OOM / proses mati | RAM habis | `dmesg -T \| grep -i oom`, turunkan `pm.max_children`, cek swap |
| `Access denied ... DROP` | runtime user dipakai untuk migration | jalankan migration lewat 9.1 |
| Jam log meleset | NTP mati | `timedatectl`, `systemctl restart chrony` |
