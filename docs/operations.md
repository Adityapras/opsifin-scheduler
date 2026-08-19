# Operations Runbook

Runbook ini untuk production VPS manual. Ganti path dan nama service bila
berbeda dari template repository.

## 1. Pemeriksaan rutin

Jalankan dari `/var/www/opsifin-scheduler`:

```bash
sudo -u opsifin /usr/bin/php8.4 artisan schedule:list
sudo -u opsifin /usr/bin/php8.4 artisan queue:failed
sudo supervisorctl status opsifin-scheduler-worker:*
sudo systemctl status cron nginx php8.4-fpm mysql --no-pager
```

Di UI, periksa:

- queued tidak menumpuk lama;
- running tidak melewati timeout task + margin;
- failure rate dan failed terbaru;
- schedule aktif memiliki `next_run_at`;
- schedule paused tidak memiliki `next_run_at`.

## 2. Log map

| Log | Isi |
| --- | --- |
| UI **Execution logs** | Status request: queued/running/succeeded/failed/skipped, HTTP status, duration, response, dan error |
| UI **Audit history** | Perubahan konfigurasi client, job template, dan schedule oleh user |
| `storage/logs/laravel.log` | Exception aplikasi/importer/executor |
| `/var/log/opsifin-scheduler/worker.log` | Lifecycle queue worker |
| `/var/log/opsifin-scheduler/scheduler.log` | Output system cron dan dispatcher |
| `/var/log/nginx/opsifin-scheduler.error.log` | Routing/PHP upstream error |
| PHP-FPM journal/log | Fatal error dan worker FPM |

Jangan menyalin `.env`, Authorization header, atau secret client ke tiket.

## 3. Queue menumpuk

1. Pastikan worker `RUNNING`:

   ```bash
   sudo supervisorctl status opsifin-scheduler-worker:*
   ```

2. Periksa log worker dan Laravel.
3. Pastikan database dapat diakses:

   ```bash
   sudo -u opsifin /usr/bin/php8.4 artisan about --only=environment
   ```

4. Restart worker secara graceful:

   ```bash
   sudo -u opsifin /usr/bin/php8.4 artisan queue:restart
   sudo supervisorctl status opsifin-scheduler-worker:*
   ```

Jangan menghapus tabel `jobs` untuk memperbaiki backlog. Pause schedule yang
menambah beban jika endpoint tujuan bermasalah.

## 4. Schedule tidak dispatch

1. Periksa system cron:

   ```bash
   sudo systemctl status cron --no-pager
   sudo grep opsifin-scheduler /etc/cron.d/opsifin-scheduler
   ```

2. Jalankan entry point sekali sebagai user aplikasi:

   ```bash
   sudo -u opsifin /usr/bin/php8.4 artisan schedule:run -v
   ```

3. Periksa apakah client, template, dan schedule aktif.
4. Periksa cron/timezone serta `next_run_at` di UI.
5. Periksa `scheduler.log` dan `laravel.log`.

Setelah downtime, sistem hanya membuat maksimal satu occurrence terbaru per
schedule. Sistem tidak melakukan replay seluruh menit yang terlewat.

## 5. Run stuck di running

Dispatcher berikutnya melakukan recovery ketika
`execution_deadline_at < now()`. Bila ingin memastikan root cause:

1. periksa apakah process worker masih hidup;
2. periksa timeout/connection endpoint;
3. tunggu timeout task + `CRON_EXECUTION_MARGIN_SEC`;
4. jalankan `schedule:run -v` sekali bila system cron sempat mati;
5. pastikan run menjadi failed dan slot schedule terlepas.

Jangan mengubah `running_run_id` langsung di database selama worker mungkin
masih mengirim request.

Untuk perilaku seperti `flock -n`, pastikan **Skip overlapping run** aktif pada
schedule. Implementasinya adalah atomic database slot, bukan file lock, agar
tetap benar saat worker lebih dari satu.

## 6. Endpoint client sedang bermasalah

1. Pause assignment terkait, gunakan bulk Pause bila banyak.
2. Biarkan request yang sudah running selesai/timeout.
3. Perbaiki endpoint atau credential.
4. Gunakan Run now pada satu client.
5. Jika sukses, resume bertahap.
6. Retry manual hanya untuk failure yang aman diulang.

## 7. Login admin bermasalah

```bash
sudo -u opsifin /usr/bin/php8.4 artisan optimize:clear
sudo -u opsifin /usr/bin/php8.4 artisan route:list --path=admin
sudo tail -n 100 /var/log/nginx/opsifin-scheduler.error.log
sudo tail -n 100 /var/www/opsifin-scheduler/storage/logs/laravel.log
```

Pastikan Nginx root mengarah ke `public`, route `/livewire-*` diteruskan ke
Laravel, database session/cache dapat ditulis, dan URL diakses menggunakan
domain yang sama dengan `APP_URL`.

## 8. Deploy aplikasi

```bash
sudo -u opsifin git pull --ff-only
sudo -u opsifin composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
sudo -u opsifin npm ci
sudo -u opsifin npm run build
sudo -u opsifin /usr/bin/php8.4 artisan migrate --force
sudo -u opsifin /usr/bin/php8.4 artisan optimize
sudo -u opsifin /usr/bin/php8.4 artisan queue:restart
sudo systemctl reload php8.4-fpm
sudo systemctl reload nginx
```

Gunakan maintenance mode bila migration/asset change tidak backward compatible.
Selalu backup database sebelum migration production.

## 9. Retensi dan backup

Preview retensi:

```bash
sudo -u opsifin /usr/bin/php8.4 artisan cron:purge-runs --dry-run
```

Scheduler menjalankan purge setiap hari pukul 03:00. Queued dan running tidak
dihapus.

Backup minimal mencakup:

- dump database terenkripsi/akses terbatas;
- `.env` dan key management secara terpisah;
- konfigurasi Nginx, Supervisor, cron, dan TLS;
- release/tag Git yang sedang berjalan.

## 10. External monitoring

Pantau dari host di luar VPS:

- HTTPS `/admin/login` merespons;
- system cron hidup;
- Supervisor workers running;
- disk/database capacity;
- jumlah queued/failed dan umur run terakhir.

Aplikasi sengaja tidak mempunyai watchdog internal karena watchdog yang berada
di server sama tidak dapat mendeteksi server mati total.

## 11. Import dan cutover legacy

Bagian ini hanya untuk import awal pada environment persiapan. VPS production
yang menerima dump database existing **tidak menjalankan import ulang**. Gunakan
prosedur [database-migration-vps.md](database-migration-vps.md), konversi sekali
credential lama ke plaintext sebelum dump, lalu jadikan database production
sebagai sumber kebenaran.

Importer tidak mengaktifkan schedule dan tidak mengubah legacy cron.
Task template hanya dibentuk dari `jobs/*.sh`; script pada folder client hanya
digunakan untuk memetakan assignment dan mendeteksi perbedaan legacy.

Dry-run:

```bash
php artisan cron:import --fresh --dry-run --report=/tmp/lean-import.md
```

Apply `--fresh` menghapus Clients, Job Templates, Schedules, dan Runs sebelum
import ulang. Backup dan persetujuan eksplisit wajib tersedia sebelum command:

```bash
php artisan cron:import --fresh --report=storage/app/import-reports/lean-apply.md
php artisan cron:verify-import
php artisan cron:cutover-status
```

Cutover dilakukan per client/job group:

1. review seluruh import error dan credential;
2. lakukan Run now pada satu endpoint harmless;
3. nonaktifkan baris legacy yang tepat;
4. resume schedule pengganti melalui UI;
5. pantau minimal dua siklus;
6. rollback dengan Pause schedule baru dan aktifkan kembali baris legacy.

Jangan mengaktifkan runtime lama dan baru untuk job yang sama secara bersamaan.
