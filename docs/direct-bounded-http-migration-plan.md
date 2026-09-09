# Migration Plan: Direct Bounded Concurrent HTTP

## Document status

| Metadata | Value |
| --- | --- |
| Status | Draft for final approval — implementation has not started |
| Target architecture | Direct Bounded Concurrent HTTP |
| Current architecture | Redis Queue + Laravel Horizon |
| Production sizing baseline | Peak 123 Run due pada menit yang sama |
| Primary timing requirement | Seluruh Run mulai dalam menit yang sama; beda detik diperbolehkan |
| Delivery semantics | Best-effort periodic execution with next-period recovery |
| Retry policy | Tidak ada automatic retry atau catch-up |
| Source of truth | MySQL `runs` |
| Created | 9 September 2026 |

> **Execution gate:** dokumen ini adalah checkpoint planning dan handoff. Jangan
> mulai mengubah source code sampai user menyatakan planning sudah final dan
> memberikan instruksi eksplisit untuk mulai eksekusi.

## 1. Ringkasan keputusan

Opsifin Scheduler akan dipindahkan dari Redis Queue + Horizon ke Direct Bounded
Concurrent HTTP karena occurrence terjadwal bersifat replaceable:

- Run yang gagal tidak perlu diulang;
- Run yang terlewat tidak perlu di-catch-up;
- periode berikutnya membuat occurrence baru;
- kegagalan satu endpoint tidak boleh membatalkan request lain;
- hasil berhasil maupun gagal tetap disimpan untuk audit;
- MySQL tetap menyimpan lifecycle dan hasil bisnis setiap Run;
- concurrency tetap dibatasi agar server dan endpoint Client tidak menerima
  connection storm.

Reliability yang dituju adalah kesinambungan eksekusi periodik dan kemampuan
diagnosis, bukan jaminan delivery setiap occurrence.

Pernyataan arsitektur:

> Untuk pekerjaan periodik yang state-nya diperbarui lagi pada periode
> berikutnya, kegagalan satu occurrence boleh menjadi terminal. Direct bounded
> concurrency memberikan fan-out yang rapat dan penggunaan resource yang
> terkendali, sementara Run state, timeout, overlap protection, dan execution
> log mempertahankan reliability yang dibutuhkan bisnis.

## 2. Keputusan yang sudah dikonfirmasi

1. Peak production sizing menggunakan **123 Run**, bukan 900 Run.
2. Semua Run due harus mulai pada menit yang sama; tidak harus pada detik yang
   sama.
3. Run HTTP yang gagal menjadi terminal `failed`.
4. Run gagal tidak di-retry otomatis.
5. Run gagal tidak di-catch-up pada periode berikutnya.
6. Periode berikutnya membuat occurrence baru yang independen.
7. Error satu endpoint tidak boleh menghentikan batch.
8. HTTP status, response endpoint, error message, dan duration tetap disimpan.
9. Response dan error harus melalui redaction credential sebelum disimpan.
10. Concurrency wajib bounded dan dapat dikonfigurasi.

## 3. Kontrak delivery dan failure

### 3.1 Delivery semantics

```text
18:00 occurrence dibuat
      |
      +-- success -> terminal succeeded
      |
      +-- HTTP error/timeout -> terminal failed, no retry
      |
      +-- tidak sempat dimulai -> terminal skipped/failed, no catch-up

18:05 occurrence baru dibuat
      -> tidak mempunyai hubungan retry dengan occurrence 18:00
```

### 3.2 Outcome per jenis kegagalan

| Kondisi | Status akhir | Data yang disimpan | Retry |
| --- | --- | --- | --- |
| HTTP 2xx | `succeeded` | HTTP status, response excerpt, duration | Tidak |
| HTTP 4xx/5xx | `failed` | HTTP status, response excerpt, endpoint message, duration | Tidak |
| Connection refused | `failed` | Connection error dan duration | Tidak |
| DNS failure | `failed` | DNS/connection error dan duration | Tidak |
| Request timeout | `failed` | Timeout message dan duration | Tidak |
| Invalid request configuration | `failed` | Validation/resolution message | Tidak |
| Client/Template/Schedule paused sebelum send | `skipped` | Alasan skip | Tidak |
| Overlap slot masih dipakai | `skipped` | Alasan overlap | Tidak |
| Process mati sebelum request dikirim | `failed` atau `skipped` melalui stale recovery | Pesan bahwa request tidak selesai diproses | Tidak |
| Process mati setelah request dikirim | `failed` melalui execution deadline | Pesan bahwa outcome endpoint tidak dapat dipastikan | Tidak |

### 3.3 Error response storage

Untuk HTTP non-2xx, simpan:

```text
status            = failed
http_status       = 4xx / 5xx
response_excerpt  = body response endpoint yang sudah di-redact
error_message     = message yang mudah dibaca operator
duration_ms       = waktu eksekusi
started_at
finished_at
```

Jika response berbentuk JSON, ambil `message`, `error`, atau field error umum
sebagai `error_message` bila selama aman. Body asli yang sudah dipotong tetap
disimpan sebagai `response_excerpt`.

Default maksimum response excerpt saat ini adalah 2.000 karakter. Batas ini
tetap dipertahankan dan dapat dikonfigurasi.

## 4. Arsitektur target

### 4.1 Alur utama

```text
Linux cron / scheduler tick
        |
        v
DueScheduleDispatcher
        |
        +-- recover stale running Run tanpa retry
        +-- query Schedule due
        +-- lock Schedule
        +-- advance next_run_at
        +-- materialize Run satu kali
        |
        v
MySQL Run(status=pending)
        |
        v
DirectPoolExecutor
        |
        +-- ambil pending Run sesuai execution window
        +-- atomic claim sebelum HTTP send
        +-- bounded concurrency C
        +-- callback hasil per request
        |
        +-- Client A
        +-- Client B
        +-- ...
        +-- Client N
        |
        v
MySQL Run(status=succeeded/failed/skipped)
```

### 4.2 Bentuk executor yang direkomendasikan

Pisahkan materialization dari eksekusi HTTP:

```text
Fast dispatcher
└── menghitung dan menyimpan occurrence

Supervised direct executor
└── menjalankan bounded HTTP pool dari pending Run di MySQL
```

Alasan:

- dispatcher tidak tertahan endpoint lambat;
- periode berikutnya tetap dapat dimaterialize;
- Run Now tetap berjalan di background;
- satu executor process dapat dijaga Supervisor;
- Redis dan Horizon tidak lagi diperlukan untuk execution path;
- concurrency dapat dibatasi secara global pada executor.

Konsekuensi yang diakui:

- MySQL menjadi pending-work ledger;
- executor perlu polling ringan;
- restart dan heartbeat executor tetap perlu dimonitor;
- pending Run lama tidak diproses ulang karena policy no catch-up.

## 5. State machine

State yang direkomendasikan:

```text
pending
   |
   +-- cancelled
   +-- skipped
   |
   v
running
   |
   +-- succeeded
   +-- failed
   +-- skipped
```

Perubahan terminology:

- nilai `queued` diganti menjadi `pending`;
- UI tidak lagi memakai istilah Queue/Horizon;
- `queued_at` diganti atau dimigrasikan menjadi `prepared_at`;
- `queue_job_id` dipertahankan hanya selama rollback window;
- setelah queue resmi dilepas, `queue_job_id` dihapus;
- `worker` dapat dipertahankan untuk menyimpan identity direct executor;
- `execution_deadline_at` tetap digunakan untuk recovery;
- `materialization_key` tetap menjadi duplicate-occurrence guard.

State `unknown` tidak wajib ditambahkan pada implementasi pertama. Ambiguous
outcome dapat dicatat sebagai `failed` dengan error message eksplisit:

```text
Execution process ended after HTTP may have been sent; endpoint outcome is unknown.
```

Run tersebut tetap tidak dikirim ulang.

## 6. Komponen implementasi

### 6.1 `DueScheduleDispatcher`

Tanggung jawab setelah migrasi:

1. membulatkan waktu dispatch sesuai timezone aplikasi;
2. memulihkan stale `running` Run sebagai terminal tanpa retry;
3. mengambil Schedule aktif yang due;
4. melakukan transaction dan `lockForUpdate` per Schedule;
5. menghitung occurrence terbaru yang due;
6. memajukan `next_run_at`;
7. materialize Run idempotently;
8. mencatat skipped occurrence ketika overlap aktif;
9. tidak melakukan HTTP request;
10. tidak memublikasikan payload Redis.

### 6.2 `RunExecutionLifecycle`

Extract aturan bisnis dari `RunWorker` menjadi service reusable:

```text
claim(run)
validate(run)
acquireOverlapSlot(run)
markStarted(run)
completeSuccess(run, response)
completeFailure(run, error)
completeSkipped(run, reason)
releaseOverlapSlot(run)
```

Aturan penting:

- claim memakai conditional update `pending -> running`;
- sebelum send, cek ulang status Schedule, Client, dan Task Template;
- overlap slot diperoleh secara atomik;
- result hanya dapat ditulis jika Run masih `running`;
- hasil satu Run tidak memengaruhi Run lain;
- semua stored response dan error melalui redaction.

### 6.3 `DirectPoolExecutor`

Laravel 13 yang terpasang sudah mempunyai HTTP Batch dengan concurrency limit
dan callback per request. Bentuk konseptual:

```php
Http::batch(function (Batch $batch) use ($runs) {
    foreach ($runs as $run) {
        // Resolve dan tambahkan request dengan key Run ID.
    }
})
    ->concurrency(config('opsifin_cron.direct.concurrency'))
    ->progress(/* persist successful response per Run */)
    ->catch(/* persist failed response/exception per Run */)
    ->finally(/* sweep Run yang belum terminal */)
    ->send();
```

Detail yang harus dijaga ketika implementasi:

- setiap request menggunakan Run ID sebagai batch key;
- HTTP request baru boleh dikirim setelah atomic claim berhasil;
- `started_at` merepresentasikan waktu request mulai, bukan waktu batch dibuat;
- per-request connect timeout dan total timeout tetap dihormati;
- callback success dan failure menyimpan hasil segera;
- generic exception pada callback tidak boleh menghentikan batch;
- final sweep mencari Run yang tidak mendapat callback normal;
- response body dibatasi sebelum masuk memory dan database;
- batch size dan concurrency adalah dua konfigurasi berbeda.

### 6.4 `DirectExecutorCommand`

Command baru yang direkomendasikan:

```text
php artisan jobs:work-direct
```

Tanggung jawab:

- berjalan sebagai supervised process;
- mengirim heartbeat;
- polling pending Run dalam interval pendek;
- hanya memilih Run yang masih berada dalam execution window;
- mengeksekusi batch maksimal sesuai `batch_limit`;
- melakukan graceful shutdown;
- tidak mengambil ulang failed/expired Run;
- tidak mengubah occurrence lama menjadi catch-up.

Jika implementasi daemon dianggap terlalu kompleks, alternatifnya adalah command
periodik `jobs:execute-direct`. Alternatif tersebut hanya boleh dipilih jika
capacity test membuktikan seluruh batch selalu selesai sebelum invocation
berikutnya.

### 6.5 Run Now

Rekomendasi behavior:

1. UI membuat Run `pending` dengan trigger `manual`;
2. direct executor mengambil Run pada polling berikutnya;
3. notification menggunakan label `Run created` atau `Run pending`, bukan
   `queued`;
4. target delay Run Now maksimal beberapa detik;
5. failure menjadi terminal dan tidak di-retry otomatis.

### 6.6 Retry dan Cancel

Rekomendasi:

- hapus action Retry dari Runs table dan View Run;
- operator dapat membuat manual Run baru melalui Run Now bila memang dibutuhkan;
- pending Run masih dapat dibatalkan;
- running Run tidak dijanjikan dapat dibatalkan;
- queued-payload cancellation dihapus setelah rollback window.

## 7. Execution window dan timing invariant

Requirement utama:

```text
p95 started_at - scheduled_for < 60 detik
```

Target operasional yang lebih aman:

```text
p95 start_lag <= 45 detik
p99 start_lag < 60 detik
```

Estimasi request terakhir mulai:

```text
last_start ~= floor((N - 1) / C) x T

N = jumlah Run due
C = concurrency
T = durasi slot sebelum request berikutnya dapat mulai
```

Contoh baseline QA:

```text
N = 123
C = 20
T = 5 detik

floor(122 / 20) x 5
= sekitar 30 detik sampai request terakhir mulai
```

Nilai `C=20` adalah titik awal pengujian, bukan nilai final production.

Invariant readiness:

```text
p99 last_start_offset < 60 detik
max timeout + last_start_offset sesuai kebijakan overlap periode berikutnya
pool in-flight <= configured concurrency
```

Jika timeout panjang membuat executor tidak dapat menerima occurrence periode
berikutnya, pilih salah satu berdasarkan hasil test:

1. kurangi timeout Task Template;
2. naikkan concurrency dalam batas resource;
3. gunakan rolling direct pool yang dapat mengisi ulang slot lintas batch;
4. stagger Schedule noncritical;
5. terima dan ukur skipped occurrence sesuai kontrak bisnis.

## 8. Konfigurasi yang diusulkan

```env
CRON_EXECUTION_DRIVER=queue
CRON_DIRECT_CONCURRENCY=20
CRON_DIRECT_BATCH_LIMIT=250
CRON_DIRECT_POLL_INTERVAL_MS=500
CRON_DIRECT_START_WINDOW_SEC=55
CRON_DIRECT_HEARTBEAT_SEC=15
CRON_DIRECT_IDLE_DELAY_MS=500
```

Selama development dan rollback window:

```text
CRON_EXECUTION_DRIVER=queue  -> execution path lama
CRON_EXECUTION_DRIVER=direct -> direct bounded executor
```

Setelah direct production dinyatakan stabil, feature flag queue dan kode
compatibility dapat dihapus.

## 9. Perubahan database

Migration bertahap yang direkomendasikan:

### Migration 1 — compatibility phase

- tambahkan atau normalisasi status `pending`;
- tambahkan `prepared_at` bila disepakati;
- pertahankan `queued_at` dan `queue_job_id` untuk rollback;
- tambahkan index untuk direct executor:

```text
(status, scheduled_for)
(status, prepared_at)
```

### Migration 2 — post-cutover cleanup

- hapus `queue_job_id`;
- hapus `queued_at` jika sudah digantikan;
- bersihkan metadata failed queue yang tidak dipakai;
- pertahankan histori Run lama tanpa kehilangan audit.

## 10. Dampak file dan module

Area yang diperkirakan berubah:

```text
app/Enums/RunStatus.php
app/Models/Run.php
app/Services/Execution/HttpExecutor.php
app/Services/Scheduling/DueScheduleDispatcher.php
app/Services/Scheduling/RunDispatcher.php
app/Services/Scheduling/RunWorker.php
app/Services/Scheduling/QueuedRunCanceller.php
app/Console/Commands/DispatchDueJobsCommand.php
app/Console/Commands/ReconcileQueuedRunsCommand.php
app/Jobs/ExecuteRun.php
app/Filament/Resources/Schedules/Tables/SchedulesTable.php
app/Filament/Resources/Runs/Tables/RunsTable.php
app/Filament/Resources/Runs/Pages/ViewRun.php
app/Filament/Widgets/LateSchedulesTable.php
app/Filament/Widgets/RunHealthOverview.php
config/opsifin_cron.php
config/queue.php
config/horizon.php
routes/console.php
.env.example
composer.json
deploy/*/supervisor-worker.conf.template
docs/architecture.md
docs/installation.md
docs/operations.md
docs/deployment-vps.md
docs/user-guide.md
```

File/service baru yang diperkirakan:

```text
app/Services/Scheduling/RunExecutionLifecycle.php
app/Services/Scheduling/DirectPoolExecutor.php
app/Console/Commands/WorkDirectRunsCommand.php
database/migrations/*_prepare_runs_for_direct_execution.php
deploy/*/supervisor-direct-executor.conf.template
tests/Feature/DirectPoolExecutorTest.php
tests/Feature/WorkDirectRunsCommandTest.php
```

Nama final boleh berubah mengikuti struktur code setelah refactor.

## 11. Tahapan eksekusi

### Phase 0 — Baseline dan keputusan final

- catat test baseline;
- inventarisasi timeout seluruh Task Template;
- ukur atau estimasikan p50/p95/p99 HTTP duration;
- verifikasi peak 123;
- sepakati execution window;
- sepakati behavior Run Now;
- sepakati penghapusan Retry;
- pilih executor daemon atau command periodik.

Exit criteria:

- tidak ada keputusan delivery semantics yang ambigu;
- concurrency awal dan acceptance threshold tercatat.

### Phase 1 — Shared lifecycle refactor

- extract atomic claim, validation, overlap, completion, dan redaction;
- pertahankan queue path tetap bekerja;
- pindahkan test RunWorker ke shared lifecycle;
- pastikan tidak ada behavior regression.

Exit criteria:

- seluruh existing test lulus;
- queue production behavior belum berubah.

### Phase 2 — Implement direct executor

- implement request preparation;
- implement bounded batch/pool;
- implement callback success/failure per Run;
- implement polling/command lifecycle;
- implement heartbeat dan graceful shutdown;
- tambah konfigurasi direct;
- direct mode tetap berada di balik feature flag.

Exit criteria:

- single-error isolation terbukti;
- concurrency limit terbukti;
- no-retry semantics terbukti.

### Phase 3 — UI dan operations adaptation

- ubah label Queued menjadi Pending;
- sesuaikan Run Now;
- hapus/disable Retry sesuai keputusan;
- sesuaikan Cancel;
- ganti widget queue dengan direct executor health;
- tambahkan start lag dan pool metrics;
- buat Supervisor template direct executor.

Exit criteria:

- operator dapat melihat pending, running, result, dan executor health tanpa
  Horizon.

### Phase 4 — QA dan failure drill

- jalankan functional suite;
- load test 123 dan 246;
- uji mixed latency dan timeout;
- kill executor pada beberapa failure window;
- verifikasi next occurrence;
- verifikasi tidak ada retry/catch-up;
- ukur CPU, RAM, DB connection, socket, dan bandwidth.

Exit criteria:

- seluruh acceptance criteria Section 15 terpenuhi.

### Phase 5 — Staged production cutover

- deploy direct code dengan driver masih `queue`;
- pilot Task yang replaceable dan harmless;
- ubah driver menjadi `direct` pada scope pilot/global sesuai keputusan;
- monitor satu siklus peak lengkap;
- perluas scope bertahap;
- pertahankan queue sebagai rollback sementara.

Exit criteria:

- direct mode stabil selama soak period yang disepakati;
- rollback tidak dibutuhkan atau sudah dibuktikan dapat dilakukan.

### Phase 6 — Redis/Horizon decommission

- hentikan Horizon setelah tidak ada queued payload;
- hapus schedule `horizon:snapshot`;
- hapus queued reconciler;
- hapus job `ExecuteRun` dan queue cancellation logic;
- hapus `laravel/horizon`;
- hapus `predis/predis` hanya jika Redis tidak digunakan subsistem lain;
- hapus Supervisor Horizon;
- bersihkan config/env/deployment docs;
- jalankan migration cleanup queue metadata.

Exit criteria:

- tidak ada execution path yang masih bergantung pada Redis/Horizon;
- application health, Run Now, scheduler, dan direct executor tetap sehat.

## 12. Test plan

### 12.1 Functional

- seluruh Schedule due dimaterialize tepat satu kali;
- Client/Template/Schedule nonaktif tidak mengirim HTTP;
- placeholder dan credential resolution tetap sama;
- HTTP 2xx menghasilkan `succeeded`;
- HTTP non-2xx menghasilkan `failed` dengan response endpoint tersimpan;
- connection failure dan timeout menghasilkan `failed`;
- response dan error credential di-redact;
- `prevent_overlap` tetap bekerja;
- Run Now membuat manual Run dan berjalan di background;
- tidak ada automatic Retry;
- occurrence lama tidak di-catch-up.

### 12.2 Concurrency

- jumlah request in-flight tidak pernah melebihi configured concurrency;
- pool mengisi slot baru setelah request selesai;
- satu failure tidak membatalkan request lain;
- satu timeout hanya menahan satu slot;
- setiap result disimpan saat request settle;
- duplicate dispatcher tidak membuat duplicate occurrence;
- peak 123 memenuhi same-minute start SLA.

### 12.3 Load

Uji:

```text
10 Run
50 Run
123 Run
246 Run
```

Dengan variasi:

- response 100 ms;
- response 1 detik;
- response 5 detik;
- response 10 detik;
- timeout maksimum;
- satu endpoint lambat di antara endpoint cepat;
- campuran 2xx, 4xx, 5xx, connection failure, dan timeout;
- response body kecil dan batas maksimum.

Ukur:

- p50/p95/p99 start lag;
- p50/p95/p99 duration;
- batch completion time;
- CPU dan memory;
- DB connection;
- socket/file descriptor;
- bandwidth;
- failed dan skipped rate.

### 12.4 Failure drill

- kill direct executor sebelum request dikirim;
- kill executor ketika request in-flight;
- restart executor saat pending Run tersedia;
- putus database sementara;
- endpoint menerima request lalu response terputus;
- endpoint hang sampai timeout;
- restart server saat executor aktif;
- deploy ketika request berjalan;
- jalankan dispatcher ganda;
- verifikasi next occurrence tetap independen;
- verifikasi stale Run tidak dikirim ulang.

## 13. Observability

Metric minimum:

```text
dispatch_lag = prepared_at - scheduled_for
start_lag    = started_at - scheduled_for
duration     = finished_at - started_at
pool_active
pool_pending
pool_capacity
pool_saturation
batch_duration
missed_start_window
expired_running
failed_rate
```

Dashboard minimum:

- pending, running, succeeded, failed, skipped per periode;
- start lag dan duration p50/p95/p99;
- oldest pending Run;
- direct executor heartbeat;
- active request dibanding concurrency limit;
- HTTP success/error/timeout per Task dan Client;
- CPU, memory, database, socket, disk, dan network.

Alert minimum:

- dispatcher heartbeat hilang lebih dari dua menit;
- direct executor heartbeat hilang;
- p95/p99 start lag melampaui SLA;
- pending Run melewati start window;
- running Run melewati deadline;
- failed rate naik di atas baseline;
- pool saturation berkepanjangan;
- memory, DB connection, socket, atau disk mendekati limit.

## 14. Cutover dan rollback

### 14.1 Cutover

```text
1. Deploy code direct dengan default driver tetap queue.
2. Jalankan migration compatibility.
3. Start direct executor dalam keadaan standby.
4. Pilih Task pilot yang replaceable.
5. Pastikan legacy/new executor tidak menjalankan occurrence yang sama.
6. Aktifkan direct untuk scope yang disepakati.
7. Monitor start lag, result, resource, dan heartbeat.
8. Perluas scope setelah peak test berhasil.
```

### 14.2 Rollback selama compatibility window

```text
1. Stop menerima Run baru pada direct executor.
2. Biarkan in-flight selesai atau mencapai timeout.
3. Jangan kirim ulang Run dengan outcome ambigu.
4. Ubah driver kembali ke queue untuk occurrence berikutnya.
5. Start Horizon.
6. Verifikasi queue dan scheduler health.
7. Catat scope dan waktu rollback.
```

Rollback berlaku untuk occurrence berikutnya. Run direct yang sudah gagal atau
ambigu tidak dipindahkan ke Redis.

## 15. Definition of done

Migrasi dianggap selesai jika:

- [ ] peak 123 memenuhi p99 start lag di bawah 60 detik;
- [ ] projected peak 246 sudah diuji;
- [ ] concurrency tidak pernah melampaui konfigurasi;
- [ ] satu HTTP failure tidak menghentikan batch;
- [ ] satu timeout hanya menggunakan satu slot;
- [ ] semua response error yang aman tersimpan dan sudah di-redact;
- [ ] tidak ada automatic retry;
- [ ] tidak ada catch-up occurrence lama;
- [ ] periode berikutnya membuat occurrence baru;
- [ ] duplicate materialization tetap nol;
- [ ] stale running Run menjadi terminal tanpa dikirim ulang;
- [ ] `prevent_overlap` tetap bekerja;
- [ ] Run Now berjalan di background;
- [ ] operator dapat melihat executor health tanpa Horizon;
- [ ] capacity test dan failure drill lulus;
- [ ] staged cutover dan rollback telah diuji;
- [ ] Redis/Horizon tidak lagi berada pada execution path final;
- [ ] seluruh dokumentasi dan deployment procedure sudah diperbarui.

## 16. Risiko dan mitigasi

| Risiko | Dampak | Mitigasi |
| --- | --- | --- |
| Concurrency terlalu kecil | Run mulai lewat menit yang sama | Load test peak 123/246 dan tune `C` |
| Concurrency terlalu besar | Connection storm, OOM, DB/socket pressure | Hard limit, resource monitoring, staged increase |
| Satu request hang | Satu slot tertahan | Connect timeout dan total timeout |
| Batch callback melempar exception | Result lain berpotensi tidak tersimpan | Isolasi callback dan final sweep |
| Executor crash | In-flight outcome ambigu | Deadline recovery, no retry, error message eksplisit |
| Pending Run lama ikut dieksekusi | Catch-up tidak diinginkan | Execution window filter dan terminal stale policy |
| Dispatcher tertahan eksekusi | Periode berikutnya terlambat | Pisahkan dispatcher dari supervised executor |
| Horizon dihapus terlalu cepat | Rollback sulit | Compatibility/soak window sebelum decommission |
| Response menyimpan credential | Kebocoran secret | Central redaction dan security test |
| Direct executor mati tanpa diketahui | Pending Run tidak mulai | Heartbeat dan alert |

## 17. Keputusan yang masih perlu dikunci

Rekomendasi default ditulis lebih dahulu:

| Pertanyaan | Rekomendasi | Status |
| --- | --- | --- |
| Bentuk executor | Supervised direct executor terpisah dari dispatcher | Menunggu persetujuan final |
| Concurrency awal QA | 20 | Menunggu hasil baseline/load test |
| Run Now | Pending lalu diambil executor dalam beberapa detik | Menunggu persetujuan final |
| Retry UI | Hapus; manual Run baru jika operator sengaja menjalankan ulang | Menunggu persetujuan final |
| Pending start window | 55 detik dari `scheduled_for` | Menunggu hasil load test |
| Status ambiguous outcome | `failed` dengan error message eksplisit | Menunggu persetujuan final |
| Queue removal | Setelah compatibility dan production soak window | Menunggu persetujuan final |
| Redis package removal | Hanya jika tidak digunakan subsistem lain | Perlu audit saat eksekusi |

## 18. Handoff untuk eksekusi dengan model berikutnya

Ketika implementasi dimulai:

1. baca dokumen ini sampai selesai;
2. baca `docs/http-concurrency-vs-redis-queue.md`;
3. audit ulang dirty worktree dan jangan menimpa perubahan user;
4. verifikasi keputusan pada Section 17;
5. ambil test baseline sebelum edit;
6. implementasikan Phase 1 sampai Phase 6 secara berurutan;
7. pertahankan queue path sampai direct mode lulus acceptance test;
8. jangan mengaktifkan production atau menghapus queue tanpa instruksi user;
9. catat deviasi planning dan alasannya di dokumen ini;
10. update checklist Definition of Done berdasarkan bukti test, bukan asumsi.

### Implementation stop conditions

Hentikan dan minta keputusan user jika:

- HTTP duration/timeout membuat same-minute SLA tidak realistis;
- Run Now harus benar-benar immediate tetapi supervised executor ditolak;
- sebagian Task ternyata membutuhkan preserve-every-occurrence;
- Redis masih digunakan komponen production lain yang belum teridentifikasi;
- capacity test menunjukkan concurrency aman tidak memenuhi start-window SLA;
- perubahan memerlukan automatic retry atau catch-up yang bertentangan dengan
  kontrak dokumen ini.
