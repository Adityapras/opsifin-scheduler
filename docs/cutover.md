# Deploy ke VPS — Opsifin Cron

Panduan ini mengasumsikan cutover **bertahap**: aplikasi baru dipasang di VPS dengan
**seluruh schedule nonaktif**, lalu client diaktifkan satu per satu setelah lolos tes.
Crontab legacy tetap hidup selama masa transisi.

---

## 0. Prinsip yang menentukan seluruh langkah di bawah

**Sebuah schedule hanya masuk ke crontab kalau tiga hal ini benar semua:**

| Gerbang | Kolom | Diatur di |
| --- | --- | --- |
| Schedule aktif | `schedules.is_enabled` | Matrix / halaman Schedules |
| Client aktif | `clients.is_active` | Halaman Clients |
| Task template aktif | `task_templates.is_active` | Halaman Task templates |

Sumbernya `CrontabRenderer::enabledSchedules()`. Karena berlapis, `clients.is_active`
bisa dipakai sebagai **saklar induk per client** — persis yang dibutuhkan untuk tes
satu per satu.

> **Risiko yang harus disadari sejak awal:** crontab legacy masih menjalankan 236 entry.
> Kalau client yang sama diaktifkan di sistem baru **tanpa** mematikan baris legacy-nya,
> endpoint akan dipanggil **dua kali**. Untuk task seperti `repost` atau `settlement`
> itu berarti transaksi ganda. Urutan di §6 dibuat untuk mencegah ini.

---

## 1. Prasyarat server

| Komponen | Versi / catatan |
| --- | --- |
| PHP | ≥ 8.3 (CLI + FPM), ekstensi standar Laravel |
| MySQL | 8.x |
| Composer | 2.x |
| Node.js | 20+ — hanya untuk `npm run build` |
| Web server | Nginx + PHP-FPM |
| cron | `cron` (Debian/Ubuntu), `flock` dari `util-linux` |

```bash
php -v && composer -V && node -v
which flock          # harus ada, dipakai setiap baris crontab
```

---

## 2. Pasang aplikasi

```bash
sudo mkdir -p /opt/opsifin-cron
sudo chown $USER:www-data /opt/opsifin-cron
cd /opt/opsifin-cron

git clone <repo-url> .          # atau rsync dari lokal
composer install --no-dev --optimize-autoloader
npm ci && npm run build         # WAJIB — CSS Filament di-compile dari blade app
```

`npm run build` tidak bisa dilewati: theme di `resources/css/filament/admin/theme.css`
men-scan `app/Filament/**` dan `resources/views/filament/**`, jadi CSS harus dibangun
ulang setiap kali ada kelas Tailwind baru di sana.

Kalau tidak ingin memasang Node di VPS, jalankan `npm run build` di lokal lalu kirim
folder `public/build/` ke server.

---

## 3. Konfigurasi `.env`

```bash
cp .env.example .env
php artisan key:generate
```

Isi bagian ini — nilai default-nya ada di `config/opsifin_cron.php`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://cron.opsifin.internal

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=opsifin_cron
DB_USERNAME=opsifin
DB_PASSWORD=<rahasia>

# Sumber repo cron legacy (read-only, hanya untuk cron:import)
CRON_SOURCE_PATH=/opt/legacy-cron

# Target deploy
CRON_DEPLOY_BASE_DIR=/opt/opsifin-cron
CRON_DEPLOY_USER=ubuntu
CRON_D_FILE=/etc/cron.d/opsifin
CRON_PHP_BINARY=/usr/bin/php
CRON_FLOCK_BINARY=/usr/bin/flock

CRON_LOCK_DIR=/var/lock/opsifin
CRON_LOG_DIR=/var/log/opsifin-cron
CRON_DEFAULT_TIMEZONE=Asia/Jakarta
```

`APP_KEY` mengenkripsi kredensial client di tabel `clients`. **Kalau hilang, seluruh
kredensial tidak bisa didekripsi lagi** — simpan di password manager.

---

## 4. Direktori lock & log

```bash
sudo mkdir -p /var/lock/opsifin /var/log/opsifin-cron
sudo chown ubuntu:ubuntu /var/lock/opsifin /var/log/opsifin-cron
sudo chmod 755 /var/lock/opsifin /var/log/opsifin-cron
```

Pemiliknya harus **user yang menjalankan cron** (`CRON_DEPLOY_USER`, default `ubuntu`),
bukan user web. `/var/lock` dipilih alih-alih `/tmp` karena `systemd-tmpfiles` bisa
menghapus lock yang masih dipakai di `/tmp`.

Storage Laravel tetap milik user web:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
```

---

## 5. Skema, user, dan impor data

```bash
php artisan migrate --force
php artisan db:seed --class=UserSeeder     # user admin awal — ganti passwordnya
```

Impor data legacy — **dry run dulu**, laporannya wajib dibaca:

```bash
php artisan cron:import --dry-run --report=storage/app/import-reports/dry-run.md
php artisan cron:import --report=storage/app/import-reports/apply.md
php artisan cron:verify-import
```

`cron:verify-import` membandingkan request hasil impor dengan script `.sh` aslinya.
Angka "Different" yang tidak nol harus ditelusuri sebelum client mana pun diaktifkan.

### 5a. Matikan semuanya sebelum menyentuh crontab

Importer menyalakan `is_enabled` untuk setiap baris crontab yang aktif di sistem lama
(±236 dari 493 schedule). Untuk memulai dari kondisi mati total:

```bash
php artisan tinker --execute="
App\Models\Schedule::query()->update(['is_enabled' => false, 'next_run_at' => null]);
App\Models\Client::query()->update(['is_active' => false]);
"
```

Verifikasi bahwa hasil render benar-benar kosong:

```bash
php artisan cron:render --validate
# Schedules: 0 enabled
```

---

## 6. Deploy pertama — file kosong yang aman

Deploy pertama sengaja dilakukan **saat semuanya masih nonaktif**. Tujuannya menguji
jalur tulis dan hak akses, bukan menjalankan job.

```bash
sudo php artisan cron:render --apply
cat /etc/cron.d/opsifin
```

Isinya harus berupa header managed block plus `# (no enabled schedules)`.

### Kenapa `sudo`, dan kenapa tombol Deploy di UI tidak dipakai di VPS

cron Debian/Ubuntu **menolak file di `/etc/cron.d` yang tidak dimiliki root** atau yang
writable oleh group/other. Artinya proses PHP-FPM (`www-data`) tidak boleh memiliki file
itu — sehingga tombol **Deploy** di halaman *Deploy crontab* akan gagal dengan
notifikasi `No permission to write to /etc/cron.d/opsifin`.

Jadi di VPS:

- **Halaman Deploy crontab dipakai untuk baca saja** — validasi, diff, preview file, daftar backup. Semuanya berjalan tanpa menulis.
- **Penulisan dilakukan lewat SSH** dengan `sudo php artisan cron:render --apply`.

Kalau nanti ingin tombol UI berfungsi, arahkan `CRON_D_FILE` ke file milik aplikasi
(misal `/opt/opsifin-cron/deploy/opsifin.cron`) dan pasang satu baris di
`/etc/cron.d/opsifin` yang tidak berubah-ubah untuk memuatnya — di luar cakupan
panduan ini.

Pastikan permission akhirnya benar:

```bash
sudo chown root:root /etc/cron.d/opsifin
sudo chmod 644 /etc/cron.d/opsifin
```

### 6a. Pasang penjadwal aplikasi

Terpisah dari file yang di-generate aplikasi, tambahkan satu baris ke crontab
user aplikasi (`crontab -e`):

```
* * * * * cd /opt/opsifin-cron && php artisan schedule:run >> /dev/null 2>&1
```

Ini yang menjalankan `cron:check-missed` tiap 5 menit (deteksi cron mati) dan
`cron:purge-runs` tiap 03:15. Pemisahannya disengaja: pemeriksaan missed run
harus tetap hidup justru ketika crontab hasil render bermasalah — kalau keduanya
dijadwalkan di tempat yang sama, satu kegagalan mematikan job sekaligus alarmnya.

Verifikasi:

```bash
php artisan schedule:list
```

---

## 7. Aktifkan client satu per satu

Ulangi blok ini untuk **satu client**, jangan diborong.

Cek kesiapannya lebih dulu — perintah ini mengumpulkan semua syaratnya jadi satu
tabel:

```bash
php artisan cron:cutover-status            # ringkasan semua client
php artisan cron:cutover-status gn         # daftar blocker untuk satu client
php artisan cron:cutover-status --ready    # hanya yang sudah bersih
```

### 7.1 Uji tanpa efek samping

Di panel: **Schedules** → filter client → tombol **Dry run** pada satu baris.
Yang tampil adalah request final (URL, header dengan kredensial ter-mask, body,
timeout) tanpa memanggil endpoint sama sekali.

Lewat CLI:

```bash
php artisan cron:run <schedule_id> --dry-run
php artisan cron:run gn/repost --dry-run     # bisa pakai <client_code>/<task_key>
```

### 7.2 Uji panggilan sungguhan, sekali

Masih dengan crontab legacy hidup, panggil manual **satu kali** di waktu yang Anda pilih
— tombol **Run now** di halaman Schedules, atau:

```bash
php artisan cron:run <schedule_id> --trigger=manual
```

Cek hasilnya di menu **Runs**: status, HTTP status, durasi, dan potongan body respons.
Ini membuktikan base URL, kredensial, header, dan endpoint sudah benar.

> Pilih waktu yang tidak berdekatan dengan jadwal legacy untuk task yang sama, supaya
> tidak ada dua panggilan beruntun.

### 7.3 Cutover client tersebut

Baru di sini legacy dimatikan dan yang baru dinyalakan — **berurutan, jangan terbalik**:

```bash
# 1. Matikan baris legacy milik client ini
sudo nano /path/ke/opsifin_crontab      # comment baris client tsb dengan '#'
```

```
# 2. Di panel: Clients → client ini → aktifkan "Active"
# 3. Di panel: Matrix → baris client ini → menu ⋮ → "Enable all tasks"
#    (atau klik sel satu per satu kalau hanya sebagian task yang di-cutover)
```

```bash
# 4. Validasi lalu deploy
php artisan cron:render --validate
sudo php artisan cron:render --apply
```

### 7.4 Pantau

```bash
tail -f /var/log/opsifin-cron/runner.log
grep CRON /var/log/syslog | tail -20        # bukti cron benar-benar memicu
```

Di panel **Runs**: filter client tersebut, aktifkan filter **Problems only**. Tabelnya
auto-refresh tiap 30 detik. Biarkan berjalan minimal satu siklus penuh task terpanjang
(untuk task harian berarti 1×24 jam) sebelum lanjut ke client berikutnya.

---

## 8. Rollback

Setiap `--apply` membuat backup otomatis di `storage/app/crontab-backups`.

```bash
php artisan cron:rollback --list
sudo php artisan cron:rollback                    # ke backup terakhir
sudo php artisan cron:rollback --backup=<path>    # ke backup tertentu
```

Rollback juga di-backup lebih dulu, jadi langkah ini bisa dibatalkan lagi.

**Rollback tercepat untuk satu client** tanpa menyentuh file: nonaktifkan client di
panel (Clients → Active off), lalu `sudo php artisan cron:render --apply`. Baris client
itu langsung hilang dari crontab. Setelah itu aktifkan kembali baris legacy-nya.

---

## 9. Checklist per client

- [ ] Semua temuan **error** untuk client ini di laporan rekonsiliasi sudah tuntas
- [ ] `Test connection` di halaman Clients berhasil
- [ ] `Dry run` menunjukkan URL, header, dan body yang benar
- [ ] `Run now` sekali berhasil (HTTP 2xx, durasi wajar)
- [ ] Baris legacy client ini sudah di-comment
- [ ] Client di-`Active`, schedule yang dikehendaki di-`Enable`
- [ ] `cron:render --validate` bersih, lalu `--apply`
- [ ] Baris muncul di `/etc/cron.d/opsifin` dengan `flock` dan ekspresi cron yang benar
- [ ] Eksekusi terjadwal pertama tercatat sukses di menu **Runs**
- [ ] Dipantau satu siklus penuh sebelum lanjut ke client berikutnya

---

## 10. Rujukan cepat

| Perintah | Fungsi |
| --- | --- |
| `php artisan cron:render --validate` | Cek semua schedule aktif, tidak menulis apa pun |
| `php artisan cron:render` | Tulis hasil render ke staging `storage/app/crontab-staging/` |
| `php artisan cron:render --show` | Tampilkan isi file lengkap hasil render |
| `sudo php artisan cron:render --apply` | Deploy ke `/etc/cron.d/opsifin` + backup otomatis |
| `php artisan cron:rollback --list` | Daftar backup |
| `sudo php artisan cron:rollback` | Kembalikan ke backup terakhir |
| `php artisan cron:run <id> --dry-run` | Tampilkan request tanpa memanggil endpoint |
| `php artisan cron:run <id>` | Jalankan satu schedule sekarang |
| `php artisan cron:import --dry-run` | Impor legacy tanpa menulis database |
| `php artisan cron:verify-import` | Bandingkan hasil impor dengan script `.sh` asli |

Baris manual di luar blok `# BEGIN/END OPSIFIN-CRON MANAGED BLOCK` tidak pernah
tersentuh oleh `--apply`, sehingga entry lain di file yang sama tetap aman.

### Bentuk satu baris yang dihasilkan

```
# schedule_id=12 client=gn task=repost tz=Asia/Jakarta lock=gn.repost
*/6 * * * * ubuntu /usr/bin/flock -n '/var/lock/opsifin/gn.repost.lock' \
  /usr/bin/php '/opt/opsifin-cron/artisan' cron:run 12 >> /var/log/opsifin-cron/runner.log 2>&1
```

Ada **dua lapis lock**: `flock -n` di baris crontab mencegah proses PHP menumpuk, dan
lock di dalam runner mencatat status `skipped_lock` ke tabel `runs` serta ikut berlaku
untuk tombol **Run now**.

---

## 11. Setelah deploy ulang aplikasi

Setiap kali kode di-update di VPS:

```bash
cd /opt/opsifin-cron
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build          # kalau ada perubahan di app/Filament atau resources/views/filament
php artisan migrate --force
sudo systemctl reload php8.3-fpm # bersihkan OPcache
```

`config:cache` dan `route:cache` **belum** dipakai di project ini. Kalau nanti
diaktifkan untuk produksi, tambahkan `php artisan config:cache && php artisan route:cache`
di akhir — dan ingat mengulanginya setiap kali `.env` berubah.

File `cron.d` **tidak** perlu di-reload manual; daemon cron membacanya ulang otomatis.
