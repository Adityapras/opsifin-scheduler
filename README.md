# Opsifin Scheduler

Scheduler HTTP berbasis Laravel 13 dan Filament 5. Job didefinisikan sekali,
di-assign ke banyak client, lalu dieksekusi melalui Redis/Horizon atau direct
bounded HTTP. Hasil setiap occurrence disimpan di database.

Direct HTTP tersedia melalui `CRON_EXECUTION_DRIVER=direct` dan daemon
`jobs:work-direct`. Default tetap `queue` selama compatibility window.
Lihat [deployment dan rollback direct](docs/direct-http-operations.md).

## Arsitektur singkat

```text
system cron setiap menit
        │
        ▼
artisan schedule:run
        │
        ▼
jobs:dispatch-due
        │
        ├─ schedules.next_run_at <= sekarang
        ├─ buat run
        ├─ hitung next_run_at berikutnya
        ├─ direct: Run pending → supervised bounded HTTP pool
        └─ queue: Redis → Horizon worker (compatibility)
                         │
                         ▼
                  HTTP endpoint client
                         │
                         ▼
                   success / failed
```

## Fitur

- Client dengan base URL dan credential yang disimpan sesuai nilai input.
- Job template HTTP reusable.
- Assign job ke semua client aktif atau client terpilih.
- Remove assignment dari client terpilih.
- Set cron, pause, dan resume secara bulk.
- Cron preview dan `next_run_at` yang eksplisit.
- Overlap guard per schedule dengan perilaku skip seperti `flock -n`.
- Direct HTTP dengan concurrency terbatas; Redis/Horizon tersedia untuk rollback.
- Run now di background; retry manual hanya untuk Run queue dalam mode queue.
- Histori pending/queued, running, succeeded, failed, skipped, dan cancelled.
- Heartbeat executor/dispatcher, start lag, dan peringatan occurrence terlewat.
- Audit perubahan client, template, dan schedule.
- Import legacy memakai `crontab-legacy/jobs/*.sh` sebagai katalog canonical dan
  selalu menghasilkan schedule disabled.

Tidak ada automatic retry, multi-catch-up, blackout, incident engine, runtime
override, atau watchdog internal.

## Environment

| Environment | Infrastruktur |
| --- | --- |
| Production | VPS manual: Apache2, PHP-FPM/CLI 8.4, MySQL, Supervisor, system cron, TLS |
| Development | WSL2 + aaPanel |

aaPanel hanya dipakai untuk development lokal.

## Setup development

```bash
cd /home/aditya_prasetyo/project/opsifin-crontab
/www/server/php/84/bin/php /usr/bin/composer install
npm ci
cp .env.example .env
/www/server/php/84/bin/php artisan key:generate
/www/server/php/84/bin/php artisan migrate
/www/server/php/84/bin/php artisan cron:admin-create --email=admin@example.com
npm run build
```

Runtime development:

```bash
# Satu task aaPanel Cron, setiap menit
cd /home/aditya_prasetyo/project/opsifin-crontab && /www/server/php/84/bin/php artisan schedule:run

# Supervisor pada driver queue (default compatibility)
/www/server/php/84/bin/php artisan horizon

# Supervisor setelah environment diatur CRON_EXECUTION_DRIVER=direct
/www/server/php/84/bin/php artisan jobs:work-direct
```

## Import legacy

```bash
/www/server/php/84/bin/php artisan cron:import --fresh --dry-run --report=storage/app/import-reports/dry-run.md
/www/server/php/84/bin/php artisan cron:import --fresh --report=storage/app/import-reports/apply.md
/www/server/php/84/bin/php artisan cron:verify-import
/www/server/php/84/bin/php artisan cron:cutover-status
```

Import tidak pernah mengaktifkan schedule atau mematikan cron legacy. Re-import
database berisi data wajib memakai `--fresh` setelah backup.

## Dokumentasi

- [Index dokumentasi](docs/README.md)
- [Artifact teknis end-to-end](docs/artifact-teknis-opsifin-scheduler.md)
- [Arsitektur dan flow teknis](docs/architecture.md)
- [User guide dan panduan per module](docs/user-guide.md)
- [Development WSL + aaPanel](docs/installation.md)
- [Deployment production VPS](docs/deployment-vps.md)
- [Migrasi database existing ke VPS](docs/database-migration-vps.md)
- [Operations, troubleshooting, dan cutover](docs/operations.md)
- [Handoff/memory terakhir](docs/handoff.md)
- [Direct HTTP: deployment dan rollback](docs/direct-http-operations.md)
- [Direct HTTP: hasil validasi lokal](docs/direct-http-validation.md)

## Verifikasi

```bash
/www/server/php/84/bin/php artisan test --compact
/www/server/php/84/bin/php vendor/bin/pint --test
CACHE_STORE=array /www/server/php/84/bin/php artisan schedule:list
npm run build
```

Validasi 10 September 2026: **120 test lulus, 447 assertion**; satu capacity test
opt-in dijalankan terpisah. Pint dan Vite build lulus. Bukti serta batasan
pengujian tercatat di [laporan validasi](docs/direct-http-validation.md).
