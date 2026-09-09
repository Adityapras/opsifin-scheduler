# Justifikasi Queue, Redis, dan Reliability Opsifin Scheduler

## Status dokumen

| Metadata | Nilai |
| --- | --- |
| Tujuan | Menjawab challenge arsitektur sebelum Opsifin Scheduler digunakan sebagai pengganti Linux crontab legacy |
| Audiens | Engineering Leader, Developer, DevOps, dan QA |
| Arsitektur aktif | Laravel Scheduler, MySQL, Redis Queue, Laravel Horizon, dan Supervisor |
| Sumber workload | Crontab legacy aktif dan hasil import/audit repository |
| Sifat dokumen | Justifikasi teknis dan production-readiness criteria |

## 1. Ringkasan eksekutif

Queue tidak dipakai karena Linux cron tidak dapat menjalankan banyak process.
Linux cron memang dapat membuat banyak shell process pada menit yang sama.
Queue dipakai agar pekerjaan tersebut:

- mempunyai batas concurrency yang jelas;
- tidak hilang tanpa jejak;
- dapat dilacak dari waktu seharusnya berjalan sampai selesai;
- tidak langsung membanjiri server dan endpoint Client ketika terjadi burst;
- dapat dipulihkan dan didiagnosis ketika process atau infrastruktur gagal;
- dapat ditambah kapasitasnya tanpa membuat ulang crontab per Client;
- mempunyai audit, pause/resume, retry, dan overlap protection yang konsisten.

Redis bukan sumber data bisnis. MySQL tetap menjadi sumber kebenaran untuk
Client, Task Template, Schedule, Run, hasil HTTP, dan audit. Redis hanya menjadi
buffer pekerjaan antara dispatcher dan worker. Horizon mengatur sekumpulan
worker yang mengambil pekerjaan dari Redis secara concurrent.

Pernyataan yang harus digunakan:

> Existing Linux cron dapat memulai banyak shell process pada menit yang sama,
> tetapi sistem tidak mempunyai central trace untuk membuktikan kapan HTTP
> request benar-benar mulai, selesai, hang, duplicate, atau gagal. Opsifin
> Scheduler mengganti concurrency yang tidak terkontrol dengan concurrency yang
> terukur, dapat diaudit, dan dapat ditingkatkan berdasarkan SLA.

Arsitektur ini dapat diandalkan, tetapi reliability tidak otomatis diperoleh
hanya karena menggunakan Redis dan Horizon. Sebelum production, aplikasi harus
lolos capacity test, failure drill, monitoring, idempotency review, data
readiness, dan staged cutover yang didefinisikan dalam dokumen ini.

## 2. Bukti workload legacy

Hasil analisis crontab legacy aktif menunjukkan sekitar 235–236 entry aktif.
Perhitungan occurrence selama satu minggu menghasilkan estimasi berikut:

| Metrik | Estimasi |
| --- | ---: |
| Eksekusi per minggu | 286.484 Run |
| Rata-rata per hari | 40.926 Run |
| Rata-rata Schedule due per menit | 28,4 |
| Median Schedule due per menit | 28 |
| p95 Schedule due per menit | 66 |
| p99 Schedule due per menit | 107 |
| Peak Schedule due pada menit yang sama | 123 |

Contoh peak pada hari reguler:

| Waktu | Schedule due |
| --- | ---: |
| 00:00 | 115 |
| 01:00 | 123 |
| 05:00 | 105 |
| 10:00 | 107 |
| 21:00 | 118 |
| 22:00 | 114 |

Burst terjadi karena banyak Client menggunakan ekspresi yang sama dan semuanya
bertemu pada menit `00`:

```cron
*/2 * * * *
*/5 * * * *
*/6 * * * *
*/7 * * * *
*/10 * * * *
*/50 * * * *
*/59 * * * *
```

Ekspresi `*/59` berjalan pada menit `00` dan `59`, bukan setiap 59 menit dengan
jarak yang merata. Ekspresi `*/50` berjalan pada menit `00` dan `50`. Pola ini
ikut meningkatkan burst pada awal jam.

Angka 500 Run per hari yang pernah dipakai sebagai contoh sizing tidak
merepresentasikan full legacy workload. Jika seluruh entry legacy aktif
dimigrasikan, capacity planning harus memakai skala sekitar 40 ribu Run per hari
dan peak 123 occurrence per menit, kemudian ditambah proyeksi pertumbuhan.

Dalam perspektif message broker, volume tersebut masih rendah:

```text
28 job/menit  ~= 0,47 payload/detik secara rata-rata
123 job/menit ~= 2,05 payload/detik pada peak satu menit
```

Bottleneck yang lebih mungkin muncul lebih dahulu adalah durasi HTTP, jumlah
worker, RAM, koneksi MySQL, network, kapasitas endpoint Client, dan pertumbuhan
tabel `runs`, bukan kemampuan Redis menerima payload.

## 3. Challenge 1 — Kenapa memakai queue dan Redis?

### 3.1 Queue memisahkan scheduling dari execution

Tanpa queue:

```text
Linux/Laravel cron
    |
    v
Cari Schedule due
    |
    v
Jalankan HTTP dan tunggu sampai selesai
```

Dengan queue:

```text
Linux cron setiap menit
    |
    v
Laravel Scheduler
    |
    v
DueScheduleDispatcher
    |
    +-- hitung Schedule due
    +-- simpan Run di MySQL
    +-- push ExecuteRun(run_id)
    |
    v
Redis Queue
    |
    v
Horizon worker pool
    |
    v
HTTP endpoint Client
    |
    v
Simpan hasil ke MySQL
```

Pemisahan tersebut memberikan manfaat berikut:

1. Scheduler tidak tertahan oleh endpoint yang lambat.
2. Burst ditampung sebelum dieksekusi sesuai kapasitas.
3. Concurrency menjadi konfigurasi yang eksplisit.
4. Worker dapat ditambah atau dipisah tanpa mengubah dispatcher.
5. Kegagalan satu worker tidak langsung membatalkan seluruh batch.
6. Deployment dapat menghentikan worker secara graceful.
7. Setiap Run mempunyai lifecycle dan diagnostic trail.

Desain ini mengikuti pola **Queue-Based Load Leveling** dan **Competing
Consumers**: queue menjadi buffer antara producer dan consumer, sementara
beberapa consumer memproses pekerjaan secara paralel sesuai kapasitas. Referensi:

- [Queue-Based Load Leveling — Microsoft Architecture Center](https://learn.microsoft.com/en-us/azure/architecture/patterns/queue-based-load-leveling)
- [Competing Consumers — Microsoft Architecture Center](https://learn.microsoft.com/en-us/azure/architecture/patterns/competing-consumers)

### 3.2 Kenapa Redis?

Redis dipilih karena:

1. Laravel Horizon bekerja menggunakan Redis.
2. Redis mendukung push/pop queue dengan latency rendah.
3. Beban queue dipisahkan dari tabel bisnis MySQL.
4. Horizon menyediakan worker management, autoscaling, queue wait, throughput,
   recent job, dan failed-job visibility.
5. Worker dapat ditambah secara horizontal dengan menggunakan Redis bersama.
6. Payload Opsifin kecil karena queue hanya membawa identitas Run, bukan seluruh
   response atau data bisnis.

Pembagian tanggung jawab:

| Penyimpanan | Tanggung jawab |
| --- | --- |
| MySQL | Client, credential, Task Template, Schedule, Run, hasil HTTP, audit, dan failed job record |
| Redis queue DB | Payload `ExecuteRun(run_id)` yang sedang menunggu atau reserved |
| Redis Horizon DB | Supervisor state, recent job, worker metrics, dan Horizon metadata |

Redis bukan pengganti MySQL dan Horizon bukan pengganti Execution Logs. Status
bisnis authoritative tetap berada pada tabel `runs`.

### 3.3 Apakah Redis wajib?

Redis tidak mutlak diperlukan untuk membuat queue. Laravel juga mendukung
database queue dan broker lain. Namun Redis + Horizon layak dipilih jika sistem
membutuhkan:

- concurrency dinamis;
- beberapa supervisor dengan kapasitas berbeda;
- queue wait dan throughput monitoring;
- worker scale-out;
- pemisahan job critical, regular, dan slow;
- graceful worker lifecycle.

Jika seluruh kebutuhan tersebut tidak ada dan workload sangat kecil, database
queue dapat lebih sederhana. Pada Opsifin, kebutuhan observability, burst
control, dan pertumbuhan Client membuat Redis + Horizon memberikan manfaat yang
lebih besar daripada biaya operasional tambahannya.

Konsekuensi yang harus diterima:

- Redis menjadi service yang harus dimonitor;
- persistence dan memory policy harus benar;
- MySQL dan Redis membuat dual-write boundary;
- recovery dan deployment procedure harus tersedia;
- Redis pada satu VPS tetap menjadi single point of failure.

## 4. Challenge 2 — Apa bedanya dengan existing?

| Area | Existing Linux crontab | Opsifin Scheduler |
| --- | --- | --- |
| Definisi Schedule | File crontab dan script per Client | Database dan UI terpusat |
| Trigger | Linux membuat shell process | Satu dispatcher membaca Schedule due |
| Eksekusi | Shell/curl langsung | Redis → Horizon → worker HTTP |
| Concurrency | Tidak dibatasi secara global | Dibatasi oleh jumlah worker |
| Burst | Langsung membuat banyak process | Ditampung dan dikuras sesuai kapasitas |
| Overlap | Hanya sebagian menggunakan `flock` | Atomic overlap guard per Schedule |
| Connect/request timeout | Mayoritas script tidak memiliki timeout | Didefinisikan per Task Template |
| Trace waktu | Hanya waktu yang ditulis di crontab | `scheduled_for`, `queued_at`, `started_at`, `finished_at` |
| Hasil | Log tersebar atau tidak tersedia | `succeeded`, `failed`, `skipped`, HTTP status, duration, dan error |
| Retry | Menjalankan script secara manual | Retry dari UI membuat Run baru |
| Pause/resume | Edit atau comment crontab | State Schedule dan Client |
| Audit perubahan | Tidak terpusat | Audit history |
| Credential | Banyak hardcoded dalam script | Konfigurasi Client terpusat |
| Recovery process mati | Tidak terstandarisasi | Supervisor, reconciler, dan execution deadline |
| Scaling | Menambah process secara tidak terukur | Menambah worker/supervisor/node secara terukur |
| Reporting | Membaca banyak log | Execution Logs terpusat |

### 4.1 Risiko existing yang ditemukan

Audit legacy menemukan:

- sekitar 235–236 entry aktif;
- hanya sekitar 17% job aktif menggunakan `flock`;
- mayoritas script tidak memakai `--connect-timeout`;
- mayoritas script tidak memakai `--max-time`;
- log tersebar dan banyak invocation tidak mempunyai output terpusat;
- credential tersebar di banyak script;
- perubahan crontab tidak mempunyai audit trail;
- tidak ada status machine untuk membedakan queued, running, failed, dan
  skipped.

Contoh failure existing:

```text
Schedule setiap 2 menit
Request menggantung 10 menit
Tidak menggunakan flock

00:00 -> process 1
00:02 -> process 2
00:04 -> process 3
00:06 -> process 4
00:08 -> process 5
```

Linux terus membuat process baru. Jika endpoint tidak pulih, process dapat terus
bertambah sampai RAM, socket, atau process limit habis.

Pada Opsifin Scheduler:

```text
concurrency dibatasi worker pool
request mempunyai timeout
overlap dapat dicegah per Schedule
job selebihnya menunggu atau skipped sesuai kebijakan
```

Perbedaan utamanya bukan sekadar UI versus file cron. Perbedaannya adalah
**uncontrolled execution** versus **controlled and observable execution**.

### 4.2 Diagram tiga pilihan arsitektur

Tiga pendekatan yang dibandingkan adalah:

1. existing Linux crontab yang langsung menjalankan shell/curl;
2. Laravel dispatcher dengan bounded Direct Concurrent HTTP Pool tanpa message
   queue;
3. Laravel dispatcher dengan Redis Queue dan Horizon worker.

#### A. Existing Linux crontab

```text
                         MENIT 10:00
                              |
                         Linux crond
                              |
             +----------------+----------------+
             |                |                |
             v                v                v
         shell/curl A     shell/curl B     shell/curl C      ...
             |                |                |
             v                v                v
          Client A         Client B         Client C
             |                |                |
             +----------------+----------------+
                              |
                   log tersebar / tidak ada
```

Karakteristik:

- Linux langsung membuat process untuk setiap entry yang due;
- seluruh process dapat mulai berdekatan, tetapi concurrency tidak dibatasi
  secara global;
- tidak ada coordinator yang mencatat lifecycle seluruh process;
- jika request hang dan tidak memakai `flock`, invocation berikutnya tetap dapat
  membuat process baru;
- tidak ada central trace yang membuktikan kapan endpoint menerima request.

Tempat menunggu:

```text
OS process scheduler, network, atau endpoint Client
```

Jika kapasitas server/endpoint habis, tidak ada buffer aplikasi. Beban langsung
menjadi process, socket, dan request aktif.

#### B. Direct Concurrent HTTP Pool

```text
                         MENIT 10:00
                              |
                       Laravel Scheduler
                              |
                              v
                  Query seluruh Schedule due
                              |
                              v
                       Materialize Run
                              |
                              v
                 Satu batch executor process
                              |
               bounded concurrency pool = 3
             +----------------+----------------+
             |                |                |
             v                v                v
        pool slot 1      pool slot 2      pool slot 3
             |                |                |
             v                v                v
          Client A         Client B         Client C
             |                |                |
             +----------------+----------------+
                              |
                    callback per response
                              |
                              v
                    update Run di MySQL
```

Karakteristik:

- dispatcher sekaligus menjadi batch executor;
- satu process PHP dapat membuka beberapa asynchronous HTTP connection;
- concurrency ditentukan oleh ukuran pool;
- seluruh request due dapat dibuka dalam window rapat jika pool cukup besar;
- pekerjaan yang belum mendapat slot menunggu di memory/batch process;
- batch harus tetap hidup sampai seluruh request selesai atau timeout.

Tempat menunggu:

```text
memory dan internal queue milik batch executor
```

Jika batch process mati, coordinator untuk seluruh request in-flight ikut hilang.
Run di MySQL tetap dapat dipakai untuk recovery, tetapi recovery partial batch,
cancel, Run Now, retry, dan ambiguous HTTP result harus dibangun sendiri.

#### C. Redis Queue + Horizon

```text
                         MENIT 10:00
                              |
                       Laravel Scheduler
                              |
                              v
                  Query seluruh Schedule due
                              |
                              v
                   Materialize Run di MySQL
                              |
                              v
                  push ExecuteRun(run_id)
                              |
                              v
                        Redis Queue
                    pending / reserved job
                              |
             +----------------+----------------+
             |                |                |
             v                v                v
        Horizon worker 1 Horizon worker 2 Horizon worker 3   ...
             |                |                |
             v                v                v
          Client A         Client B         Client C
             |                |                |
             +----------------+----------------+
                              |
                              v
                    update Run di MySQL
```

Karakteristik:

- dispatcher hanya menghitung Schedule dan memublikasikan pekerjaan;
- worker menjadi executor yang terpisah;
- concurrency ditentukan oleh worker aktif;
- pekerjaan yang belum mendapat worker tetap menjadi payload Redis;
- satu worker mati umumnya hanya memengaruhi Run yang sedang ditanganinya;
- worker dapat ditambah pada node lain tanpa mengubah dispatcher.

Tempat menunggu:

```text
Redis Queue sebagai buffer yang terlihat dan dapat dimonitor
```

Jika worker penuh, Run memang menunggu. Perbedaannya, jumlah yang menunggu,
oldest queue wait, dan status worker dapat diamati dan diberi alert.

### 4.3 Diagram burst yang sama pada tiga arsitektur

Contoh: pukul 10:00 terdapat 20 Client due, setiap HTTP request berlangsung 30
detik.

#### Existing Linux crontab

```text
10:00:00  cron membuat sampai 20 shell/curl process
           | | | | | | | | | | | | | | | | | | | |
           v v v v v v v v v v v v v v v v v v v v
          20 request mencoba berjalan bersamaan

Kapasitas penuh:
process/socket tetap dibuat; server dan endpoint menerima seluruh burst
```

Hasil terbaiknya adalah seluruh request mulai berdekatan. Hasil buruknya adalah
CPU/RAM/socket atau endpoint overload. Tanpa central trace, kedua kondisi sulit
dibedakan setelah kejadian.

#### Direct HTTP Pool dengan concurrency 5

```text
10:00:00  Client  1..5  mulai
10:00:30  Client  6..10 mulai
10:01:00  Client 11..15 mulai
10:01:30  Client 16..20 mulai
10:02:00  batch selesai

Yang menunggu berada dalam batch process.
```

#### Redis Queue dengan 5 worker warm

```text
10:00:00  Client  1..5  mulai; 15 Run queued di Redis
10:00:30  Client  6..10 mulai; 10 Run queued di Redis
10:01:00  Client 11..15 mulai;  5 Run queued di Redis
10:01:30  Client 16..20 mulai;  0 Run queued di Redis
10:02:00  seluruh Run selesai

Yang menunggu berada di Redis dan queue wait dapat dimonitor.
```

Dengan concurrency yang sama, Direct Pool dan Queue mempunyai throughput ideal
yang hampir sama. Perbedaan utamanya adalah durability tempat menunggu,
isolation process, operasional, dan recovery.

### 4.4 Komparasi ringkas

| Aspek | Existing Linux cron | Direct Concurrent HTTP Pool | Redis Queue + Horizon |
| --- | --- | --- | --- |
| Execution owner | Banyak shell process independen | Satu batch executor | Banyak worker independen |
| Sumber Schedule | File crontab | Database | Database |
| Tempat menunggu | OS/network; tidak ada application buffer | Memory/internal pool | Redis Queue |
| Concurrency | Tidak dibatasi global | Bounded pool | Bounded worker pool |
| Start berdekatan | Tinggi, tetapi tidak terukur | Tinggi jika pool cukup | Tinggi jika worker sudah warm dan cukup |
| Burst protection | Tidak ada | Ada selama pool bounded | Ada melalui queue buffer |
| Failure blast radius | Satu process per job, tetapi dapat menumpuk tanpa batas | Satu crash dapat memengaruhi seluruh batch | Satu crash umumnya memengaruhi satu Run |
| Pending durability | Tidak ada | Tidak ada di memory; perlu state DB | Payload tersimpan di Redis sesuai persistence |
| Partial recovery | Manual dari process/log | Harus dibuat dari Run state | Reconciler dan worker lifecycle tersedia |
| Duplicate protection | Bergantung `flock` dan endpoint | Harus memakai atomic claim/idempotency | Atomic claim; endpoint tetap perlu idempotency |
| Prevent overlap | Sebagian memakai `flock` | Harus memakai atomic DB slot | Atomic DB slot sudah tersedia |
| Central trace | Tidak ada | Dapat dibuat dari Run | Run + Horizon + log |
| Cancel pending | Tidak tersedia | Sulit jika sudah masuk pool | Dapat dilakukan ketika masih queued |
| Run Now dari UI | Menjalankan script manual | Tidak boleh menahan PHP-FPM; butuh background executor | Publish ke queue |
| Horizontal scaling | Membagi crontab secara manual | Perlu sharding/leader coordination | Tambah worker node dengan broker bersama |
| Resource idle | Paling rendah | Rendah–menengah | Paling tinggi karena broker dan worker daemon |
| Resource saat burst | Tidak terkontrol | Dikontrol pool | Dikontrol worker |
| Infrastruktur | Paling sederhana | MySQL + application | MySQL + Redis + Horizon + Supervisor |
| Observability | Rendah | Menengah jika dibangun lengkap | Tinggi |
| Operasional | Edit file dan baca log | Kelola batch/recovery custom | Kelola Redis, Horizon, worker, dan queue |
| Cocok untuk | Job sedikit, sederhana, dan risiko rendah | HTTP fan-out cepat dan terprediksi | Workload burst, bertumbuh, perlu audit dan recovery |

### 4.5 Pros dan cons

#### A. Existing Linux crontab

Pros:

- baseline RAM rendah ketika tidak ada job;
- tidak membutuhkan database, Redis, atau worker daemon;
- implementasi sederhana dan sudah terbukti memanggil endpoint selama ini;
- setiap entry menjadi process OS terpisah;
- banyak entry dapat dibuat hampir bersamaan oleh Linux.

Cons:

- tidak ada global concurrency limit;
- tidak ada buffer ketika terjadi burst;
- hanya sebagian job memakai `flock`;
- timeout dan logging tidak konsisten;
- process hang dapat bertumpuk sampai OOM;
- tidak ada central execution trace dan audit;
- perubahan harus dilakukan pada banyak file/Client;
- credential tersebar;
- tidak dapat membuktikan SLA start dan completion;
- scaling berarti memperbesar server atau membagi crontab secara manual.

#### B. Direct Concurrent HTTP Pool

Pros:

- satu process PHP dapat mengelola banyak koneksi I/O;
- berpotensi lebih hemat RAM daripada satu PHP worker per request;
- fan-out dapat dimulai dalam window yang rapat;
- tidak membutuhkan Redis dan Horizon;
- concurrency dapat dibatasi;
- broker latency tidak ada;
- cocok untuk request cepat, response kecil, dan jumlah Client terprediksi.

Cons:

- dispatcher/batch executor menjadi long-running process;
- fatal error atau OOM dapat memengaruhi seluruh batch;
- pekerjaan yang menunggu hanya berada di memory kecuali dibuat persistent
  state tambahan;
- recovery partial batch harus dibuat sendiri;
- satu slow-tail request memperpanjang umur batch;
- bounded pool, cancellation, metrics, retry, graceful shutdown, dan
  backpressure harus diimplementasikan sendiri;
- Run Now dari UI tetap memerlukan background execution path;
- horizontal scaling memerlukan sharding dan coordination;
- setelah semua reliability feature ditambahkan, aplikasi berpotensi membuat
  queue implementation sendiri di MySQL.

#### C. Redis Queue + Horizon

Pros:

- queue menjadi buffer burst yang terlihat;
- dispatcher dan executor terpisah;
- concurrency dapat dibatasi dan diubah tanpa mengubah Schedule;
- failure isolation per worker lebih baik;
- pending job dapat bertahan saat worker restart jika Redis persistence benar;
- Horizon menyediakan worker state, queue wait, throughput, dan failed-job
  visibility;
- mendukung beberapa supervisor dan queue class;
- worker dapat di-scale horizontal;
- deployment dapat dilakukan secara graceful;
- Run Now, manual Retry, cancel queued Run, dan pause lebih natural.

Cons:

- membutuhkan Redis, Horizon, dan Supervisor;
- baseline RAM lebih besar;
- job menunggu jika jumlah worker tidak cukup;
- autoscaling tidak instan;
- menambah worker menaikkan RAM, DB connection, HTTP connection, dan beban
  endpoint;
- MySQL dan Redis membuat dual-write boundary;
- persistence, no-eviction policy, monitoring, dan recovery Redis wajib;
- satu Redis pada satu VPS tetap menjadi single point of failure;
- exactly-once HTTP tetap membutuhkan idempotency pada endpoint.

### 4.6 Perbandingan pada server RAM 1 GB

| Pendekatan | Kelayakan pada 1 GB | Catatan |
| --- | --- | --- |
| Existing Linux cron | Paling ringan saat idle | Tetap berisiko OOM ketika process hang dan invocation bertumpuk |
| Direct HTTP Pool | Mungkin untuk workload terbatas | Satu process lebih hemat, tetapi batch crash dan recovery menjadi risiko |
| Redis + Horizon all-in-one | Hanya layak untuk pilot/scale kecil | Web, PHP-FPM, MySQL, Redis, Horizon, dan worker berebut RAM |
| Redis + Horizon dengan DB/Redis eksternal | Lebih layak | Server 1 GB dapat menjadi app/worker kecil dengan worker terbatas |
| Server 1 GB sebagai worker-only node | Layak sebagai unit scale-out | Jalankan sedikit worker; tambah node jika perlu |

Konfigurasi `HORIZON_MAX_PROCESSES=10` tidak aman diasumsikan cocok pada server 1
GB. Sepuluh worker mempunyai memory limit 128 MB per worker pada konfigurasi
aktif, belum termasuk Horizon master, PHP-FPM, web server, MySQL, Redis, dan OS.

Jika seluruh stack terpaksa berada pada satu server 1 GB, starting point untuk
pilot adalah:

```env
HORIZON_MIN_PROCESSES=1
HORIZON_MAX_PROCESSES=1
TELESCOPE_ENABLED=false
```

Setelah RSS worker, MySQL, Redis, dan PHP-FPM diukur, maksimum worker mungkin
dinaikkan menjadi dua. Konfigurasi tersebut tidak boleh dianggap mampu mengambil
alih full workload tanpa load test, karena rata-rata workload legacy mencapai
28,4 job/menit dan peak mencapai 123 job/menit.

Reference deployment repository menetapkan baseline awal yang lebih wajar:

```text
2 vCPU
RAM 4 GB
SSD yang memperhitungkan Run history, log, dan backup
```

### 4.7 Kapan memilih masing-masing pendekatan?

Pilih Existing Linux cron jika seluruh kondisi berikut benar:

- jumlah job sangat sedikit dan stabil;
- tidak membutuhkan central audit dan trace;
- failure manual masih dapat diterima;
- setiap script mempunyai timeout, logging, dan overlap protection yang benar;
- pertumbuhan Client rendah.

Pilih Direct Concurrent HTTP Pool jika:

- seluruh job hanya HTTP;
- request cepat dan durasinya terprediksi;
- jumlah Client terbatas;
- start yang rapat lebih penting daripada durability buffer;
- tim siap membuat recovery, metrics, graceful shutdown, dan partial batch
  handling sendiri;
- penghematan RAM/infrastruktur lebih penting daripada failure isolation.

Pilih Redis Queue + Horizon jika:

- workload burst dan bertumbuh;
- durasi endpoint bervariasi;
- audit dan central trace diwajibkan;
- worker harus dapat ditambah atau dipisah per workload;
- queue wait perlu diukur;
- failure isolation dan recovery lebih penting;
- sistem akan menjadi shared scheduler untuk banyak Client.

Untuk Opsifin, Redis Queue + Horizon adalah pilihan paling seimbang karena
jumlah Client dan Run bertumbuh, workload mempunyai burst besar, endpoint
bervariasi, dan aplikasi ditujukan menjadi control plane terpusat. Direct Pool
tetap valid sebagai alternatif jika target deployment harus bertahan pada RAM
sangat kecil dan hasil load/failure test membuktikan trade-off-nya dapat
diterima.

### 4.8 Ringkasan satu slide

```text
EXISTING CRON
+ ringan dan langsung paralel
- tidak terkontrol, tidak terukur, trace/recovery lemah

DIRECT HTTP POOL
+ concurrent dengan process lebih sedikit dan fan-out rapat
- batch menjadi single failure domain; durability/recovery harus dibuat sendiri

REDIS QUEUE + HORIZON
+ controlled concurrency, durable buffer, observable, scalable
- membutuhkan RAM dan operasi lebih besar; job dapat menunggu worker
```

Jawaban singkat untuk presentasi:

> Existing mengoptimalkan kesederhanaan, Direct Pool mengoptimalkan efisiensi
> koneksi, sedangkan Queue mengoptimalkan reliability dan operasional. Opsifin
> memilih Queue karena scheduler ini akan menjadi shared control plane untuk
> banyak Client. Konsekuensinya, worker harus di-size berdasarkan SLA dan server
> 1 GB tidak boleh dipaksa menjalankan full all-in-one workload tanpa pemisahan
> service atau load test.

## 5. Challenge 3 — Existing dapat jalan sekaligus, apakah queue juga bisa?

### 5.1 Existing hanya membuktikan process dibuat pada menit yang sama

Linux cron mencoba membuat process ketika ekspresi cron cocok. Hal tersebut
tidak membuktikan:

- detik aktual shell process mulai;
- waktu DNS resolution;
- waktu TCP dan TLS connection terbentuk;
- waktu endpoint menerima request;
- apakah process hang atau dibunuh;
- apakah respons berhasil;
- selisih aktual antarklien.

Pernyataan yang akurat:

> Existing memulai banyak shell process pada menit yang sama, tetapi tidak ada
> central trace untuk membuktikan HTTP request benar-benar mulai dan selesai
> pada waktu yang sama.

Linux cron, queue worker, dan direct HTTP pool sama-sama bukan hard real-time
scheduler. OS scheduling, query database, process startup, DNS, TLS, network,
dan endpoint dapat menghasilkan selisih waktu aktual.

### 5.2 Queue tetap concurrent

Satu Horizon worker menangani satu HTTP queue job pada satu waktu:

```text
20 worker
~= maksimal 20 HTTP request in-flight
```

Jika 20 Client due dan 20 worker sudah warm, seluruh request dapat mulai hampir
bersamaan. Jika 100 Client due dan hanya 10 worker:

```text
10 mulai
90 tetap queued
```

Queue tidak menghilangkan concurrency. Queue membuat batas concurrency
eksplisit, terukur, dan dapat dikendalikan.

### 5.3 Konfigurasi sekarang belum menjamin seluruh peak mulai bersamaan

Konfigurasi production saat ini:

```text
minimum worker          = 2
maximum worker          = 10
balance strategy        = auto/time
balance maximum shift   = 1 process
balance cooldown        = 3 detik
```

Ketika queue sebelumnya kosong, Horizon dapat berada pada dua worker. Horizon
kemudian menambah worker secara bertahap hingga maksimal sepuluh. Dengan peak
123 job, sebagian Run akan menunggu.

Perkiraan kapasitas teoritis:

```text
capacity per minute ~= worker x 60 / rata-rata durasi job dalam detik
```

Dengan 10 worker:

| Rata-rata durasi HTTP | Kapasitas teoritis |
| ---: | ---: |
| 2 detik | 300 job/menit |
| 5 detik | 120 job/menit |
| 10 detik | 60 job/menit |
| 20 detik | 30 job/menit |
| 30 detik | 20 job/menit |
| 60 detik | 10 job/menit |

Dengan average arrival 28,4 job/menit, sepuluh worker hanya mempunyai steady
state capacity yang cukup apabila rata-rata durasi job berada di bawah sekitar:

```text
10 x 60 / 28,4 ~= 21 detik
```

Perkiraan waktu mulai job terakhir pada burst 123 job dengan 10 worker:

| Durasi satu job | Perkiraan job terakhir mulai |
| ---: | ---: |
| 2 detik | 24 detik |
| 5 detik | 60 detik |
| 10 detik | 2 menit |
| 30 detik | 6 menit |
| 60 detik | 12 menit |

Estimasi tersebut belum memasukkan waktu autoscaling dari minimum dua worker.

### 5.4 Requirement harus diubah menjadi SLA yang terukur

Jangan menggunakan requirement:

```text
Semua job harus jalan bersamaan.
```

Gunakan SLO seperti:

```text
Critical:
p95 started_at - scheduled_for <= 10 detik

Regular:
p95 started_at - queued_at <= 60 detik

Semua:
tidak ada duplicate materialization
tidak ada Run melewati execution deadline tanpa recovery
```

Semua waktu berikut harus disimpan dan ditampilkan:

```text
scheduled_for
queued_at
started_at
finished_at
```

Metric utama:

```text
dispatch_lag = queued_at - scheduled_for
queue_wait   = started_at - queued_at
start_lag    = started_at - scheduled_for
duration     = finished_at - started_at
```

Dengan metric tersebut, sistem baru dapat membuktikan apakah requirement waktu
dipenuhi. Existing tidak mempunyai bukti yang setara.

### 5.5 Strategi untuk service yang harus mulai rapat

Pisahkan workload:

```text
critical -> fixed/warm workers
fast     -> autoscaling workers
default  -> autoscaling workers
slow     -> limited workers
```

Contoh:

| Queue | Contoh | Kebijakan |
| --- | --- | --- |
| `critical` | service yang harus start dalam window ketat | Worker fixed dan selalu warm |
| `fast` | repost, update balance, print status | Autoscaling |
| `default` | job reguler | Autoscaling |
| `slow` | invoice, remittance, billing, file sync | Worker dibatasi |

Laravel Horizon mendukung beberapa supervisor dengan queue, balancing strategy,
dan worker count berbeda. Referensi:

- [Laravel Horizon — Supervisors and Balancing](https://laravel.com/framework/docs/12.x/horizon)

### 5.6 Staggering

Tidak semua job secara bisnis harus berjalan pada detik yang sama. Banyak burst
legacy terjadi hanya karena seluruh Client memakai ekspresi identik.

Contoh 26 Client menggunakan:

```cron
*/7 * * * *
```

Seluruhnya due pada menit yang sama. Job noncritical dapat disebar:

```cron
0-59/7 * * * *
1-59/7 * * * *
2-59/7 * * * *
3-59/7 * * * *
```

Kebijakan:

- job yang benar-benar time-critical tetap aligned;
- polling reguler di-stagger antar-Client;
- job lambat masuk queue terpisah.

Staggering dapat mengurangi peak dan kebutuhan worker tanpa mengurangi frekuensi
per Client.

## 6. Challenge 4 — Apakah ke depannya reliable?

Jawaban yang harus diberikan:

> Fondasi arsitekturnya dapat reliable dan dapat scale, tetapi reliability bukan
> otomatis didapat dari Redis dan Horizon. Reliability harus dibuktikan melalui
> data integrity, idempotency, capacity test, failure drill, monitoring,
> retention, dan cutover procedure.

### 6.1 Perlindungan yang sudah ada

#### Unique materialization

Occurrence Schedule memiliki materialization key unik berdasarkan Schedule dan
`scheduled_for`. Jika dispatcher mencoba membentuk occurrence yang sama dua
kali, database hanya mengizinkan satu Run.

#### Atomic Run claim

Worker melakukan update bersyarat:

```sql
UPDATE runs
SET status = 'running'
WHERE id = ? AND status = 'queued';
```

Hanya satu worker yang berhasil mengklaim Run. Jika payload Redis terduplikasi,
worker berikutnya menjadi no-op.

#### Atomic overlap protection

Schedule dapat mempunyai satu running slot:

```text
previous Run masih running
-> occurrence berikutnya skipped
```

Ini adalah pengganti `flock` berbasis database sehingga tetap berfungsi ketika
worker berada pada beberapa server.

#### Timeout invariant

Konfigurasi harus selalu mengikuti:

```text
Task request timeout
<= Horizon worker timeout
< Redis retry_after
<= Supervisor stopwaitsecs
```

Konfigurasi default saat ini:

```text
Horizon timeout         = 1900 detik
Redis retry_after       = 2000 detik
Supervisor stopwaitsecs = 2000 detik
```

#### Recovery setelah commit sebelum publish

Urutan dispatch:

```text
commit Run ke MySQL
-> push ExecuteRun ke Redis
-> simpan Redis job UUID
```

Jika process mati setelah MySQL commit tetapi sebelum Redis push atau sebelum
queue ID disimpan, `jobs:reconcile-queued` mencari Run queued tanpa queue ID dan
mempublikasikannya kembali.

#### Worker supervision

Supervisor menjaga Horizon tetap hidup dan menjalankannya kembali setelah
process berhenti. Horizon mendukung graceful termination agar deployment tidak
langsung memotong seluruh worker aktif.

#### Authoritative business status

Horizon hanya menunjukkan lifecycle queue infrastructure. Status bisnis tetap
berada pada Execution Logs dan tabel `runs`:

```text
queued -> running -> succeeded
                  -> failed
       -> skipped
```

### 6.2 Reliability boundary yang harus diakui

#### Exactly-once HTTP tidak dapat dijamin scheduler sendirian

Kedua desain, legacy maupun queue, mempunyai failure window:

```text
endpoint sudah menerima request
-> process/worker mati
-> hasil belum tersimpan di MySQL
```

Scheduler tidak mengetahui apakah request aman dikirim ulang. Solusinya adalah
idempotency key:

```http
X-Idempotency-Key: <run-uuid>
```

Endpoint Client harus menyimpan key dan menolak side effect kedua untuk key yang
sama. Tanpa idempotency pada endpoint, tidak ada arsitektur yang dapat
menjanjikan exactly-once side effect hanya dari sisi scheduler.

#### Satu VPS masih single point of failure

Jika UI, dispatcher, MySQL, Redis, dan Horizon berada pada satu VPS:

```text
VPS mati -> seluruh sistem berhenti
```

Desain tersebut dapat mempunyai recovery yang baik, tetapi belum high
availability. Ketika SLA bisnis meningkat, pisahkan execution node, siapkan
database/broker failover, dan definisikan RTO/RPO.

#### MySQL dan Redis adalah dual write

Reconciler saat ini menutup failure window utama. Untuk reliability lebih tinggi,
desain dapat dikembangkan menjadi transactional outbox:

```text
BEGIN
  insert Run
  insert Outbox Event
COMMIT

Outbox Relay
  -> publish Redis
  -> mark published
```

Referensi:

- [Transactional Outbox — AWS Prescriptive Guidance](https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/transactional-outbox.html)

Consumer tetap harus idempotent karena duplicate message masih mungkin terjadi.

#### Total kehilangan Redis membutuhkan recovery procedure

Jika Redis kehilangan seluruh payload setelah `queue_job_id` tersimpan di
MySQL, reconciler yang hanya mencari `queue_job_id IS NULL` tidak otomatis
mengetahui bahwa payload telah hilang. Risiko ini dimitigasi melalui:

- Redis AOF persistence;
- `maxmemory-policy=noeviction`;
- backup/replica sesuai SLA;
- alert dan queue recovery procedure;
- transactional outbox pada reliability tier berikutnya.

### 6.3 Pertumbuhan histori

Dengan estimasi 40.926 Run per hari:

```text
sekitar 1,23 juta Run per bulan
sekitar 14,9 juta Run per tahun
```

Jika satu row beserta index dan response excerpt rata-rata 2 KB:

```text
sekitar 2,5 GB per bulan
```

Jika rata-rata 5 KB:

```text
sekitar 6 GB per bulan
```

Sistem harus mempunyai:

- retention Run terminal;
- purge dalam chunk;
- archive jika histori panjang diwajibkan;
- pembatasan response excerpt;
- monitoring ukuran tabel dan disk;
- backup serta restore test.

## 7. Reference architecture bertumbuh

### 7.1 Fase awal

```text
Satu VPS
  Admin UI
  Laravel Scheduler
  MySQL
  Redis
  Horizon 2..N workers
```

Cocok untuk initial rollout selama resource, queue wait, dan RTO masih memenuhi
SLA.

### 7.2 Fase scale-out

```text
                     CONTROL PLANE
       +--------------------------------------+
       | Web/Admin UI                         |
       | Scheduler/Dispatcher leader          |
       | MySQL                                |
       +------------------+-------------------+
                          |
                          v
                    Redis Queue
                          |
             +------------+------------+
             |            |            |
             v            v            v
        Worker Node A Worker Node B Worker Node C
        Horizon       Horizon       Horizon
             |            |            |
             +------------+------------+
                          |
                          v
                    Endpoint Client
```

Worker node bersifat stateless. Kapasitas ditambah secara horizontal tanpa
memindahkan tanggung jawab Schedule dan audit dari control plane.

### 7.3 Fase high availability

- web/application minimal dua node di belakang load balancer;
- satu active scheduler leader dan standby;
- worker multi-node;
- MySQL backup, replica, dan failover sesuai RPO/RTO;
- Redis persistence dan replica/failover atau managed broker;
- central log dan metrics;
- transactional outbox;
- archive storage untuk histori lama.

### 7.4 Fase ribuan Client atau strict simultaneous

Jika ribuan Client harus mengeksekusi job hampir pada detik yang sama, central
push executor akan memerlukan banyak koneksi dan worker. Alternatifnya adalah
Client Agent/Pull Model:

```text
Control Plane
  -> mengirim Schedule lebih awal

Agent pada setiap Client
  -> menyimpan Schedule lokal
  -> mengeksekusi berdasarkan local clock
  -> mengirim hasil ke Control Plane
```

Model agent mengurangi kebutuhan fan-out dari pusat, tetapi menambah kompleksitas
instalasi, versioning, certificate, NTP, offline reconciliation, agent health,
dan security. Model ini belum diperlukan untuk workload sekarang, tetapi dapat
menjadi arah jangka panjang jika kebutuhan strict timing dan jumlah Client
meningkat drastis.

## 8. Production reliability requirements

### 8.1 SLO yang harus disepakati

Contoh target awal, bukan keputusan final:

| Metrik | Contoh target |
| --- | ---: |
| Dispatcher heartbeat | Tidak boleh hilang lebih dari 2 menit |
| Critical p95 start lag | Maksimal 10 detik |
| Regular p95 queue wait | Maksimal 60 detik |
| Duplicate materialization | 0 |
| Run melewati deadline tanpa recovery | 0 |
| Redis eviction | 0 |
| Recovery Horizon setelah crash | Maksimal 2 menit |
| Queue infrastructure failure berulang | 0 |

Target harus disesuaikan dengan durasi HTTP, kapasitas VPS, endpoint Client, dan
kebutuhan bisnis.

### 8.2 Queue separation

Minimum desain production:

```text
critical -> fixed/warm workers
default  -> autoscaling workers
slow     -> limited workers
```

Job lambat tidak boleh menghabiskan seluruh worker job critical.

### 8.3 Capacity test

Uji sekurang-kurangnya:

```text
123 job due bersamaan = current measured peak
246 job due bersamaan = proyeksi 2x growth
```

Variasi endpoint:

- respons 100 ms;
- respons 5 detik;
- respons 30 detik;
- timeout maksimum;
- kombinasi sebagian cepat dan sebagian lambat;
- HTTP 2xx, 4xx, 5xx, connection failure, dan response disconnect.

Acceptance:

- jumlah Run yang dibuat sama dengan occurrence yang diharapkan;
- tidak ada duplicate materialization;
- start lag memenuhi SLO;
- tidak ada swap atau OOM;
- MySQL tidak kehabisan connection;
- Redis tidak melakukan eviction;
- endpoint tidak mengalami overload yang tidak dapat diterima;
- seluruh Run menjadi terminal atau tetap queued dengan alasan yang terlihat.

### 8.4 Failure drill

Sebelum production, lakukan secara sengaja:

- stop Horizon ketika queue berisi payload;
- kill satu worker saat HTTP request berjalan;
- restart Redis;
- putuskan database sementara;
- buat endpoint menggantung sampai timeout;
- restart server ketika queue mempunyai backlog;
- deploy ketika sebagian Run masih running;
- jalankan dispatcher ganda untuk menguji idempotency.

Setiap skenario harus mempunyai expected result, recovery step, dan bukti hasil.

### 8.5 Monitoring dan alert

Alert minimum:

```text
Scheduler heartbeat hilang > 2 menit
Horizon tidak running
Redis tidak dapat dihubungi
Oldest critical Run > SLO
Oldest regular Run > SLO
Run running melewati execution deadline
Failed rate meningkat di atas baseline
Redis memory mendekati limit
MySQL connection mendekati limit
Disk mendekati penuh
Run purge gagal
```

Dashboard minimum:

- due, queued, running, succeeded, failed, skipped per periode;
- dispatch lag, queue wait, start lag, dan execution duration p50/p95/p99;
- oldest queued Run per queue;
- worker aktif dan worker utilization;
- HTTP success rate per Task dan Client;
- timeout rate;
- MySQL, Redis, CPU, memory, disk, socket, dan network.

### 8.6 Idempotency review

Setiap Task Template harus diklasifikasikan:

| Kategori | Contoh kebijakan |
| --- | --- |
| Read-only/idempotent | Aman Retry sesuai policy |
| Idempotent dengan key | Kirim Run UUID sebagai idempotency key |
| Non-idempotent side effect | `tries=1`, manual review sebelum Retry |
| Tidak diketahui | Anggap non-idempotent sampai dikonfirmasi |

### 8.7 Data readiness

Sebelum Schedule diaktifkan:

- Client mempunyai base URL dan credential valid;
- Task Template canonical tersedia;
- placeholder dapat di-resolve;
- credential drift telah diputuskan;
- tidak ada URL malformed atau dangling;
- timeout dan overlap policy telah ditentukan;
- queue class telah ditentukan;
- Run Now harmless telah berhasil.

### 8.8 Staged cutover

Jangan menonaktifkan seluruh legacy dan mengaktifkan semua Schedule sekaligus.

```text
1. Pilih satu Task yang harmless pada beberapa Client.
2. Catat baseline hasil legacy.
3. Disable legacy cron untuk pasangan Client + Task tersebut.
4. Enable Schedule baru.
5. Jalankan minimal 2–3 siklus.
6. Bandingkan occurrence, timing, HTTP result, dan side effect.
7. Tambah Client bertahap.
8. Pindahkan Task critical setelah jalur reguler stabil.
```

Legacy dan aplikasi baru tidak boleh aktif bersamaan untuk pasangan Schedule
yang sama kecuali sedang menjalankan shadow test yang tidak menghasilkan side
effect.

### 8.9 Rollback

Rollback minimum:

```text
1. Pause Schedule baru untuk scope yang bermasalah.
2. Biarkan Run running selesai atau timeout.
3. Putuskan nasib Run queued: drain, cancel, atau simpan untuk review.
4. Aktifkan kembali legacy cron hanya setelah queue baru aman.
5. Jangan menjalankan legacy dan new execution secara bersamaan.
6. Catat waktu dan scope rollback pada audit/change record.
```

## 9. Go/No-Go checklist

Aplikasi boleh digunakan untuk production apabila seluruh item critical berikut
terpenuhi:

- [ ] Seluruh Schedule aktif mempunyai Client, Template, URL, dan credential valid.
- [ ] SLA/SLO critical dan regular telah disepakati.
- [ ] Queue dipisahkan berdasarkan karakter workload atau keputusan mempertahankan satu queue telah dibuktikan dengan load test.
- [ ] Worker sizing telah lolos test 123 current peak dan 246 projected peak.
- [ ] p95 start lag dan queue wait memenuhi SLO.
- [ ] Tidak ada duplicate materialization.
- [ ] Endpoint side-effect mendukung idempotency atau Retry dinonaktifkan dan dikendalikan manual.
- [ ] Redis menggunakan persistence yang disepakati dan `maxmemory-policy=noeviction`.
- [ ] MySQL, Redis, worker, queue wait, disk, dan scheduler mempunyai alert.
- [ ] Horizon dijaga Supervisor dan graceful deployment telah diuji.
- [ ] Failure drill telah lolos dan mempunyai bukti.
- [ ] Retention dihitung untuk sekitar 1,23 juta Run per bulan pada workload penuh.
- [ ] Backup dan restore procedure telah diuji.
- [ ] Cutover dilakukan bertahap tanpa dual execution.
- [ ] Rollback procedure telah diuji pada scope pilot.
- [ ] Credential drift dan unresolved legacy findings telah ditutup.

## 10. Kesimpulan untuk disampaikan kepada leader

> Linux cron lama mampu membuat banyak process pada menit yang sama, tetapi
> tidak memberikan global concurrency control, central trace, audit, recovery,
> atau bukti kapan endpoint benar-benar dieksekusi. Jika endpoint menggantung,
> invocation baru dapat terus bertambah karena mayoritas script tidak mempunyai
> `flock` dan timeout yang konsisten.
>
> Queue + Redis tidak dipilih sekadar untuk menjalankan background job. Queue
> dipakai sebagai buffer dan batas pengaman antara Schedule yang due dengan
> kapasitas server dan endpoint. Horizon menjalankan worker secara concurrent
> dan terukur, sedangkan MySQL menyimpan status bisnis dan histori yang
> authoritative.
>
> Sistem baru mungkin membuat sebagian job menunggu ketika worker penuh. Namun
> waktu tunggu tersebut terlihat, dapat diukur, dapat diberi SLA, dan kapasitasnya
> dapat ditambah. Pada existing, keterlambatan, hang, overlap, dan kegagalan
> sebagian besar tidak terlihat secara terpusat.
>
> Opsifin Scheduler dapat digunakan dan diandalkan apabila SLA ditetapkan,
> workload dipisahkan, worker sizing dibuktikan melalui load test, endpoint
> side-effect menggunakan idempotency, monitoring dan alert tersedia, failure
> drill berhasil, retention disiapkan, serta cutover dilakukan bertahap.
> Redis/Horizon adalah fondasi yang tepat untuk workload ini, tetapi reliability
> harus dibuktikan dengan acceptance criteria, bukan diasumsikan.

## 11. Decision record

Bagian ini diisi setelah review bersama leader:

```text
Tanggal keputusan:
Pemilik keputusan:

SLO critical start lag:
SLO regular queue wait:
Current peak due jobs:
Projected peak due jobs:
HTTP duration p50/p95/p99:

Queue topology:
Worker topology:
Worker count per queue:
RAM/CPU hasil load test:
Database connection hasil load test:

Idempotency policy:
Retention policy:
RTO:
RPO:

Risiko yang diterima:
Mitigasi:
Rollout scope:
Rollback owner:
Keputusan Go/No-Go:
```
