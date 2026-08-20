# Migrasi Database Existing ke VPS

Dokumen ini adalah prosedur resmi untuk memindahkan data Opsifin Scheduler dari
environment development/staging yang sekarang ke database production baru.
Tujuannya adalah mempertahankan seluruh data yang sudah dirapikan melalui UI —
user, client, credential, job template, schedule, execution log, audit history,
dan hasil import — tanpa menjalankan `cron:import` kembali di VPS.

## 1. Prinsip wajib

1. Production menerima **salinan database aplikasi**, bukan hasil parse ulang
   `crontab-legacy`.
2. Credential Client disimpan plaintext/as-is. Ciphertext dari release lama
   harus dikonversi satu kali pada source sebelum dump; setelah itu target tidak
   membutuhkan `APP_KEY` source.
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
| `clients` dan credential as-is | `cache` dan `cache_locks` |
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
- release yang memuat migration konversi credential belum tersedia di source;
- commit aplikasi source tidak tercatat.

## 5. Konversi credential lama sebelum dump

Release ini menyimpan credential Client secara plaintext/as-is. Bila database
pernah dipakai oleh release yang memakai encrypted cast, migration
`2026_08_19_000005_store_client_credentials_as_plaintext` akan mendekripsi nilai
lama satu kali memakai `APP_KEY` source.

Konversi dijalankan **di source sebelum final dump**, ketika key lama masih
aktif. Command dijalankan pada maintenance window setelah scheduler dan worker
dihentikan pada langkah berikutnya.

Migration berhenti dengan pesan yang jelas bila menemukan ciphertext yang tidak
dapat dibuka. Jangan mengganti credential satu per satu atau meneruskan dump
dalam kondisi itu. Ciphertext lama secara teknis tidak bisa dipulihkan tanpa key
yang mengenkripsinya.

Setelah migration berhasil, semua nilai lama telah ditulis kembali as-is dan
data baru juga langsung disimpan as-is. `APP_KEY` source tidak perlu ditransfer
ke target. Key tetap jangan dikirim melalui chat, tiket, screenshot, atau shell
history selama proses konversi.

Checklist:

- [ ] source menggunakan key lama saat migration dijalankan;
- [ ] migration konversi berstatus `Ran`;
- [ ] tidak ada error `cannot be decrypted`;
- [ ] credential dapat direveal dari UI source setelah migration;
- [ ] backup database diperlakukan sebagai secret karena memuat plaintext.

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

Dengan seluruh proses runtime sudah berhenti, jalankan migration menggunakan
`.env` source yang masih memiliki key lama:

```bash
php artisan migrate --force
php artisan migrate:status
```

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

Pilih salah satu: CLI `mysqldump` atau SQLyog. Hasil keduanya harus berupa full
SQL dump yang memuat struktur dan data seluruh object aplikasi.

### Opsi A — CLI mysqldump

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

### Opsi B — export manual dengan SQLyog

Gunakan koneksi source setelah scheduler/worker dihentikan dan migration
credential plaintext berstatus `Ran`:

1. Pilih database source pada Object Browser.
2. Buka **Database -> Backup/Export -> Backup Database As SQL Dump** atau tekan
   `Ctrl+Alt+E`.
3. Pilih **Structure and Data** dan **Select All** untuk seluruh tables, views,
   triggers, functions, procedures, dan events yang tersedia.
4. Simpan sebagai satu file `.sql` dengan timestamp UTC.
5. Aktifkan **Single transaction** dan jangan aktifkan **Lock all tables**.
6. Aktifkan **Set FOREIGN_KEY_CHECKS=0** dan **Create Bulk Insert statements**.
7. Nonaktifkan **Include USE database**, **Include CREATE database**, dan
   **Include DROP statements**. Target database akan dipilih saat restore.
8. Aktifkan **Ignore DEFINER** bila dump memuat view/routine/trigger agar restore
   tidak bergantung pada account MySQL source.
9. Jalankan export dan pastikan SQLyog melaporkan selesai tanpa error.

Jangan menggunakan **Export Table Data as CSV, SQL, Excel...**. Menu tersebut
ditujukan untuk export data/result set, bukan backup database lengkap.

Validasi file dari PowerShell tanpa membukanya di editor:

```powershell
Get-Item .\opsifin-scheduler-20260819T120000Z.sql
Get-FileHash .\opsifin-scheduler-20260819T120000Z.sql -Algorithm SHA256
```

Simpan nilai hash pada change ticket. File SQL memuat credential Client
plaintext, sehingga wajib disimpan pada disk terenkripsi/arsip berpassword,
tidak dikirim melalui chat, dan tidak diletakkan di repository atau web root.

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

Jika restore dilakukan langsung dari workstation dengan SQLyog, file `.sql`
tidak perlu disalin ke VPS. Gunakan koneksi SQLyog melalui SSH tunnel/VPN dan
tetap simpan hash serta backup asli sampai cutover diterima. Port MySQL `3306`
tidak boleh dibuka ke internet hanya untuk mempermudah import.

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

### Opsi A — CLI

```bash
gunzip -c /secure-import/opsifin-scheduler/opsifin-scheduler.sql.gz \
  | mysql --host=127.0.0.1 --user=opsifin_scheduler --password opsifin_scheduler
```

### Opsi B — import manual dengan SQLyog

1. Hubungkan SQLyog ke MySQL target melalui SSH tunnel/VPN. Pada SSH tab gunakan
   account deploy/infra yang memang boleh login; pada MySQL tab gunakan
   `opsifin_scheduler`. Jangan memberi shell login kepada service user
   `opsifin_admin` hanya untuk kebutuhan SQLyog.
2. Pastikan database `opsifin_scheduler` benar-benar kosong lalu pilih database
   tersebut pada Object Browser.
3. Buka **Tools -> Restore From SQL Dump** atau tekan `Ctrl+Shift+Q`.
4. Pilih file `.sql` hasil export source dan database target
   `opsifin_scheduler`.
5. Aktifkan **Force disable FK checks** bila opsinya tersedia dan pastikan
   eksekusi berhenti ketika menemukan error.
6. Jalankan restore. Jangan menutup SQLyog atau memutus SSH tunnel sampai
   selesai.
7. Review tab Messages/Result: jumlah error harus nol.
8. Refresh Object Browser dan pastikan tabel `migrations`, `clients`,
   `task_templates`, `schedules`, `runs`, `users`, dan `audit_logs` tersedia.

Jika edisi SQLyog yang dipakai tidak menyediakan SSH Tunnel, buat local SSH
port forwarding dengan tool SSH organisasi atau gunakan VPN, lalu arahkan
SQLyog ke port lokal tersebut. Jangan membuka MySQL ke internet.

Jangan memakai schema/data synchronization sebagai pengganti full restore untuk
cutover pertama. Jangan import ke database yang sebelumnya sudah menjalankan
`artisan migrate` atau seeder.

Referensi menu SQLyog:

- [Backup Database as SQL Dump](https://webyog.com/article/116-backup-database-as-sql-dump-batch-scripts/)
- [Connecting using SSH Tunneling](https://sqlyogkb.webyog.com/article/30-connecting-using-ssh-tunneling)
- [Shortcut Backup/Restore SQL Dump](https://sqlyogkb.webyog.com/article/41-keyboard-shortcuts)

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
sudo -u opsifin_admin tar -C /var/www/opsifin-scheduler/storage/app/public -xzf \
  /secure-import/opsifin-scheduler/opsifin-public-files.tar.gz

cd /var/www/opsifin-scheduler
sudo -u opsifin_admin php8.4 artisan storage:link
```

Pastikan `www-data` dapat membaca file dan symlink
`public/storage -> storage/app/public` tersedia.

## 13. Konfigurasi target dengan key baru

Isi `.env` target dengan database target. Credential Client tidak bergantung
pada `APP_KEY`, tetapi Laravel tetap memerlukan key untuk cookie dan layanan
framework:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://scheduler.example.com
APP_KEY=

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

Generate key baru khusus target:

```bash
sudo -u opsifin_admin php8.4 artisan key:generate --force
```

Tidak perlu menyalin `APP_KEY` source ke target.

`CRON_SOURCE_PATH` boleh dikosongkan atau menunjuk arsip read-only. Jangan
menjalankan `cron:import` pada target.

## 14. Upgrade schema ke release target

Dump membawa tabel `migrations` source. Jalankan migration release target untuk
menerapkan migration yang belum ada:

```bash
cd /var/www/opsifin-scheduler
sudo -u opsifin_admin php8.4 artisan migrate:status
sudo -u opsifin_admin php8.4 artisan migrate --force
sudo -u opsifin_admin php8.4 artisan optimize
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

Pastikan migration konversi ikut terbawa dan berstatus `Ran`:

```bash
sudo -u opsifin_admin php8.4 artisan migrate:status
```

Cari `2026_08_19_000005_store_client_credentials_as_plaintext` pada output.
Statusnya harus `Ran`. Bila nilai pada form Client masih berupa string panjang
seperti payload JSON/base64 dan bukan credential asli, hentikan proses: dump
dibuat sebelum konversi source selesai.

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
- [ ] migration konversi credential berstatus `Ran`;
- [ ] credential Client dapat direveal sebagai nilai asli;
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
