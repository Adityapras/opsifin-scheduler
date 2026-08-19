# Migrasi Database Existing ke VPS

Dokumen ini adalah prosedur resmi untuk memindahkan data Opsifin Scheduler dari
environment development/staging yang sekarang ke database production baru.
Tujuannya adalah mempertahankan seluruh data yang sudah dirapikan melalui UI —
user, client, credential, job template, schedule, execution log, audit history,
dan hasil import — tanpa menjalankan `cron:import` kembali di VPS.

## 1. Prinsip wajib

1. Production menerima **salinan database aplikasi**, bukan hasil parse ulang
   `crontab-legacy`.
2. `APP_KEY` source harus dipakai di production. Credential Client disimpan
   terenkripsi dengan key ini; database tanpa key yang sama tidak dapat membaca
   password, token, dan Secret Key.
3. Source dan target harus berada pada commit/release aplikasi yang kompatibel.
4. Scheduler dan queue source harus dihentikan sebelum final dump agar tidak ada
   job yang berubah di tengah cutover.
5. Jangan menyalakan worker atau system cron target sebelum database, `.env`,
   permission, dan smoke test selesai.
6. File avatar berada di filesystem, bukan database. Pindahkan
   `storage/app/public/avatars` bersama dump database.

`crontab-legacy` boleh disimpan sebagai arsip read-only untuk audit, tetapi
`CRON_SOURCE_PATH` tidak dibutuhkan untuk operasi harian setelah data dipindah.

## 2. Data yang ikut dan tidak ikut

Dump penuh membawa seluruh schema dan data. Setelah restore, data runtime yang
tidak aman dibawa ke server baru dibersihkan.

| Dipertahankan | Dibersihkan di target |
| --- | --- |
| `users` dan role | `sessions` lama |
| `clients` dan credential terenkripsi | `cache` dan `cache_locks` |
| `task_templates` | payload `jobs` lama |
| `schedules` | `job_batches` dan `failed_jobs` lama |
| `runs`/execution history | entry Telescope lama |
| `audit_logs` | lock/runtime sementara |
| `import_runs` dan `import_findings` | |
| `notifications` | |
| tabel `migrations` | |

Jangan membersihkan `runs` hanya karena statusnya historis. Riwayat tersebut
berguna untuk audit dan troubleshooting. Pastikan tidak ada run `queued` atau
`running` sebelum dump final.

## 3. Worksheet sebelum mulai

Isi dan simpan pada change ticket:

| Item | Nilai |
| --- | --- |
| Source host | |
| Source database | |
| Source Git commit | |
| Target host | |
| Target database | |
| Target Git commit/tag | |
| Maintenance mulai | |
| PIC aplikasi | |
| PIC database | |
| Rollback deadline | |
| Lokasi backup terenkripsi | |
| SHA-256 dump | |
| SHA-256 avatar archive | |

Gunakan nama file dengan timestamp UTC agar tidak tertukar, misalnya
`opsifin-scheduler-20260819T120000Z.sql.gz`.

## 4. Preflight source

Jalankan dari root project source:

```bash
git rev-parse HEAD
php artisan about --only=environment
php artisan migrate:status
php artisan schedule:list
php artisan queue:failed
```

Catat jumlah data untuk rekonsiliasi:

```sql
SELECT 'users' AS item, COUNT(*) AS total FROM users
UNION ALL SELECT 'clients', COUNT(*) FROM clients
UNION ALL SELECT 'task_templates', COUNT(*) FROM task_templates
UNION ALL SELECT 'schedules', COUNT(*) FROM schedules
UNION ALL SELECT 'runs', COUNT(*) FROM runs
UNION ALL SELECT 'audit_logs', COUNT(*) FROM audit_logs;

SELECT status, COUNT(*) AS total
FROM runs
GROUP BY status
ORDER BY status;

SELECT COUNT(*) AS queued_payloads FROM jobs;
```

Selesaikan blocker berikut sebelum lanjut:

- migration source belum lengkap;
- ada run `running` yang belum selesai;
- ada run `queued` atau payload `jobs` yang belum diputuskan;
- disk tidak cukup untuk dump dan salinan terkompresi;
- `APP_KEY` source tidak tersedia;
- commit aplikasi source tidak tercatat.

## 5. Amankan APP_KEY tanpa membocorkannya

Jangan mengirim key melalui chat, tiket, screenshot, atau shell history. Salin
nilai `APP_KEY` source ke secret manager organisasi atau transfer `.env` melalui
channel terenkripsi dengan akses terbatas.

Checklist:

- [ ] key dapat dipulihkan oleh minimal dua PIC berwenang;
- [ ] file sementara permission `0600`;
- [ ] key tidak muncul di output CI/CD;
- [ ] target menggunakan `APP_CIPHER` yang sama;
- [ ] key lama belum dihapus sebelum verifikasi credential target.

Pada target migrasi existing, **jangan** menjalankan `artisan key:generate`.

## 6. Quiesce source untuk final dump

Lakukan dalam maintenance window:

1. Pause schedule melalui UI bila source masih mungkin dipakai user.
2. Tunggu seluruh run `running` selesai atau timeout.
3. Cancel run yang masih `queued` melalui Execution logs bila memang tidak akan
   diteruskan.
4. Aktifkan maintenance mode.
5. Hentikan trigger scheduler dan queue worker.

Contoh VPS/systemd-Supervisor:

```bash
php artisan down --retry=60
sudo supervisorctl stop 'opsifin-scheduler-worker:*'
sudo mv /etc/cron.d/opsifin-scheduler /etc/cron.d/opsifin-scheduler.disabled
sudo systemctl reload cron
```

Untuk aaPanel development, hentikan program Supervisor Opsifin dan disable task
aaPanel Cron `artisan schedule:run` dari panel.

Verifikasi ulang:

```sql
SELECT status, COUNT(*) FROM runs
WHERE status IN ('queued', 'running')
GROUP BY status;

SELECT COUNT(*) FROM jobs;
```

Kedua query harus menghasilkan nol. Jika tidak, jangan membuat final dump sampai
statusnya diputuskan. Jangan menghapus payload queue secara massal tanpa
menyesuaikan status Run melalui aplikasi.

## 7. Membuat dump source

Gunakan user database yang memiliki hak membaca seluruh tabel aplikasi. Opsi
`--single-transaction` membuat snapshot konsisten untuk tabel InnoDB tanpa table
lock panjang.

```bash
mkdir -p /secure-backup/opsifin-scheduler
chmod 700 /secure-backup/opsifin-scheduler

mysqldump \
  --host=127.0.0.1 \
  --user=opsifin_cron \
  --password \
  --single-transaction \
  --quick \
  --hex-blob \
  --default-character-set=utf8mb4 \
  --no-tablespaces \
  opsifin_cron \
  | gzip -9 > /secure-backup/opsifin-scheduler/opsifin-scheduler.sql.gz

sha256sum /secure-backup/opsifin-scheduler/opsifin-scheduler.sql.gz \
  > /secure-backup/opsifin-scheduler/opsifin-scheduler.sql.gz.sha256
```

`--password` tanpa nilai akan meminta password secara interaktif dan mencegah
password masuk shell history. Jika MySQL client target/source berbeda versi,
uji restore pada database sementara sebelum maintenance window.

Validasi dump tidak kosong:

```bash
gzip -t /secure-backup/opsifin-scheduler/opsifin-scheduler.sql.gz
zgrep -m1 'CREATE TABLE `clients`' /secure-backup/opsifin-scheduler/opsifin-scheduler.sql.gz
zgrep -m1 'CREATE TABLE `schedules`' /secure-backup/opsifin-scheduler/opsifin-scheduler.sql.gz
```

## 8. Backup avatar dan file aplikasi

Database hanya menyimpan `avatar_path`. Arsipkan file public upload:

```bash
cd /path/source/opsifin-crontab
tar -C storage/app/public -czf \
  /secure-backup/opsifin-scheduler/opsifin-public-files.tar.gz \
  avatars

sha256sum /secure-backup/opsifin-scheduler/opsifin-public-files.tar.gz \
  > /secure-backup/opsifin-scheduler/opsifin-public-files.tar.gz.sha256
```

Jika folder `avatars` belum ada, buat arsip kosong atau catat bahwa tidak ada
file yang perlu dipindahkan.

## 9. Transfer ke target

Gunakan SCP/SFTP/VPN/object storage private sesuai kebijakan organisasi. Jangan
menaruh dump di web root atau repository Git.

Contoh:

```bash
scp /secure-backup/opsifin-scheduler/opsifin-scheduler.sql.gz* \
  deploy@target-vps:/secure-import/opsifin-scheduler/

scp /secure-backup/opsifin-scheduler/opsifin-public-files.tar.gz* \
  deploy@target-vps:/secure-import/opsifin-scheduler/
```

Di target:

```bash
cd /secure-import/opsifin-scheduler
sha256sum -c opsifin-scheduler.sql.gz.sha256
sha256sum -c opsifin-public-files.tar.gz.sha256
gzip -t opsifin-scheduler.sql.gz
```

Jangan lanjut jika checksum berbeda.

## 10. Menyiapkan database target

Buat database kosong dan user terbatas:

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

Pastikan database benar-benar kosong sebelum restore. Jangan import dump ke
database yang sudah diisi `migrate` atau seeder kecuali database tersebut
dihapus dan dibuat ulang terlebih dahulu.

## 11. Restore dump

Worker dan cron target harus masih belum aktif.

```bash
gunzip -c /secure-import/opsifin-scheduler/opsifin-scheduler.sql.gz \
  | mysql --host=127.0.0.1 --user=opsifin_scheduler --password opsifin_scheduler
```

Setelah restore, bersihkan state runtime source:

```sql
USE opsifin_scheduler;
SET FOREIGN_KEY_CHECKS=0;
TRUNCATE TABLE jobs;
TRUNCATE TABLE job_batches;
TRUNCATE TABLE failed_jobs;
TRUNCATE TABLE cache;
TRUNCATE TABLE cache_locks;
TRUNCATE TABLE sessions;
TRUNCATE TABLE telescope_entries_tags;
TRUNCATE TABLE telescope_entries;
TRUNCATE TABLE telescope_monitoring;
SET FOREIGN_KEY_CHECKS=1;
```

Tabel domain, execution log, audit history, user, dan import history tidak
disentuh. Semua user akan login ulang karena session lama dibersihkan.

## 12. Restore avatar

```bash
sudo -u opsifin tar -C /var/www/opsifin-scheduler/storage/app/public -xzf \
  /secure-import/opsifin-scheduler/opsifin-public-files.tar.gz

cd /var/www/opsifin-scheduler
sudo -u opsifin php8.4 artisan storage:link
```

Pastikan `www-data` dapat membaca file dan symlink
`public/storage -> storage/app/public` tersedia.

## 13. Konfigurasi target dengan key source

Isi `.env` target dengan database target dan `APP_KEY` source:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://scheduler.example.com
APP_KEY=<APP_KEY-DARI-SOURCE>

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=opsifin_scheduler
DB_USERNAME=opsifin_scheduler
DB_PASSWORD=<secret-target>

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE=default
DB_QUEUE_RETRY_AFTER=2000
```

`CRON_SOURCE_PATH` boleh dikosongkan atau menunjuk arsip read-only. Jangan
menjalankan `cron:import` pada target.

## 14. Upgrade schema ke release target

Dump membawa tabel `migrations` source. Jalankan migration release target untuk
menerapkan migration yang belum ada:

```bash
cd /var/www/opsifin-scheduler
sudo -u opsifin php8.4 artisan migrate:status
sudo -u opsifin php8.4 artisan migrate --force
sudo -u opsifin php8.4 artisan optimize
```

Selalu backup dump asli sebelum migration. Jangan memakai `migrate:fresh`,
`db:wipe`, seeder, atau `cron:import --fresh`.

## 15. Verifikasi hasil restore

Bandingkan jumlah target dengan worksheet source:

```sql
SELECT 'users' AS item, COUNT(*) AS total FROM users
UNION ALL SELECT 'clients', COUNT(*) FROM clients
UNION ALL SELECT 'task_templates', COUNT(*) FROM task_templates
UNION ALL SELECT 'schedules', COUNT(*) FROM schedules
UNION ALL SELECT 'runs', COUNT(*) FROM runs
UNION ALL SELECT 'audit_logs', COUNT(*) FROM audit_logs;

SELECT COUNT(*) AS queued_payloads FROM jobs;
SELECT status, COUNT(*) FROM runs GROUP BY status ORDER BY status;
```

Uji dekripsi tanpa mencetak secret:

```bash
sudo -u opsifin php8.4 artisan tinker --execute="dump(\App\Models\Client::all()->every(fn (\App\Models\Client \$client) => \$client->auth_secret === null || is_string(\$client->auth_secret)));"
```

Output harus `true`. Error `The MAC is invalid` menandakan `APP_KEY` salah atau
encrypted value rusak. Jangan mengganti credential satu per satu untuk menutupi
kesalahan key; perbaiki key target.

Verifikasi aplikasi sebelum worker/cron aktif:

1. login dengan user hasil transfer;
2. buka Clients dan pastikan password/Secret Key bisa direveal;
3. buka Job Templates, Schedules, Client Job Summary, Execution logs, dan Audit
   history;
4. pastikan avatar tampil;
5. pastikan semua schedule memiliki state yang diharapkan;
6. pastikan tidak ada queue payload lama.

## 16. Menyalakan runtime target

Setelah smoke test read-only lulus:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start 'opsifin-scheduler-worker:*'
sudo supervisorctl status 'opsifin-scheduler-worker:*'

sudo mv /etc/cron.d/opsifin-scheduler.disabled /etc/cron.d/opsifin-scheduler
sudo chmod 0644 /etc/cron.d/opsifin-scheduler
sudo systemctl reload cron
```

Jika file cron baru belum pernah diaktifkan, cukup salin template sesuai panduan
deployment utama. Jalankan `Run now` hanya pada satu endpoint yang aman, lalu
pantau status `queued -> running -> succeeded/failed`.

## 17. Validasi pasca-cutover

- [ ] jumlah tabel domain cocok dengan source;
- [ ] dekripsi credential berhasil tanpa mencetak nilainya;
- [ ] seluruh user yang dibutuhkan dapat login;
- [ ] avatar dapat dibuka dari `/storage/avatars/...`;
- [ ] worker berstatus `RUNNING` sebanyak dua process;
- [ ] hanya ada satu system cron `artisan schedule:run`;
- [ ] satu Run now berhasil;
- [ ] schedule aktif memperoleh `next_run_at`;
- [ ] Execution logs dan Audit history dapat dibuka;
- [ ] Telescope hanya dapat dibuka Administrator;
- [ ] source scheduler/legacy cron tidak berjalan bersamaan untuk job yang sama;
- [ ] backup dan rollback owner masih tersedia.

## 18. Rollback migrasi database

Jika target gagal sebelum schedule production dijalankan:

1. hentikan cron dan worker target;
2. arahkan DNS/reverse proxy kembali bila sudah dialihkan;
3. aktifkan kembali worker dan scheduler source;
4. jalankan smoke test source;
5. simpan database target gagal untuk investigasi, jangan langsung menimpanya.

Jika target sudah mengeksekusi job, tentukan dulu dampak bisnis sebelum kembali
ke source karena kedua database telah divergen. Jangan menyalakan source dan
target bersamaan untuk schedule yang sama.

## 19. Setelah migrasi diterima

1. Enkripsi dan retensi dump sesuai kebijakan backup.
2. Hapus file dump dari folder sementara target.
3. Batasi akses backup dan `.env`.
4. Simpan commit/tag production dan checksum pada change ticket.
5. Jadikan database production sumber kebenaran baru.
6. Jangan menjalankan ulang import legacy; perubahan selanjutnya dilakukan
   melalui module Clients, Job Templates, dan Schedules.
