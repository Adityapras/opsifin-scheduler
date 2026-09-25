# Menjalankan Opsifin Scheduler di Docker (WSL2)

Setup ini menjalankan aplikasi di container PHP 8.4 + Apache dan memakai container
MySQL/Redis yang sudah ada di network Docker `opsifin-main-networks`. Tidak ada
container database baru; database diimpor manual ke container `mysql`.

## Komponen

| Service | Container | Profile | Fungsi |
| --- | --- | --- | --- |
| `web` | `opsifin-cron-web` | default | Panel Filament, port host `8060` |
| `scheduler` | `opsifin-cron-scheduler` | `workers` | `artisan schedule:work` (pengganti cron `schedule:run`) |
| `direct-executor` | `opsifin-cron-direct` | `workers` | `artisan jobs:work-direct` |
| `horizon` | `opsifin-cron-horizon` | `queue` | Hanya bila `CRON_EXECUTION_DRIVER=queue` |

Koneksi di network `opsifin-main-networks`:

```text
DB_HOST=mysql          (port internal 3306; dari host tetap 7706)
REDIS_HOST=opsifin-redis
```

Redis dipakai bersama `opsifin-main`, sehingga `.env.docker.example` memakai DB
index 10–12 dan `REDIS_PREFIX=opsifin_cron_`.

Storage Laravel disimpan di volume `opsifin-cron-storage`. Crontab legacy
di-mount **read-only** ke `/legacy` (`CRON_SOURCE_PATH=/legacy`) untuk
`cron:import`.

## Langkah pertama

1. Siapkan environment container:

   ```bash
   cp .env.docker.example .env.docker
   docker compose run --rm --no-deps web php artisan key:generate --show
   ```

   Isi `APP_KEY` dengan hasil perintah di atas, lalu isi `DB_USERNAME` dan
   `DB_PASSWORD` sesuai user MySQL di container `mysql`. File `.env.docker`
   tidak di-commit.

2. Import database ke container `mysql` (dilakukan manual). User aplikasi harus
   boleh login dari network Docker, misalnya `'opsifin_cron'@'%'`, bukan hanya
   `@'localhost'`.

3. Build dan jalankan panel:

   ```bash
   docker compose up -d --build
   ```

   Buka `http://localhost:8060/admin`. Health check: `http://localhost:8060/up`.

4. Bila dump lebih lama dari kode, jalankan migration pending saja:

   ```bash
   docker compose exec web php artisan migrate:status
   docker compose exec web php artisan migrate
   ```

   **Jangan** `migrate:fresh`, `migrate:refresh`, `db:wipe`, atau
   `cron:import --fresh` (lihat aturan keras di `CLAUDE.md`).

## Menyalakan scheduler dan executor

```bash
docker compose --profile workers up -d
```

Begitu profile ini aktif, setiap Schedule yang `is_enabled=true` di database
hasil import akan **benar-benar mengirim HTTP** ke endpoint Client. Sebelum
menyalakan:

- cek Schedule yang enabled di menu Schedules;
- pastikan cron `schedule:run` / `jobs:work-direct` di WSL/aaPanel yang memakai
  database lain sudah dimatikan, supaya endpoint yang sama tidak dipanggil dua
  kali dari dua environment.

Menghentikan worker saja:

```bash
docker compose stop scheduler direct-executor
```

`direct-executor` diberi `stop_grace_period` 2000 detik (sama dengan
`stopwaitsecs` Supervisor) agar request aktif selesai sebelum container mati.

Service `scheduler`, `direct-executor`, dan `horizon` wajib memakai
`stop_signal: SIGTERM`. Image dibangun dari `php:8.4-apache` yang mewarisi
`STOPSIGNAL SIGWINCH` (graceful stop Apache). Proses artisan mengabaikan SIGWINCH,
sehingga tanpa override `docker stop` tidak memicu drain dan selalu menunggu
2000 detik sebelum kill paksa (terjadi 25 Sep 2026). Dengan SIGTERM, executor
idle berhenti kurang dari satu detik. Service `web` tetap SIGWINCH.

Container yang dibuat sebelum override ini masih menyimpan SIGWINCH. Bila perlu
menghentikannya, kirim `docker kill --signal SIGTERM <container>` setelah
`docker compose` mulai menghentikannya; itu tetap graceful (request aktif
diselesaikan).

Untuk driver `queue`, ubah `CRON_EXECUTION_DRIVER=queue` lalu jalankan
`docker compose --profile workers --profile queue up -d` dan hentikan
`direct-executor`.

## Operasi harian

| Tujuan | Perintah |
| --- | --- |
| Status container | `docker compose ps` |
| Log aplikasi | `docker compose logs -f web` (Laravel log ke stderr) |
| Log executor | `docker compose logs -f direct-executor` |
| Health direct | `docker compose exec direct-executor php artisan jobs:direct-status` |
| Artisan bebas | `docker compose exec web php artisan <perintah>` |
| Membuat admin | `docker compose exec web php artisan cron:admin-create` |
| Setelah ubah kode | `docker compose up -d --build` (plus `--profile workers` bila aktif) |
| Setelah ubah `.env.docker` | `docker compose up -d --force-recreate` |

Entrypoint menjalankan `artisan optimize` setiap container start, sehingga
config/route/view cache selalu mengikuti `.env.docker` terbaru. Image dibangun
self-contained (composer `--no-dev` + `npm run build` di dalam image); kode host
tidak di-bind-mount, jadi perubahan kode butuh rebuild.

## Variabel compose opsional

Bisa di-export di shell sebelum `docker compose`:

```text
OPSIFIN_CRON_PORT=8060                       port host panel
OPSIFIN_DOCKER_NETWORK=opsifin-main-networks network external
OPSIFIN_LEGACY_PATH=../crontab-legacy        source cron:import (read-only)
```

## Catatan

- Container `mysql` saat ini MySQL 5.7.31. Test suite belum dijalankan terhadap
  MySQL 5.7; bila migration atau query gagal, cek kompatibilitas versi dulu.
- File di `docker/`: `Dockerfile`, `entrypoint.sh`, `apache/vhost.conf`,
  `php/opsifin.ini`.
