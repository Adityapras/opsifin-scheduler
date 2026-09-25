# Direct HTTP: deployment, operasi, dan rollback

Implementasi compatibility tersedia sejak 9 September 2026. Default deployment
tetap `CRON_EXECUTION_DRIVER=queue`. Implementasi ini belum mengubah `.env`
aktif, database aplikasi, service Supervisor, atau production.

## Runtime

`jobs:dispatch-due` materialize occurrence, lalu `jobs:work-direct` mengambil
Run `pending` dari MySQL. Rolling cURL multi pool mengisi ulang slot yang selesai,
termasuk Run Now yang baru masuk selama request lain masih berjalan. Transport
memakai Guzzle yang sudah terpasang, mengikuti
[API asynchronous Guzzle](https://docs.guzzlephp.org/en/stable/faq.html).
Rolling pool menggantikan contoh Laravel Batch di rencana agar heartbeat dan
polling tetap berjalan ketika ada endpoint lambat.

Ownership disimpan per Run dalam `execution_driver`. Queue worker hanya claim
`queued`; direct executor hanya claim `pending` dari driver `direct`.
`prepared_at` berlaku untuk kedua driver. Kolom `queued_at` dan `queue_job_id`
dipertahankan untuk rollback. Run direct tidak dipindahkan ke Redis.

Satu daemon memegang lease `executor_states.direct` di database bersama. Daemon
kedua standby. Setelah crash, pengganti menunggu lease kedaluwarsa dan seluruh
direct Run yang masih `running` mencapai deadline recovery. Outcome ambigu
menjadi `failed`, tanpa HTTP ulang. Persistence failure menghentikan admission;
callback lain tetap diproses dan final sweep/deadline menutup Run yang tersisa.

## Konfigurasi

| Konfigurasi | Default | Arti |
| --- | ---: | --- |
| `CRON_EXECUTION_DRIVER` | `queue` | `queue` atau `direct`; restart proses setelah berubah |
| `CRON_DIRECT_CONCURRENCY` | 20 | Maksimum request aktif pada pool global |
| `CRON_DIRECT_BATCH_LIMIT` | 250 | Kandidat per admission pass; total maksimum pada `--once` |
| `CRON_DIRECT_POLL_INTERVAL_MS` | 500 | Polling saat tidak ada kandidat, dan recovery |
| `CRON_DIRECT_IDLE_DELAY_MS` | 500 | Sleep ketika pool kosong |
| `CRON_DIRECT_START_WINDOW_SEC` | 55 | Mulai sebelum `scheduled_for + window`; wajib <60 |
| `CRON_DIRECT_HEARTBEAT_SEC` | 15 | Lease berumur max(30, 3 × heartbeat) detik |
| `CRON_DIRECT_RESPONSE_MAX_BYTES` | 65536 | Prefix body maksimum dalam memori per request |
| `CRON_RESPONSE_EXCERPT_LENGTH` | 2000 | Batas karakter respons setelah redaction di DB |
| `CRON_CONNECTION_TEST_PATH` | `/api/remittanceApi` | Path GET untuk Test connection; harus memvalidasi Basic Auth tanpa efek samping |

Body dikonsumsi sampai selesai/timeout; hanya prefix yang dibuffer. Batas ini
mengendalikan memori, bukan bandwidth. HTTP non-2xx menjadi `failed`; JSON
`message`/`error` digunakan bila berupa scalar. Redirect tidak diikuti: 3xx juga
terminal. Credential, Authorization dan secret terpotong disamarkan sebelum
disimpan. Transport direct tidak mengirim event HTTP Telescope yang bisa
merekam request berisi credential; hasil bisnis tersedia di Execution logs.

## Development dan deployment

PHP 8.4 CLI memerlukan `curl`, `pcntl`, dan driver PDO. Tests memakai SQLite
terisolasi serta Python 3 untuk server loopback, tanpa endpoint Client asli.

```bash
php artisan migrate
# Atur CRON_EXECUTION_DRIVER=direct di environment development yang dituju.
php artisan config:clear
php artisan jobs:work-direct
```

`jobs:work-direct --once` melakukan satu drain maksimum `batch_limit`.
`--max-seconds=60` menghentikan admission setelah 60 detik kemudian menunggu
in-flight selesai. Daemon ini harus berjalan bersama `schedule:run`.
Pada mode queue, daemon direct standby tanpa mengambil pekerjaan.
Run Now manual tetap boleh dijalankan saat Schedule paused, selama Client dan
Task Template aktif. Ini memungkinkan smoke test tanpa membuka occurrence cron.

Urutan cutover setelah readiness production disetujui:

1. Backup DB, deploy dengan driver `queue`, lalu `php artisan migrate --force`.
2. Pasang `deploy/vps/supervisor-direct-executor.conf.template` atau template
   aaPanel untuk development. Sesuaikan path, PHP, user dan log. Gunakan
   `numprocs=1`; `stopwaitsecs` harus melampaui timeout HTTP terlama plus margin.
3. Start daemon standby. Flag driver bersifat **global**, bukan per Task. Untuk
   pilot, pause schedule di luar subset pilihan dan catat state semula.
4. Pause admission terjadwal, drain queued/running lama, dan pastikan legacy cron
   tidak mengeksekusi occurrence yang sama. Jangan konversi `queued` ke `pending`.
5. Stop Horizon secara graceful setelah queue habis. Atur driver `direct`,
   rebuild `config:cache`, restart daemon direct/reload PHP-FPM sesuai deployment,
   lalu resume subset pilot.
6. Periksa Run Now, hasil HTTP, health, start lag, CPU/RAM/DB/socket/network;
   monitor satu peak lengkap dan soak period sebelum memperluas schedule aktif.

Concurrency 20 terbukti pada fixture 123 Run berdurasi 5 detik, bukan jaminan
production. Projected 246 diuji dengan concurrency 40. Jika seluruh request
mencapai timeout 60 detik, 20 slot tidak cukup untuk memulai 123 Run dalam
55 detik; sisanya akan skipped. Ukur durasi production sebelum cutover;
fixture lokal tidak menetapkan p95/p99 production.

## Health dan troubleshooting

```bash
php artisan jobs:direct-status --json
sudo supervisorctl status opsifin-scheduler-direct
sudo supervisorctl stop opsifin-scheduler-direct
sudo supervisorctl start opsifin-scheduler-direct
```

`jobs:direct-status` read-only dan exit 1 pada direct mode jika executor/dispatcher
offline, start lag melampaui target, atau ada occurrence kedaluwarsa. Occurrence
yang sudah terminal karena missed window tetap memicu alert selama 24 jam;
recovery tidak langsung membuat health kembali hijau. Output
mencakup counts 24 jam, p50/p95/p99 start lag/duration, failed rate, oldest pending,
missed window, dan pool metrics. Duration percentile hanya mencakup Run yang
pernah mulai, sehingga skipped/cancelled tidak menurunkan angka latency.
Hubungkan command ini ke monitoring organisasi;
command tidak mengirim pesan keluar. CPU/RAM/socket/disk/network memakai metrik OS.

Dashboard menampilkan Pending, Running, executor health, active/capacity,
dispatcher heartbeat, p95/p99 start lag, dan missed window. Snapshot slot dapat
tertinggal hingga interval heartbeat.

- Pending melewati window: skipped tanpa catch-up. Periksa daemon, slot, durasi
  endpoint dan dispatcher lag.
- Crash: tunggu timeout + margin untuk outcome ambigu; dispatcher atau daemon
  yang restart melakukan recovery. Jangan ubah status untuk memaksa resend.
- Persistence failure: pulihkan akses database lalu restart daemon; hasil
  terminal tidak ditimpa, sisanya dipulihkan melalui deadline.
- Deploy: SIGTERM melalui Supervisor berhenti mengambil Run baru dan menunggu
  in-flight selesai. SIGKILL menyisakan outcome ambigu; gunakan hanya untuk
  failure drill terisolasi.

## Rollback dan decommission

1. Pause schedule pilot, stop daemon direct melalui SIGTERM, tunggu in-flight.
2. Ubah driver ke `queue`, rebuild config cache, start Horizon, resume schedule.
3. Hanya occurrence berikutnya memakai Redis. Direct failed/ambiguous tidak
   dikirim ulang; pending lama menjadi skipped saat melewati window.
4. Validasi hasil dan catat waktu/scope rollback. Migration tidak perlu di-rollback.

Migration `down` hanya untuk database terisolasi: pending direct dijadikan terminal
sebelum metadata compatibility dihapus. Itu bukan prosedur rollback production.

Horizon, Predis, ExecuteRun, reconciler/cancellation queue dan kolom queue tetap
ada selama soak. Setelah stabil dan penghapusan disetujui, audit cache, session,
notification, lock dan pemakaian Redis lain, lalu hapus queue path, package,
provider/config/schedule Horizon, Supervisor lama dan metadata queue melalui
migration lanjutan yang menjaga histori. Default cache/session repo memakai
database; konfigurasi production tetap harus diperiksa.

## Pengulangan uji

Lihat [hasil validasi](direct-http-validation.md).

```bash
DIRECT_HTTP_LOAD_RUNS=123 DIRECT_HTTP_LOAD_DELAY=5 DIRECT_HTTP_LOAD_CONCURRENCY=20 php artisan test --filter=test_opt_in_capacity_scenario
DIRECT_HTTP_LOAD_RUNS=246 DIRECT_HTTP_LOAD_DELAY=5 DIRECT_HTTP_LOAD_CONCURRENCY=40 php artisan test --filter=test_opt_in_capacity_scenario
```

Parameter ini hanya berlaku untuk test dan tidak mengubah aplikasi aktif.
