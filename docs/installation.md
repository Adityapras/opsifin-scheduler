# Development Setup — WSL2 dan aaPanel

aaPanel hanya dipakai sebagai development environment di WSL. Production
mengikuti [deployment-vps.md](deployment-vps.md) tanpa aaPanel.

Path standar repository:

```text
/home/aditya_prasetyo/project/opsifin-crontab
```

## 1. Komponen development

- WSL2 Ubuntu;
- aaPanel Nginx, PHP 8.4, dan MySQL;
- PHP CLI `/www/server/php/84/bin/php`;
- Redis server dengan persistence AOF;
- Predis sebagai client Redis PHP;
- Laravel Horizon;
- Supervisor aaPanel;
- satu aaPanel Cron task untuk Laravel Scheduler;
- host lokal `opsifin-cron.local`.

## 2. Preflight

```bash
cd /home/aditya_prasetyo/project/opsifin-crontab
bash deploy/aapanel/preflight-check.sh
```

Periksa manual bila ada FAIL:

```bash
/www/server/php/84/bin/php -v
/www/server/php/84/bin/php -m
mysql --version
node --version
npm --version
```

Extension minimum: curl, fileinfo, intl, mbstring, openssl, pdo_mysql,
bcmath, pcntl, posix, dan xml. Predis tidak membutuhkan extension `phpredis`,
tetapi Redis server tetap wajib terpasang dan berjalan.

Pasang Redis server melalui aaPanel App Store atau package OS. Untuk Redis lokal,
batasi listener ke loopback, aktifkan AOF (`appendonly yes`, `appendfsync everysec`),
dan gunakan `maxmemory-policy noeviction`.

## 3. Install dependency

```bash
cd /home/aditya_prasetyo/project/opsifin-crontab
/www/server/php/84/bin/php /usr/bin/composer install
npm ci
cp .env.example .env
/www/server/php/84/bin/php artisan key:generate
```

Jika `composer` berada di path lain, jalankan PHP aaPanel terhadap binary/phar
Composer yang benar. Jangan mencampur PHP system lama dengan PHP 8.4 aaPanel.

## 4. Database development

Buat database `opsifin_cron` dan user lokal melalui aaPanel atau MySQL CLI,
lalu isi `.env`:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://opsifin-cron.local

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=opsifin_cron
DB_USERNAME=opsifin_cron
DB_PASSWORD=<local-secret>

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_QUEUE_CONNECTION=queue
REDIS_QUEUE_DB=2
REDIS_QUEUE_RETRY_AFTER=2000
HORIZON_MIN_PROCESSES=2
HORIZON_MAX_PROCESSES=10
HORIZON_TIMEOUT=1900
```

Jangan mengirim isi `.env` atau password database ke chat/log.

Jalankan migration:

```bash
/www/server/php/84/bin/php artisan migrate
```

Rewrite lean menambah compatibility migration tanpa menghapus kolom/tabel lama.
Untuk data legacy yang sebelumnya memakai request variant, lakukan dry-run dan
fresh import terkontrol setelah backup. Jangan menjalankan `migrate:fresh` pada
database yang masih perlu dipertahankan.

## 5. Permission

Nginx/PHP-FPM aaPanel pada setup ini berjalan sebagai user `www`:

```bash
sudo chown -R aditya_prasetyo:www storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 0664 {} \;
sudo -u www test -r public/index.php
sudo -u www test -w storage/logs
sudo -u www test -w bootstrap/cache
```

Sesuaikan owner developer jika username WSL berbeda. Jangan memberi mode 777
ke seluruh repository.

## 6. Build frontend

```bash
npm run build
test -f public/build/manifest.json
```

Gunakan `npm run dev` hanya selama pengembangan aktif. Setup Nginx biasa
menggunakan asset hasil `npm run build`.

## 7. Membuat website aaPanel

Di aaPanel:

1. **Website → Add site**.
2. Domain: `opsifin-cron.local`.
3. Root: `/home/aditya_prasetyo/project/opsifin-crontab/public`.
4. PHP version: 8.4.
5. Jangan membuat database baru bila database langkah 4 sudah ada.
6. Ganti vhost dengan isi yang disesuaikan dari
   `deploy/aapanel/nginx-vhost.conf.template`.

Rule penting:

```nginx
root /home/aditya_prasetyo/project/opsifin-crontab/public;

location ^~ /livewire- {
    try_files $uri $uri/ /index.php?$query_string;
}

location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

Prefix `^~ /livewire-` mencegah rule static `.js` aaPanel mengembalikan
`public/404.html` untuk asset Livewire.

## 8. Hosts Windows

Tambahkan sebagai Administrator ke
`C:\Windows\System32\drivers\etc\hosts`:

```text
127.0.0.1 opsifin-cron.local
```

Jika port WSL tidak dipublikasikan otomatis, periksa `wsl --status`, firewall,
dan listener Nginx.

## 9. Test dan reload Nginx

Jalankan command dalam satu baris:

```bash
sudo /www/server/nginx/sbin/nginx -t -c /www/server/nginx/conf/nginx.conf
```

Jangan menulis backslash `\` yang diikuti spasi. Shell akan meneruskan spasi
sebagai argument dan Nginx dapat menampilkan `invalid option: " "`.

Jika valid:

```bash
sudo /www/server/nginx/sbin/nginx -s reload
curl -I -H 'Host: opsifin-cron.local' http://127.0.0.1/admin/login
```

Troubleshooting 404:

1. pastikan request memakai header Host/domain yang tepat;
2. pastikan vhost termuat oleh config utama;
3. pastikan root berakhir di `/public`;
4. pastikan `try_files` jatuh ke `/index.php`;
5. lihat `/www/wwwlogs/opsifin-cron.local.error.log`.

## 10. Admin login

Buat akun secara interaktif:

```bash
/www/server/php/84/bin/php artisan cron:admin-create --email=admin@example.com
```

Lalu:

```bash
/www/server/php/84/bin/php artisan optimize:clear
/www/server/php/84/bin/php artisan route:list --path=admin
```

Buka `http://opsifin-cron.local/admin/login`. Password harus tersembunyi secara
default. Jika input tampil seperti text atau submit tidak bekerja:

1. buka DevTools Network;
2. pastikan request `/livewire-*/livewire.min.js` berstatus 200 dan JavaScript;
3. pastikan console tidak memuat error Livewire;
4. rebuild asset dan clear optimize cache;
5. periksa rule Nginx `/livewire-`.

Masalah tersebut biasanya routing asset Livewire, bukan tipe field password di
source form.

## 11. Supervisor Horizon

Gunakan fitur Supervisor aaPanel atau template
`deploy/aapanel/supervisor-worker.conf.template`:

```text
directory=/home/aditya_prasetyo/project/opsifin-crontab
command=/www/server/php/84/bin/php artisan horizon
user=www
numprocs=1
stopwaitsecs=2000
```

Sesudah config berubah, reread/update melalui aaPanel atau supervisorctl.
Pastikan satu process master Horizon berstatus RUNNING; Horizon mengelola jumlah
worker berdasarkan `HORIZON_MIN_PROCESSES` dan `HORIZON_MAX_PROCESSES`.

## 12. aaPanel Cron

Buat satu task **Shell Script**, period setiap menit, berisi satu baris:

```bash
cd /home/aditya_prasetyo/project/opsifin-crontab && /www/server/php/84/bin/php artisan schedule:run
```

Jangan membuat cron Linux/aaPanel per job. Laravel Scheduler menjalankan:

- `jobs:dispatch-due` setiap menit;
- `cron:purge-runs` setiap hari pukul 03:00.
- `horizon:snapshot` setiap lima menit.

Verifikasi:

```bash
CACHE_STORE=array /www/server/php/84/bin/php artisan schedule:list
/www/server/php/84/bin/php artisan schedule:run -v
```

## 13. Import legacy development

Set `CRON_SOURCE_PATH` ke repo legacy read-only, kemudian:

```bash
/www/server/php/84/bin/php artisan cron:import --fresh --dry-run --report=storage/app/import-reports/dry-run.md
/www/server/php/84/bin/php artisan cron:verify-import
```

Apply biasa:

```bash
/www/server/php/84/bin/php artisan cron:import --fresh --report=storage/app/import-reports/apply.md
```

Import selalu paused. `--fresh` menghapus data domain sebelum reimport dan hanya
boleh dijalankan setelah backup/konfirmasi eksplisit.

## 14. Verifikasi kode

```bash
/www/server/php/84/bin/php artisan test --compact
/www/server/php/84/bin/php vendor/bin/pint --test
CACHE_STORE=array /www/server/php/84/bin/php artisan schedule:list
npm run build
```

## 15. Runtime setelah WSL restart

Start aaPanel/Nginx, MySQL, PHP-FPM, cron, dan Supervisor. Helper tersedia di:

```text
deploy/aapanel/wsl-start-services.sh
deploy/aapanel/install-windows-startup.ps1
```

Script PowerShell memasang Windows Scheduled Task dan harus dijalankan sebagai
Administrator setelah distro/path diverifikasi.

## 16. Checklist development siap

- [ ] PHP 8.4 extensions lengkap.
- [ ] MySQL dapat diakses dari Laravel.
- [ ] Migration berhasil.
- [ ] `public/build/manifest.json` ada.
- [ ] Nginx test sukses dan root mengarah ke `public`.
- [ ] Login serta asset Livewire merespons.
- [ ] Dua worker Supervisor RUNNING.
- [ ] Hanya satu aaPanel Cron `schedule:run`.
- [ ] Assignment hasil import tetap paused.
