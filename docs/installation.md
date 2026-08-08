# Instalasi dari Nol di VPS

Dari Ubuntu 22.04/24.04 kosong sampai aplikasi berjalan dan siap menerima
cutover. Untuk memindahkan client dari crontab lama, lanjut ke
[`cutover.md`](cutover.md) setelah dokumen ini selesai.

Perkiraan waktu: 45–60 menit.

---

## Ringkasan langkah

| # | Langkah | Hasil |
| --- | --- | --- |
| 1 | Paket dasar | PHP 8.3+, MySQL, Nginx, Composer, Node |
| 2 | Database | Skema + user MySQL |
| 3 | User sistem | `opsifin` sebagai pemilik aplikasi & cron |
| 4 | Aplikasi | Kode + dependency + asset ter-build |
| 5 | Konfigurasi | `.env` lengkap, `APP_KEY` tersimpan aman |
| 6 | Direktori | Lock, log, storage dengan pemilik yang benar |
| 7 | Nginx + TLS | Panel bisa diakses lewat HTTPS |
| 8 | Skema & admin | Migrasi + user admin pertama |
| 9 | Cron | `schedule:run` terpasang, `cron.d` siap ditulis |
| 10 | Uji akhir | Semua jalur terverifikasi |

---

## 1. Paket dasar

```bash
sudo apt update && sudo apt upgrade -y

# PHP 8.3 dari repositori ondrej
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update

sudo apt install -y \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl \
  mysql-server nginx git unzip curl util-linux

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node 20 (untuk membangun asset)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

Verifikasi:

```bash
php -v            # 8.3 atau lebih baru
composer -V
node -v
which flock       # /usr/bin/flock — dipakai setiap baris crontab
```

`util-linux` menyediakan `flock`. Tanpa itu setiap baris crontab akan gagal.

---

## 2. Database

```bash
sudo mysql_secure_installation

sudo mysql <<'SQL'
CREATE DATABASE opsifin_cron CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'opsifin'@'localhost' IDENTIFIED BY 'GANTI_DENGAN_PASSWORD_KUAT';
GRANT ALL PRIVILEGES ON opsifin_cron.* TO 'opsifin'@'localhost';
FLUSH PRIVILEGES;
SQL
```

---

## 3. User sistem

Aplikasi dan cron berjalan sebagai user tersendiri, bukan root dan bukan
`www-data`.

```bash
sudo adduser --disabled-password --gecos "" opsifin
sudo usermod -aG www-data opsifin
```

User inilah yang nanti diisi ke `CRON_DEPLOY_USER`, dan yang harus memiliki
direktori lock dan log.

---

## 4. Aplikasi

```bash
sudo mkdir -p /opt/opsifin-cron
sudo chown opsifin:www-data /opt/opsifin-cron

sudo -u opsifin -H bash <<'EOF'
cd /opt/opsifin-cron
git clone <repo-url> .
composer install --no-dev --optimize-autoloader
npm ci && npm run build
EOF
```

**`npm run build` tidak boleh dilewati.** Theme Filament di
`resources/css/filament/admin/theme.css` men-scan `app/Filament/**` dan
`resources/views/filament/**`, jadi CSS harus dibangun dari sumber. Tanpa itu
halaman Matrix tampil rusak.

Kalau tidak ingin memasang Node di VPS: jalankan `npm run build` di mesin lokal,
lalu kirim folder `public/build/` ke server.

---

## 5. Konfigurasi

```bash
cd /opt/opsifin-cron
sudo -u opsifin cp .env.example .env
sudo -u opsifin php artisan key:generate
sudo -u opsifin nano .env
```

Isi minimal:

```dotenv
APP_NAME="Opsifin Cron"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://cron.opsifin.internal
APP_LOCALE=en
APP_FALLBACK_LOCALE=en

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=opsifin_cron
DB_USERNAME=opsifin
DB_PASSWORD=GANTI_DENGAN_PASSWORD_KUAT

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

# Sumber repo cron legacy — read-only, hanya dibaca cron:import.
# Boleh dikosongkan kalau tidak melakukan migrasi.
CRON_SOURCE_PATH=/opt/legacy-cron

# Target deploy
CRON_DEPLOY_BASE_DIR=/opt/opsifin-cron
CRON_DEPLOY_USER=opsifin
CRON_D_FILE=/etc/cron.d/opsifin
CRON_PHP_BINARY=/usr/bin/php
CRON_FLOCK_BINARY=/usr/bin/flock

CRON_LOCK_DIR=/var/lock/opsifin
CRON_LOG_DIR=/var/log/opsifin-cron
CRON_DEFAULT_TIMEZONE=Asia/Jakarta
CRON_RUNS_RETENTION_DAYS=90
```

> **`APP_KEY` mengenkripsi seluruh kredensial client di tabel `clients`.**
> Kalau hilang, semua kredensial tidak bisa didekripsi lagi dan harus diisi
> ulang satu per satu. Simpan di password manager sebelum melanjutkan.

> **`APP_LOCALE=en` bukan kosmetik.** Nilai `id` membuat seluruh label bawaan
> Laravel dan Filament — tombol Save/Cancel, navigasi, pesan validasi, format
> tanggal relatif — dirender dalam bahasa Indonesia.

---

## 6. Direktori lock, log, dan storage

```bash
sudo mkdir -p /var/lock/opsifin /var/log/opsifin-cron
sudo chown opsifin:opsifin /var/lock/opsifin /var/log/opsifin-cron
sudo chmod 755 /var/lock/opsifin /var/log/opsifin-cron

cd /opt/opsifin-cron
sudo chown -R opsifin:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

Pemilik direktori lock harus **user yang menjalankan cron** (`CRON_DEPLOY_USER`),
bukan user web. `/var/lock` dipilih alih-alih `/tmp` karena `systemd-tmpfiles`
bisa menghapus lock yang masih dipakai di `/tmp`.

Rotasi log runner supaya tidak tumbuh tanpa batas:

```bash
sudo tee /etc/logrotate.d/opsifin-cron >/dev/null <<'EOF'
/var/log/opsifin-cron/*.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
    copytruncate
    su opsifin opsifin
}
EOF
```

---

## 7. Nginx + TLS

```bash
sudo tee /etc/nginx/sites-available/opsifin-cron >/dev/null <<'EOF'
server {
    listen 80;
    server_name cron.opsifin.internal;
    root /opt/opsifin-cron/public;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }

    client_max_body_size 16M;
}
EOF

sudo ln -sf /etc/nginx/sites-available/opsifin-cron /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Kalau host ini terjangkau dari internet, pasang sertifikat:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d cron.opsifin.internal
```

Kalau hanya di jaringan internal, batasi aksesnya di firewall:

```bash
sudo ufw allow from 10.0.0.0/8 to any port 80,443 proto tcp
sudo ufw allow OpenSSH
sudo ufw enable
```

Panel ini menyimpan kredensial seluruh client — jangan biarkan terbuka ke publik
tanpa TLS.

---

## 8. Skema database & user admin

```bash
cd /opt/opsifin-cron
sudo -u opsifin php artisan migrate --force
```

Buat admin pertama:

```bash
sudo -u opsifin php artisan tinker --execute="
App\Models\User::create([
    'name'      => 'Aditya Prasetyo',
    'email'     => 'aditya.prasetyo@opsigo.com',
    'password'  => bcrypt('GANTI_PASSWORD_INI'),
    'role'      => App\Enums\UserRole::Admin,
    'is_active' => true,
]);
"
```

> `php artisan db:seed --class=UserSeeder` juga tersedia, tapi seeder itu membuat
> tiga user contoh dengan password `password`. **Jangan dipakai di produksi.**

Coba login di `https://cron.opsifin.internal/admin`.

---

## 9. Cron

Dua hal terpisah, dan pemisahannya disengaja.

### 9.1 Penjadwal aplikasi

```bash
sudo -u opsifin crontab -e
```

Tambahkan:

```
* * * * * cd /opt/opsifin-cron && php artisan schedule:run >> /dev/null 2>&1
```

Ini menjalankan `cron:check-missed` tiap 5 menit dan `cron:purge-runs` tiap
03:15. Verifikasi:

```bash
sudo -u opsifin php artisan schedule:list
```

### 9.2 File cron.d untuk job

File ini **di-generate aplikasi**, tidak ditulis tangan. Sekarang cukup pastikan
jalur tulisnya berfungsi:

```bash
cd /opt/opsifin-cron
sudo -u opsifin php artisan cron:render --validate    # harus: 0 enabled
sudo php artisan cron:render --apply

cat /etc/cron.d/opsifin
sudo chown root:root /etc/cron.d/opsifin
sudo chmod 644 /etc/cron.d/opsifin
```

Isinya harus berupa header managed block plus `# (no enabled schedules)` —
belum ada job sama sekali. Itu memang yang diharapkan pada instalasi baru.

> **Kenapa `sudo`, dan kenapa tombol Deploy di UI tidak dipakai di VPS.**
> cron Debian/Ubuntu menolak file di `/etc/cron.d` yang tidak dimiliki root atau
> yang writable oleh group/other. Artinya proses PHP-FPM tidak boleh memiliki
> file itu — sehingga tombol **Deploy** di panel akan gagal dengan notifikasi
> `No permission to write to /etc/cron.d/opsifin`. Gagalnya terang, bukan
> diam-diam.
>
> Di VPS: **halaman Deploy crontab dipakai untuk baca saja** (validasi, diff,
> preview, daftar backup), sedangkan penulisan lewat SSH dengan `sudo`.

---

## 10. Uji akhir

```bash
cd /opt/opsifin-cron

# Aplikasi
sudo -u opsifin php artisan about | head -20

# Database
sudo -u opsifin php artisan tinker --execute="echo App\Models\User::count().' user';"

# Penjadwal
sudo -u opsifin php artisan schedule:list

# Jalur tulis crontab
sudo php artisan cron:render --validate

# Direktori lock bisa ditulis user cron
sudo -u opsifin touch /var/lock/opsifin/.probe && sudo -u opsifin rm /var/lock/opsifin/.probe && echo "lock dir OK"

# Log bisa ditulis
sudo -u opsifin touch /var/log/opsifin-cron/runner.log && echo "log dir OK"
```

Lalu di browser:

- [ ] Login berhasil
- [ ] Dashboard terbuka, keempat widget muncul
- [ ] Menu Matrix terbuka tanpa tampilan rusak (bukti asset ter-build)
- [ ] Halaman Deploy crontab menampilkan preview dan daftar backup
- [ ] Tombol/label berbahasa Inggris (bukti `APP_LOCALE=en`)

---

## 11. Mengisi data

Dua jalur, pilih salah satu.

### A. Impor dari crontab legacy

Untuk migrasi dari sistem lama. Salin repo cron legacy ke VPS (read-only sudah
cukup), set `CRON_SOURCE_PATH`, lalu:

```bash
sudo -u opsifin php artisan cron:import --dry-run --report=storage/app/import-reports/dry-run.md
# baca laporannya sebelum melanjutkan
sudo -u opsifin php artisan cron:import --report=storage/app/import-reports/apply.md
sudo -u opsifin php artisan cron:verify-import
```

Lalu **matikan semuanya** sebelum menyentuh crontab, dan lanjut ke
[`cutover.md`](cutover.md):

```bash
sudo -u opsifin php artisan tinker --execute="
App\Models\Schedule::query()->update(['is_enabled' => false, 'next_run_at' => null]);
App\Models\Client::query()->update(['is_active' => false]);
"
```

### B. Mulai dari kosong

Buat client dan task template lewat panel. Langkahnya ada di
[`user-guide.md`](user-guide.md) §3 dan §4.

---

## 12. Memperbarui aplikasi

```bash
cd /opt/opsifin-cron
sudo -u opsifin git pull
sudo -u opsifin composer install --no-dev --optimize-autoloader
sudo -u opsifin npm ci && sudo -u opsifin npm run build   # bila ada perubahan UI
sudo -u opsifin php artisan migrate --force
sudo systemctl reload php8.3-fpm                          # bersihkan OPcache
```

`config:cache` dan `route:cache` belum dipakai di project ini. Kalau nanti
diaktifkan untuk produksi, tambahkan `php artisan config:cache` dan
`route:cache` di akhir — dan ingat mengulanginya setiap kali `.env` berubah.

File `cron.d` **tidak** perlu di-reload manual; daemon cron membacanya ulang
otomatis.

---

## Masalah yang sering muncul saat instalasi

| Gejala | Sebab |
| --- | --- |
| Panel tampil tanpa gaya / Matrix berantakan | `npm run build` belum dijalankan, atau `public/build` tidak ikut terkirim |
| Tombol berbahasa Indonesia | `APP_LOCALE` masih `id` |
| `500` setelah login | Permission `storage/` — pastikan `opsifin:www-data` dan `775` |
| `cron:render --apply` ditolak | Normal kalau dijalankan tanpa `sudo`. Lihat §9.2 |
| Job tidak jalan padahal barisnya ada | `/etc/cron.d/opsifin` bukan milik `root:root`, atau mode-nya bukan `644` |
| `Cannot create the lock directory` | `/var/lock/opsifin` belum ada atau bukan milik `CRON_DEPLOY_USER` |
| Kredensial client kosong semua | `APP_KEY` berubah setelah data terisi — tidak bisa dipulihkan, harus diisi ulang |
