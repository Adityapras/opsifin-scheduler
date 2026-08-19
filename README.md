# Opsifin Scheduler

Scheduler HTTP berbasis Laravel 13 dan Filament 5. Job didefinisikan sekali,
di-assign ke banyak client, dijalankan melalui database queue, dan hasilnya
disimpan sebagai success atau failed.

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
        └─ dispatch database queue
                         │
                         ▼
                 Supervisor worker
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
- Database queue dengan dua Supervisor worker.
- Run now dan retry manual.
- Histori queued, running, succeeded, failed, dan skipped.
- Audit perubahan client, template, dan schedule.
- Import legacy memakai `crontab-legacy/jobs/*.sh` sebagai katalog canonical dan
  selalu menghasilkan schedule disabled.

Tidak ada automatic retry, multi-catch-up, blackout, incident engine, runtime
override, atau watchdog internal.

## Environment

| Environment | Infrastruktur |
| --- | --- |
| Production | VPS manual: Nginx, PHP-FPM/CLI 8.4, MySQL, Supervisor, system cron, TLS |
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

# Supervisor
/www/server/php/84/bin/php artisan queue:work database --queue=default --sleep=1 --tries=1 --timeout=1900 --max-time=3600
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

- [Arsitektur dan flow teknis](docs/architecture.md)
- [User guide](docs/user-guide.md)
- [Development WSL + aaPanel](docs/installation.md)
- [Deployment production VPS](docs/deployment-vps.md)
- [Migrasi database existing ke VPS](docs/database-migration-vps.md)
- [Operations, troubleshooting, dan cutover](docs/operations.md)
- [Handoff/memory terakhir](docs/handoff.md)

## Verifikasi

```bash
/www/server/php/84/bin/php artisan test --compact
/www/server/php/84/bin/php vendor/bin/pint --test
CACHE_STORE=array /www/server/php/84/bin/php artisan schedule:list
npm run build
```

Status 19 Agustus 2026: **66 test, 231 assertion**.
