# Current Handoff — Opsifin Scheduler

Last updated: **19 August 2026, Asia/Bangkok**

Ini adalah memory utama lintas sesi. Baca file ini dan
[`architecture.md`](architecture.md) sebelum melanjutkan.

## Keputusan yang berlaku

- Arsitektur aktif adalah Lean Laravel Scheduler.
- Production memakai VPS manual tanpa aaPanel.
- WSL + aaPanel hanya untuk development.
- Job dibuat sekali lalu di-assign ke client dari UI; tidak perlu menambah code
  atau crontab pada setiap client.
- Tidak ada automatic retry, full catch-up, runtime override, incident engine,
  atau internal watchdog.
- Jangan enable schedule atau mengubah legacy cron tanpa persetujuan user.

## Status implementasi

Sudah selesai:

- `schedule:run` → `jobs:dispatch-due` → database queue → HTTP worker;
- one-running-slot per schedule dan deadline recovery;
- success/failed/skipped history, Run now, dan Retry manual;
- seluruh row action tabel dikelompokkan dalam ActionGroup;
- overlap guard per schedule aktif secara default untuk skip occurrence ketika
  run sebelumnya masih aktif;
- menu Runs diperjelas menjadi Execution logs dan Audit history sudah dapat
  merender perubahan JSON yang berisi boolean/nested value;
- Assign all active clients;
- Assign selected clients;
- Remove from selected clients;
- Set cron in bulk;
- Pause dan Resume in bulk;
- password/credential input hidden, revealable, dan tidak mengirim secret lama
  ke browser saat edit;
- redaction response/error/request preview;
- importer memakai `crontab-legacy/jobs/*.sh` sebagai satu-satunya katalog
  canonical dan tidak membuat varian per client;
- schema unique client × task × cron expression dan due index;
- VPS serta aaPanel development templates.

Automated UI tests benar-benar memanggil seluruh bulk action melalui Filament
Livewire, bukan hanya menguji service di bawahnya.

## Dokumentasi kanonik

Hanya enam file berikut yang dipertahankan:

```text
docs/architecture.md       arsitektur dan flow developer
docs/user-guide.md         cara memakai UI
docs/installation.md       development WSL + aaPanel
docs/deployment-vps.md     production VPS manual
docs/operations.md         runbook, troubleshooting, import, cutover
docs/handoff.md            state pekerjaan terbaru
```

Dokumen V2/historis/duplikat sudah dihapus agar tidak menjadi sumber instruksi
yang bertentangan.

## Database development saat ini

Fresh import canonical jobs **sudah diterapkan** pada 19 August 2026:

```text
clients                    40
task templates             20
canonical files in jobs/   20
schedules                 450
runs with overlap guard   450
runs                        0
enabled schedules           0
next_run_at terisi           0
database queue payloads      0
duplicate client/task/cron   0
multi-timing client/tasks   30
```

Safety boundary aman: seluruh schedule paused dan legacy cron tidak diubah.

Migration yang sudah Ran:

```text
2026_08_18_000001_prepare_lean_scheduler
2026_08_18_000002_enforce_lean_schedule_uniqueness
2026_08_19_000001_allow_multiple_timings_per_client_job
2026_08_19_000002_add_overlap_guard_to_schedules
```

Database development mungkin masih memiliki tabel/kolom V2 lama secara fisik;
runtime lean tidak membacanya. Fresh production database hanya membuat schema
aktif.

## Backup sebelum fresh import

```text
File   storage/app/backups/before-lean-fresh-import-20260818-102937.sql
Mode   600
Size   269721 bytes
Tables 23
SHA256 1633adf6d4dd3766ea52e8b4525f47461e8801d079791dd6518b8eb175d519b8
```

Dump selesai normal dan memiliki end marker. Jangan commit atau membagikan file
backup karena berisi data database terenkripsi dan metadata internal.

## Import report dan verifikasi

Apply report terbaru:

```text
storage/app/import-reports/canonical-jobs-apply-20260819.md
```

File bermode `600` dan di-ignore Git.

Verifikasi katalog template terhadap `crontab-legacy/jobs/`:

```text
20 exact match
 0 different
 0 skipped
```

Finding aktif:

| Severity/category | Jumlah | Status |
| --- | ---: | --- |
| Error: active task tidak ada di `jobs/` | 19 | Tidak dibuatkan template; tambah file canonical atau hapus cron legacy |
| Error: credential drift | 5 | Harus konfirmasi credential yang benar |
| Error: unresolved URL variable | 2 | QA env variable tidak tersedia pada source |
| Error: dangling URL | 1 | Script legacy memang malformed |
| Warning: commented task tidak ada di `jobs/` | 24 | Kandidat cleanup; tidak dibuatkan schedule |
| Warning lainnya | 16 | Folder/script/client legacy tidak tersedia atau base URL conflict |
| Info: script berbeda dari canonical | 227 | Expected; definisi `jobs/` tetap dipakai |

Tidak ada perbaikan otomatis yang aman untuk 27 error tersebut; source atau
keputusan bisnis tambahan diperlukan. Importer sengaja tidak menebak task yang
tidak mempunyai file canonical.

`cron:cutover-status` saat ini melaporkan 27 dari 40 client configuration-ready.
13 client blocked/review: aladin, anta, demo, globalwisata, gns, kia,
kiaxxxharmoni, pij, psa, psa-gw, qa1, qa2, dan qaAladin.

## Import safety guard

`cron:import` sekarang menolak database domain yang sudah berisi data kecuali
`--fresh` diberikan. Ini mencegah re-import ambigu.

```bash
# Selalu backup lebih dahulu
php artisan cron:import --fresh --dry-run --report=/tmp/lean-import.md
php artisan cron:import --fresh --report=storage/app/import-reports/lean-apply.md
```

Initial import pada database domain kosong tetap dapat dijalankan tanpa
`--fresh`.

## Verifikasi terakhir

```text
Pint                         passed
PHPUnit                      52 tests, 201 assertions, all passed
Vite production build        passed
Laravel schedule:list        dispatch tiap menit + purge pukul 03:00
GET /admin/login              HTTP 200
Login password               hidden by default, Livewire loaded
Importer apply/verify         passed
Enabled/run/queue             0 / 0 / 0
```

## Runtime development yang masih perlu dinyalakan

Audit process terakhir tidak menemukan `supervisord`, `queue:work`, atau daemon
cron. Nginx/login tetap hidup. Akses `/etc/supervisor` memerlukan sudo password,
jadi agent tidak dapat menyalakannya dari sesi ini.

Melalui aaPanel:

1. Buka **App Store**, install/buka **Supervisor Manager**.
2. Hentikan konfigurasi Opsifin lama yang memakai queue `high,default,slow`
   bila masih ada.
3. Tambahkan program dari `deploy/aapanel/supervisor-worker.conf.template`:

   ```text
   Name       opsifin-scheduler-worker
   Directory  /home/aditya_prasetyo/project/opsifin-crontab
   User       www
   Processes  2
   Autostart  yes
   Restart    yes
   ```

4. Start command harus satu baris:

   ```bash
   /www/server/php/84/bin/php artisan queue:work database --queue=default --sleep=1 --tries=1 --timeout=1900 --max-time=3600
   ```

5. Pastikan dua process worker berstatus **RUNNING**.
6. Buka **aaPanel Cron**, buat task type **Shell Script**, period **setiap 1
   menit**, execute user `www`, dengan script satu baris:

   ```bash
   cd /home/aditya_prasetyo/project/opsifin-crontab && /www/server/php/84/bin/php artisan schedule:run
   ```

7. Jangan menulis `* * * * *` di textarea script dan jangan membuat cron per
   job/client. Hanya satu task `schedule:run`.

Verifikasi setelah dinyalakan:

```bash
ps -ef | rg 'queue:work|supervisord'
CACHE_STORE=array /www/server/php/84/bin/php artisan schedule:list
```

Setelah worker hidup, lakukan Run now hanya pada endpoint harmless dan pastikan
status berubah `queued → running → succeeded/failed`. Seluruh schedule tetap
paused sampai user menyetujui cutover.

## Pekerjaan berikutnya

1. Nyalakan Supervisor worker dan cron development melalui aaPanel/root.
2. Review 52 import errors bersama pemilik source legacy.
3. Dapatkan `gateway.sh`, folder `jobs/`, dan QA env definition bila memang masih
   digunakan.
4. Putuskan credential yang benar untuk `qa1/update_balance_trx.sh` dan empat
   credential drift lain.
5. Manual browser walkthrough, lalu Run now hanya pada endpoint harmless.
6. Jangan enable massal; cutover per client/job group minimal dua siklus.
7. Production deployment dan Git commit belum dilakukan.

## Environment dan working tree

```text
Project       /home/aditya_prasetyo/project/opsifin-crontab
Legacy source /home/aditya_prasetyo/project/crontab-legacy
PHP           /www/server/php/84/bin/php
Panel URL     http://opsifin-cron.local/admin
Queue         database/default
```

Working tree berisi rewrite besar dan belum di-commit. Jangan melakukan hard
reset atau mass checkout.

## Prompt sesi berikutnya

> Baca `docs/handoff.md` dan `docs/architecture.md`. Task pertama adalah
> menyalakan dua Supervisor worker dan satu aaPanel Cron `schedule:run` sesuai
> bagian "Runtime development yang masih perlu dinyalakan", lalu lakukan smoke
> test Run now pada endpoint harmless. Jangan enable schedule, mengubah legacy
> cron, deploy production, atau commit tanpa persetujuan saya.

## Memory update — preparation deployment VPS (19 Agustus 2026)

Keputusan deployment terbaru:

1. Production tetap memakai database queue dan dua Supervisor worker. Horizon,
   Predis, dan Redis tidak digunakan.
2. Web server production memakai Apache2 + PHP 8.4-FPM, bukan Nginx. Template
   resminya `deploy/vps/apache-vhost.conf.template`.
3. Linux service user dan primary group production adalah
   `opsifin_admin:opsifin_admin`.
4. Domain bukan prasyarat instalasi. Fase awal boleh memakai IP LAN/public atau
   URL HTTPS forwarder; setelah stabil baru ubah `ServerName`, `APP_URL`, session
   secure cookie, dan TLS ke domain final.
   Untuk HTTPS forwarder, samakan `ASSET_URL` dengan origin HTTPS, percaya hanya
   IP/CIDR forwarder melalui `TRUSTED_PROXIES`, dan pastikan header
   `X-Forwarded-Proto: https` diteruskan agar asset tidak terkena mixed-content.
5. Data production berasal dari dump database environment sekarang. Jangan
   menjalankan `cron:import` atau import ulang `crontab-legacy` pada VPS baru.
6. Credential Client disimpan plaintext/as-is. Jalankan migration konversi pada
   source dengan `APP_KEY` lama sebelum final dump; key source tidak perlu
   dipindahkan ke VPS setelah konversi berhasil.
7. Full database dump/restore boleh dilakukan manual melalui SQLyog. Gunakan
   **Backup Database As SQL Dump** (structure + data), target database kosong,
   koneksi SSH tunnel/VPN, dan rekonsiliasi jumlah data setelah import.
8. `storage/app/public/avatars` wajib ikut dipindah bersama database.
9. Runtime sementara seperti queue payload, session, cache, failed jobs, dan
   entry Telescope source dibersihkan setelah restore di target.
10. Dokumen resmi:
   - `docs/deployment-vps.md`: instalasi dan go-live VPS end-to-end;
   - `docs/database-migration-vps.md`: dump/restore database existing;
   - `docs/user-guide.md`: konsep, role, dan seluruh module UI.

Prompt deployment berikutnya:

> Baca tiga dokumen resmi di atas. Deploy release ke VPS manual memakai Apache2
> dan PHP 8.4-FPM sebagai user `opsifin_admin`, tanpa aaPanel/Redis/Horizon.
> Jalankan fase awal melalui IP atau HTTPS forwarder jika domain belum siap.
> Migrasikan database existing beserta avatar setelah konversi satu kali
> credential lama. SQLyog boleh dipakai untuk full dump/restore manual ke
> database target kosong. Generate `APP_KEY` baru di target;
> jangan re-import legacy. Jangan menyalakan cron/worker target sebelum restore,
> migration, count reconciliation, credential check, dan smoke test read-only
> lulus.
