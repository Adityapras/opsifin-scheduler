# Komparasi Direct Concurrent HTTP dan Redis Queue + Horizon

> Arsip analisis sebelum implementasi. Sejak 10 September 2026, compatibility
> direct HTTP sudah tersedia dengan dispatcher dan executor terpisah. Status
> terbaru ada di [rencana migrasi](direct-bounded-http-migration-plan.md) dan
> [hasil validasi](direct-http-validation.md). Perbandingan di bawah tetap
> merekam alternatif awal, bukan instruksi runtime saat ini.

## 1. Tujuan dokumen

Dokumen ini membandingkan dua pendekatan eksekusi HTTP job pada Opsifin
Scheduler:

1. **Direct Concurrent HTTP** — dispatcher mengambil seluruh Schedule yang due,
   membuat Run, lalu mengirim HTTP request secara concurrent dalam proses batch
   yang sama tanpa message queue.
2. **Redis Queue + Laravel Horizon** — dispatcher membuat Run dan memasukkan
   payload `ExecuteRun` ke Redis, kemudian sekumpulan worker Horizon menjalankan
   HTTP request secara paralel.

Fokus utama komparasi adalah kebutuhan satu service yang dijadwalkan pada menit
yang sama untuk banyak Client. Dokumen ini membahas ketepatan waktu mulai,
throughput, penggunaan resource, reliability, recovery, observability, dan
kompleksitas operasional.

Dokumen ini adalah bahan pengambilan keputusan arsitektur. Implementasi aktif
saat ini tetap Redis Queue + Horizon sampai ada keputusan dan migrasi yang
disetujui.

## 2. Ringkasan eksekutif

Kedua pendekatan dapat menjalankan request secara paralel. Perbedaannya bukan
"paralel atau tidak", tetapi **siapa yang mengatur paralelisme dan di mana
pekerjaan menunggu**.

| Pertanyaan | Direct Concurrent HTTP | Redis Queue + Horizon |
| --- | --- | --- |
| Siapa yang mengeksekusi? | Satu proses dispatcher/batch executor | Banyak proses worker Horizon |
| Di mana job menunggu? | Di memori batch atau belum dimulai dalam pool | Sebagai payload di Redis |
| Batas paralelisme | Ukuran concurrency pool | Jumlah worker aktif |
| Jika kapasitas penuh | Request berikutnya menunggu slot pool | Job berikutnya tetap `queued` di Redis |
| Start hampir bersamaan | Baik jika pool langsung dibuka sebesar jumlah Client | Baik jika worker sudah tersedia sebanyak job due |
| Isolasi kegagalan | Kegagalan proses dapat memengaruhi satu batch | Kegagalan worker umumnya memengaruhi satu job |
| Recovery setelah restart | Harus dibuat di aplikasi | Payload pending dapat dilanjutkan dari Redis |
| Backpressure | Harus dibuat melalui batas pool/chunk | Alami melalui antrean |
| Infrastruktur | Lebih sederhana; Redis/Horizon dapat dihapus | Memerlukan Redis, Horizon, dan Supervisor |
| Observability | Harus dibuat dari Run/log aplikasi | Run/log aplikasi ditambah dashboard dan metrics Horizon |

Kesimpulan awal:

- Direct Concurrent HTTP menarik jika seluruh pekerjaan hanya HTTP, jumlah
  Client kecil dan terprediksi, respons relatif cepat, serta kebutuhan utamanya
  adalah fan-out dengan selisih start yang kecil.
- Redis Queue + Horizon lebih kuat jika durasi request tidak stabil, burst dapat
  membesar, job harus tetap tercatat saat worker berhenti, dan failure isolation
  serta kontrol operasional lebih penting.
- Konfigurasi queue saat ini, minimal 2 dan maksimal 10 worker dengan
  autoscaling, **belum menjamin** semua Client langsung mulai bersamaan apabila
  jumlah Schedule due lebih dari worker yang sudah aktif.

## 3. Batasan dan istilah

### 3.1 Schedule yang diproses

Kedua pendekatan tidak seharusnya mengambil semua row `schedules` setiap menit.
Dispatcher hanya mengambil Schedule yang memenuhi kondisi berikut:

```text
is_enabled = true
next_run_at <= waktu dispatcher
Client aktif
Task Template aktif
```

Query harus tetap menggunakan index pada `is_enabled` dan `next_run_at` agar
biaya scanning tidak bertambah linear terhadap seluruh histori atau seluruh
Schedule nonaktif.

### 3.2 Arti "bersamaan"

Dokumen ini membedakan tiga tingkat kebutuhan:

| Tingkat | Definisi praktis |
| --- | --- |
| Sama dalam satu menit | Semua request mulai sebelum pergantian menit berikutnya |
| Near-simultaneous | Selisih start antarklien beberapa detik |
| Strict simultaneous | Selisih start sangat kecil, misalnya di bawah satu detik |

Linux cron, direct HTTP pool, dan queue worker sama-sama tidak memberikan
jaminan hard real-time. Scheduling process oleh OS, waktu query database, DNS,
TCP/TLS handshake, network latency, dan kondisi endpoint dapat membuat waktu
aktual berbeda. Untuk kebutuhan bisnis, SLA sebaiknya dinyatakan sebagai batas
selisih `started_at - scheduled_for`, bukan hanya memakai frasa "jalan
bersamaan".

### 3.3 Concurrency dan parallelism

- **Concurrency** adalah jumlah request yang dapat berada dalam status in-flight
  pada waktu yang sama.
- **Parallelism** adalah pekerjaan yang benar-benar diproses bersamaan oleh CPU
  atau proses berbeda.

Karena job Opsifin sebagian besar menunggu I/O HTTP, asynchronous concurrency
dalam satu proses dapat memberikan throughput tinggi tanpa membutuhkan satu
PHP process per request. Queue Horizon menggunakan beberapa PHP process;
masing-masing worker menangani satu queue job pada satu waktu.

## 4. Alur Direct Concurrent HTTP

### 4.1 Diagram

```text
Linux cron setiap menit
        |
        v
Laravel Scheduler
        |
        v
Due Schedule Dispatcher
        |
        +-- query seluruh Schedule due
        +-- lock dan advance next_run_at
        +-- buat Run untuk setiap Schedule
        |
        v
Bounded HTTP concurrency pool
        |
        +-- request Client A --+
        +-- request Client B --+--> tunggu/catat masing-masing hasil
        +-- request Client C --+
        +-- ...                |
        |
        v
Update setiap Run menjadi succeeded / failed / skipped
```

### 4.2 Karakteristik

Dispatcher sekaligus menjadi batch executor. Semua request due dikumpulkan,
tetapi hanya sebanyak batas concurrency yang dikirim pada waktu yang sama.
Misalnya batas pool 20 berarti maksimal 20 koneksi HTTP in-flight; request ke-21
menunggu salah satu slot selesai.

Concurrency harus **bounded**. Membuka seluruh request tanpa batas dapat
menghabiskan socket, file descriptor, memory, koneksi database, bandwidth, atau
kapasitas endpoint tujuan.

### 4.3 State minimum yang tetap dibutuhkan

Walaupun queue dihapus, model Run dan state machine tetap diperlukan:

```text
pending/claimed -> running -> succeeded
                          +-> failed
                          +-> skipped
```

Sebelum mengirim request, sistem minimal perlu mencatat:

- Schedule dan Client yang akan dieksekusi;
- `scheduled_for` dan `started_at`;
- status claim eksekusi;
- execution deadline;
- hasil HTTP, durasi, dan error;
- idempotency/materialization key;
- overlap slot jika `prevent_overlap=true`.

Tanpa pencatatan ini, proses yang mati di tengah batch tidak dapat menentukan
request mana yang belum dimulai, sedang in-flight, atau telah selesai.

## 5. Alur Redis Queue + Horizon saat ini

### 5.1 Diagram

```text
Linux cron setiap menit
        |
        v
Laravel Scheduler
        |
        v
DueScheduleDispatcher
        |
        +-- query Schedule due
        +-- transaction per Schedule
        +-- buat Run(status=queued)
        +-- commit
        +-- push ExecuteRun(run_id)
        |
        v
Redis queue `default`
        |
        v
Horizon supervisor
        |
        +-- worker 1 --> RunWorker --> HTTP Client
        +-- worker 2 --> RunWorker --> HTTP Client
        +-- ...
        +-- worker N --> RunWorker --> HTTP Client
```

### 5.2 Konfigurasi aktif

Konfigurasi production saat ini menggunakan:

| Parameter | Nilai |
| --- | ---: |
| Queue connection | Redis |
| Queue aktif | `default` |
| Minimum worker | 2 |
| Maksimum worker | 10 |
| Balancing | `auto`, strategy `time` |
| Kenaikan maksimum tiap rebalance | 1 process |
| Cooldown rebalance | 3 detik |
| Attempts otomatis | 1 |
| Worker timeout | 1900 detik secara default |

Implikasinya, ketika queue sebelumnya kosong, Horizon dapat berada pada minimum
2 worker. Jika mendadak ada 20 job due, dua job pertama dapat mulai segera,
sedangkan penambahan worker berlangsung bertahap sampai maksimum 10. Job yang
belum mendapat worker tetap menunggu di Redis.

`tries=1` berarti arsitektur aktif tidak otomatis mengulang HTTP request yang
gagal. Retry bisnis tetap dilakukan manual dengan membuat Run baru. Queue di
sini terutama memberikan buffering, worker management, failure isolation, dan
observability; bukan automatic business retry.

### 5.3 Transaction boundary

Run disimpan ke MySQL sebelum payload dimasukkan ke Redis:

```text
BEGIN
  lock Schedule
  advance next_run_at
  insert Run(status=queued, queue_job_id=null)
COMMIT

push ExecuteRun(run_id) ke Redis
update queue_job_id
```

Jika aplikasi mati setelah commit tetapi sebelum push, reconciler mencari Run
`queued` yang belum mempunyai `queue_job_id`, lalu memublikasikannya kembali.

## 6. Model kapasitas dan waktu eksekusi

Gunakan variabel berikut:

```text
N = jumlah job yang due bersamaan
T = rata-rata durasi satu HTTP request
C = batas concurrency atau jumlah worker aktif
```

Estimasi sederhana durasi batch:

```text
durasi batch ~= ceil(N / C) x T + overhead
```

Rumus ini hanya pendekatan. Tail latency, timeout, autoscaling delay, DNS, dan
perbedaan endpoint dapat membuat hasil aktual lebih lama.

### 6.1 Contoh 20 Client, masing-masing 30 detik

| Model | Kapasitas awal/maksimal | Estimasi ideal | Catatan |
| --- | ---: | ---: | --- |
| Direct sequential | 1 | 10 menit | Client terakhir menunggu seluruh request sebelumnya |
| Direct concurrent pool | 5 | 2 menit | Empat gelombang request |
| Direct concurrent pool | 20 | 30 detik | Semua koneksi dibuka dalam satu batch |
| Horizon fixed workers | 10 | 1 menit | Dua gelombang queue job |
| Horizon autoscale saat ini | 2 sampai 10 | Lebih dari 1 menit | Ada waktu ramp-up dari minimum worker |

### 6.2 Contoh 100 Client, masing-masing 2 menit

| Model | Concurrency | Estimasi ideal |
| --- | ---: | ---: |
| Direct concurrent pool | 20 | 10 menit |
| Queue workers | 10 | 20 menit |
| Queue workers | 50 | 4 menit |
| Unbounded direct concurrent | 100 | Sekitar 2 menit, tetapi berisiko tinggi |

Contoh tersebut menunjukkan bahwa queue tidak otomatis lebih lambat atau lebih
cepat. Faktor penentunya adalah nilai `C`. Queue membuat pekerjaan yang melebihi
`C` menunggu secara durable; direct pool membuatnya menunggu di dalam proses
batch.

## 7. Komparasi menyeluruh

| Aspek | Direct Concurrent HTTP | Redis Queue + Horizon |
| --- | --- | --- |
| Ketepatan waktu mulai | Dapat membuka banyak request dalam jarak sangat dekat jika pool cukup besar | Bergantung pada worker yang sudah aktif; autoscaling menambah delay saat burst |
| Batas concurrency | Ditetapkan pada pool/chunk | Ditetapkan oleh jumlah worker Horizon |
| Urutan mulai | Dibentuk oleh loop/pool dispatcher | Umumnya mengikuti enqueue, tetapi multiple worker tidak menjamin urutan selesai |
| Backpressure | Harus dibuat dengan bounded pool dan chunk | Queue secara alami menahan backlog |
| Burst besar | Membebani satu batch process; perlu batas ketat | Diserap Redis lalu dikuras sesuai kapasitas worker |
| Request lama | Menahan batch sampai request selesai/timeout | Menahan satu worker; worker lain tetap independen |
| Failure isolation | Process crash dapat memengaruhi banyak in-flight request | Worker crash umumnya memengaruhi satu Run |
| Durability saat executor mati | Daftar in-memory hilang; recovery harus membaca state DB | Payload pending tetap di Redis jika persistence dikonfigurasi benar |
| Recovery setelah crash | Harus membedakan belum dikirim, in-flight, dan selesai | Reconciler, retry-after, deadline recovery, dan Run state menangani beberapa failure mode |
| Risiko duplicate HTTP | Ada saat crash setelah request terkirim tetapi sebelum hasil tersimpan | Tetap ada pada failure window yang sama; atomic claim mencegah duplicate worker claim, bukan menjamin endpoint exactly-once |
| Exactly-once | Tidak tersedia tanpa idempotency pada endpoint | Tidak tersedia tanpa idempotency pada endpoint |
| Retry | Harus dibuat eksplisit | Infrastruktur mendukung retry, tetapi konfigurasi bisnis saat ini `tries=1` dan retry manual |
| Cancel sebelum mulai | Sulit jika request sudah masuk pool; perlu state check sebelum send | Payload queued dapat dibatalkan dan worker melakukan state check |
| Pause setelah dispatch | Harus dicek tepat sebelum send | Worker memeriksa ulang state sebelum HTTP call |
| Prevent overlap | Harus memakai atomic DB slot per Schedule | Sudah memakai atomic DB slot per Schedule |
| Resource PHP | Biasanya satu/few process dengan banyak socket; berpotensi lebih hemat RAM | Beberapa PHP worker; penggunaan RAM dan DB connection bertambah per worker |
| CPU | Rendah ketika menunggu I/O, tetapi event/promise handling berada pada satu process | Tersebar ke beberapa process |
| Koneksi database | Umumnya lebih sedikit, tetapi batch update harus dijaga | Setiap worker dapat memegang koneksi sendiri |
| Koneksi HTTP | Sama-sama dapat mencapai `C` | Sama-sama dapat mencapai jumlah worker aktif |
| Redis | Tidak diperlukan untuk eksekusi | Diperlukan sebagai broker dan metadata Horizon |
| Latency broker | Tidak ada push/pop Redis | Ada overhead push/pop, biasanya kecil dibanding durasi HTTP |
| Horizontal scaling | Perlu sharding/leader election sendiri agar batch tidak ganda | Worker dapat ditambah pada host lain dengan Redis bersama |
| Deployment | Satu batch yang panjang perlu dihentikan secara graceful | Horizon mendukung graceful termination dan worker replacement |
| Monitoring | Harus mengandalkan Run, log, dan metrics buatan sendiri | Run tetap authoritative; Horizon menambah pending/completed/failed dan throughput metrics |
| Debugging | Satu batch log dapat bercampur untuk banyak Client | Setiap queue job mempunyai lifecycle dan tag sendiri |
| Operasional | Lebih sedikit service | Lebih banyak komponen: Redis, Horizon, Supervisor |
| Kompleksitas kode | Pool, per-request callback, recovery, dan partial failure harus dirancang | Dispatch dan worker lebih terpisah; membutuhkan integrasi queue/reconciler |
| Single point of failure | Dispatcher batch | Redis/Horizon dan database; worker individual lebih terisolasi |
| Cocok untuk | Fan-out HTTP cepat dan terprediksi | Workload variatif, burst, request lama, dan kebutuhan operasional kuat |

## 8. Direct Concurrent HTTP: kelebihan dan kekurangan

### 8.1 Kelebihan

1. **Fan-out lebih rapat**

   Pool dapat membuka koneksi untuk seluruh Client dalam satu siklus tanpa
   menunggu Horizon menaikkan jumlah worker.

2. **Penggunaan process dan memory dapat lebih rendah**

   Satu proses PHP dapat mengelola banyak koneksi asynchronous. Ini efisien
   untuk pekerjaan I/O-bound bila response body kecil dan callback tidak berat.

3. **Infrastruktur lebih sederhana**

   Redis, Horizon, Horizon dashboard, snapshot metrics, dan Supervisor khusus
   Horizon dapat dihilangkan jika tidak digunakan oleh subsistem lain.

4. **Tidak ada broker latency**

   Request dapat dikirim segera setelah transaksi Run selesai. Penghematan ini
   biasanya hanya milidetik dan bukan faktor dominan untuk request berdurasi
   detik atau menit.

5. **Kapasitas fan-out eksplisit dalam satu tempat**

   Batas concurrency pool dapat disesuaikan langsung dengan kapasitas gateway
   dan endpoint Client.

### 8.2 Kekurangan

1. **Blast radius process lebih besar**

   Fatal error, out-of-memory, kill, atau restart dapat memutus pengelolaan
   seluruh batch yang sedang berjalan.

2. **Recovery partial batch lebih sulit**

   Sistem harus membedakan request yang belum dikirim, sedang in-flight, sudah
   diterima endpoint tetapi responsnya belum tercatat, dan benar-benar selesai.

3. **Backpressure harus dibuat sendiri**

   Tanpa batas concurrency, satu menit dengan banyak Schedule due dapat membuka
   terlalu banyak socket dan membanjiri endpoint.

4. **Dispatcher dapat berjalan lama**

   Durasi command mengikuti request paling lambat pada setiap gelombang.
   `withoutOverlapping` dan lock expiry harus disesuaikan agar invocation menit
   berikutnya tidak memulai batch yang sama secara bersamaan.

5. **Timeout proses menjadi kritis**

   PHP CLI, process manager, cron wrapper, dan deployment harus membiarkan batch
   hidup sampai timeout request terpanjang. Timeout satu request tidak boleh
   membatalkan seluruh pool.

6. **Observability tambahan harus dibangun**

   Queue wait memang hilang, tetapi metrics active pool, pending-in-batch,
   batch duration, per-request result, saturation, dan recovery tetap perlu.

7. **Manual Run dan Retry tidak boleh dieksekusi di web request**

   Jika tombol UI langsung menjalankan HTTP secara synchronous, request browser
   dan PHP-FPM dapat timeout. Tetap diperlukan background command atau mekanisme
   eksekusi lain, yang berpotensi menciptakan queue versi baru.

## 9. Redis Queue + Horizon: kelebihan dan kekurangan

### 9.1 Kelebihan

1. **Buffer durable dan backpressure**

   Ketika jumlah job due melebihi worker, payload tetap menunggu di Redis dan
   tidak memaksa server membuka seluruh koneksi sekaligus. Durability bergantung
   pada konfigurasi persistence Redis, kapasitas disk, dan kebijakan eviction.

2. **Failure isolation per job**

   Satu worker menangani satu Run. Worker yang crash tidak langsung menghentikan
   worker lain.

3. **Concurrency mudah dikontrol**

   Minimum dan maksimum process menjadi guard terhadap konsumsi RAM, DB
   connection, bandwidth, serta rate limit endpoint.

4. **Scaling lebih fleksibel**

   Worker dapat ditambah, dipisah per queue, atau ditempatkan pada host lain
   tanpa mengubah dispatcher.

5. **Deployment lebih aman**

   Horizon dapat dihentikan secara graceful. Payload yang belum diambil tetap
   menunggu selama Redis tersedia.

6. **Observability siap pakai**

   Horizon memberikan queue wait, throughput, recent jobs, failed jobs, dan
   worker status. Histori bisnis tetap berada pada tabel `runs`.

7. **Separation of concerns**

   Dispatcher fokus pada perhitungan Schedule dan materialisasi Run; worker
   fokus pada eksekusi HTTP.

### 9.2 Kekurangan

1. **Job dapat menunggu**

   Jika jumlah due job lebih besar daripada worker aktif, sebagian Run tidak
   langsung mulai. Ini terlihat sebagai selisih antara `queued_at` dan
   `started_at`.

2. **Autoscaling tidak instan**

   Konfigurasi minimum 2 worker dengan kenaikan satu process setiap cooldown
   dapat terlambat merespons fan-out yang hanya muncul pada batas menit.

3. **Penggunaan RAM lebih besar**

   Setiap PHP worker mempunyai memory dan koneksi sendiri. Menetapkan puluhan
   worker hanya demi start bersamaan dapat tidak ekonomis pada VPS kecil.

4. **Infrastruktur dan operasi lebih kompleks**

   Redis, Horizon, Supervisor, persistence, memory policy, metrics, log, dan
   prosedur recovery harus dirawat.

5. **Ada dual-write boundary**

   MySQL dan Redis tidak berada dalam satu distributed transaction. Reconciler
   diperlukan untuk menutup celah setelah MySQL commit tetapi sebelum Redis push.

6. **Horizon status tidak sama dengan hasil bisnis**

   Queue job dapat dianggap completed ketika handler berhasil menyimpan HTTP
   failure sebagai status Run. Operator tetap harus melihat Execution Logs.

7. **Lebih banyak worker tidak selalu lebih baik**

   Menambah worker dapat memindahkan bottleneck ke MySQL, network, gateway, atau
   endpoint Client dan meningkatkan risiko rate limit atau lock contention.

## 10. Kebutuhan fan-out pada menit yang sama

### 10.1 Perilaku konfigurasi saat ini

Misalnya service yang sama due pada 30 Client pukul 10:00:

```text
10:00 dispatcher enqueue 30 Run
      |
      +-- worker aktif mengambil sebagian Run
      +-- Horizon bertahap menambah worker hingga maksimum 10
      +-- sisa Run menunggu job sebelumnya selesai
```

Semua Run mempunyai `scheduled_for=10:00`, tetapi tidak seluruhnya mempunyai
`started_at` pada detik yang sama. Jika request berlangsung lama, sebagian Run
dapat mulai beberapa menit kemudian.

### 10.2 Jika tetap memakai queue

Untuk SLA start yang lebih rapat, pilih salah satu:

1. naikkan minimum worker agar process sudah warm sebelum burst;
2. gunakan fixed worker pool untuk menghindari ramp-up;
3. buat queue khusus, misalnya `time-critical`, dengan supervisor sendiri;
4. pisahkan queue reguler agar job biasa tidak berada di depan fan-out penting;
5. tentukan jumlah worker dari peak simultaneous schedules, RAM VPS, DB
   connection, dan kapasitas endpoint;
6. monitor p95/p99 queue wait, bukan hanya total job per hari.

Contoh konseptual:

```text
time-critical queue -> fixed 20 workers -> fan-out dengan SLA start ketat
default queue       -> auto 2..10       -> pekerjaan reguler
```

Konfigurasi aktif saat ini hanya mendengarkan queue `default`. Queue khusus
memerlukan penambahan supervisor pada konfigurasi Horizon.

### 10.3 Jika memakai direct concurrent

Untuk mencegah resource spike:

1. tentukan hard concurrency limit;
2. materialize seluruh Run sebelum eksekusi;
3. atomic claim setiap Run sebelum mengirim HTTP;
4. periksa ulang status Client, Template, Schedule, dan overlap tepat sebelum
   request;
5. gunakan callback hasil per request agar satu failure tidak membatalkan pool;
6. simpan hasil segera ketika setiap request selesai, bukan setelah seluruh batch;
7. tandai Run in-flight yang melewati deadline sebagai failed/unknown;
8. sediakan recovery untuk Run yang diclaim tetapi process mati;
9. gunakan idempotency key pada endpoint jika duplicate request tidak dapat
   diterima;
10. ukur p95/p99 start lag dan batch completion time.

## 11. Failure scenario

| Scenario | Direct Concurrent HTTP | Redis Queue + Horizon |
| --- | --- | --- |
| Dispatcher mati sebelum membuat Run | Invocation berikutnya dapat materialize occurrence terbaru | Sama |
| Mati setelah Run dibuat sebelum HTTP send | Recovery harus mengambil Run yang belum dikirim | Reconciler mem-publish Run tanpa queue ID |
| Mati ketika banyak request in-flight | Status aktual ambigu untuk banyak Run | Biasanya ambigu untuk Run pada worker yang mati saja |
| HTTP diterima, process mati sebelum simpan hasil | Retry berisiko duplicate | Retry juga berisiko duplicate |
| Endpoint sangat lambat | Menahan slot pool dan umur batch | Menahan satu worker |
| Redis mati | Tidak relevan untuk executor | Dispatch baru gagal; Run tanpa payload dipulihkan setelah Redis hidup |
| Horizon mati | Tidak relevan | Payload menunggu di Redis |
| Database mati | Hasil tidak dapat dicatat; batch perlu berhenti/recovery | Worker gagal mengklaim/mencatat Run; queue infrastructure menangani sesuai konfigurasi |
| Server restart | Seluruh batch process berhenti | Worker berhenti; pending payload dapat dilanjutkan setelah service hidup |
| Burst melebihi kapasitas | Menumpuk di memory/pool internal atau harus ditolak/chunk | Menumpuk sebagai queue backlog |

### 11.1 Exactly-once tidak dijamin oleh keduanya

Kedua desain memiliki failure window berikut:

```text
request sudah diterima endpoint
        |
        v
process/worker mati sebelum menyimpan hasil
```

Scheduler tidak dapat memastikan apakah request aman dikirim ulang. Untuk job
yang mempunyai side effect, endpoint sebaiknya menerima idempotency key seperti
Run UUID atau kombinasi Task, Client, dan `scheduled_for`, lalu menolak proses
duplikat pada sisi Client.

## 12. Efisiensi resource

### 12.1 Direct Concurrent HTTP

Potensi efisiensi:

- lebih sedikit PHP process;
- lebih sedikit idle worker;
- tidak ada Redis queue operation;
- lebih sedikit DB connection simultan;
- cocok untuk banyak request I/O-bound dengan response kecil.

Potensi inefisiensi:

- seluruh daftar dan state pool berada di memory satu process;
- response besar dapat menaikkan memory secara tajam;
- satu slow tail membuat batch tetap hidup;
- polling/recovery/metrics yang dibuat sendiri menambah query database;
- unbounded pool dapat menyebabkan connection storm.

### 12.2 Redis Queue + Horizon

Potensi efisiensi:

- worker hanya mengambil pekerjaan sesuai kapasitas;
- backlog tidak memenuhi memory dispatcher;
- setiap proses sederhana dan failure terisolasi;
- mudah membagi kapasitas berdasarkan queue atau host.

Potensi inefisiensi:

- beberapa PHP process tetap hidup saat idle sesuai minimum worker;
- setiap worker membawa runtime Laravel dan koneksi sendiri;
- Redis menyimpan payload dan metadata;
- autoscaling menambah kompleksitas dan dapat terlambat untuk burst singkat.

### 12.3 Faktor yang lebih penting daripada total harian

Angka total, misalnya 500 job per hari, tidak cukup untuk sizing. Faktor utama:

- peak Schedule yang due pada menit/detik yang sama;
- rata-rata, p95, dan p99 durasi HTTP;
- connect dan request timeout;
- ukuran response;
- RAM aktual per PHP process;
- kapasitas DB connection;
- bandwidth dan socket/file descriptor limit;
- rate limit dan locking pada endpoint Client;
- SLA queue wait atau start lag;
- frekuensi failure dan kebutuhan recovery.

## 13. Dampak jika queue dihapus

### 13.1 Komponen yang dapat dihapus

Jika tidak dipakai oleh subsistem lain, komponen berikut berpotensi dihapus:

- Redis queue connection dan payload DB;
- Laravel Horizon;
- Horizon dashboard dan snapshot schedule;
- Supervisor process untuk Horizon;
- enqueue dan cancel-payload logic;
- queue-specific failed jobs dan metrics;
- reconciler khusus celah MySQL-to-Redis publish.

Redis belum tentu dapat dihapus sepenuhnya jika masih dipakai untuk cache,
session, lock, rate limiter, atau subsistem lain.

### 13.2 Kemampuan yang harus dipertahankan atau dibangun ulang

- idempotent materialization per occurrence;
- atomic Run claim;
- status `running`, `succeeded`, `failed`, dan `skipped`;
- `prevent_overlap` per Schedule;
- timeout dan execution deadline recovery;
- bounded concurrency;
- per-request exception isolation;
- start lag dan execution metrics;
- safe manual retry;
- execution path untuk Run Now dari UI;
- graceful shutdown dan deploy behavior;
- protection terhadap duplicate HTTP side effect.

Dengan demikian, menghapus queue mengurangi kompleksitas infrastruktur, tetapi
tidak menghapus kompleksitas domain dan reliability. Sebagian kompleksitas
berpindah ke batch executor dan database state machine.

## 14. Opsi desain

### Opsi A — Pertahankan queue saat ini

Cocok bila SLA hanya mengharuskan job diproses dalam window yang longgar dan
queue wait saat burst masih dapat diterima.

Perbaikan minimum:

- ukur peak due jobs dan queue wait;
- sesuaikan `HORIZON_MIN_PROCESSES` dan `HORIZON_MAX_PROCESSES`;
- buat alert bila oldest queued Run melewati SLA.

### Opsi B — Queue khusus untuk fan-out time-critical

Cocok bila sebagian service harus mulai lebih rapat, sedangkan job lain boleh
menunggu.

```text
time-critical -> fixed/warm workers
default       -> autoscaling workers
```

Kelebihan:

- mempertahankan durability dan isolation queue;
- job reguler tidak menghalangi job time-critical;
- kapasitas dapat ditentukan per kelas workload.

Kekurangan:

- membutuhkan lebih banyak worker standby;
- RAM dan konfigurasi operasi bertambah;
- tetap tidak menjamin hard real-time.

### Opsi C — Direct bounded concurrent untuk semua HTTP job

Cocok bila workload sederhana, seluruh job hanya HTTP, peak dapat diprediksi,
dan penghematan process/infrastruktur lebih bernilai daripada isolation queue.

Syarat minimum:

- concurrency tidak unbounded;
- Run state dan recovery dirancang sebelum cutover;
- Run Now tidak menggantungkan PHP-FPM/web request;
- ada load test dan crash-recovery test;
- endpoint side-effect mempunyai idempotency protection.

### Opsi D — Hybrid

Dispatcher memakai direct concurrent untuk fan-out tertentu dan queue untuk job
reguler atau berdurasi panjang.

Kelebihan:

- fan-out kritis mendapat start yang rapat;
- job panjang tetap memperoleh isolation dan durability queue.

Kekurangan:

- dua execution path harus dipelihara dan diuji;
- status, retry, dan observability mudah berbeda;
- debugging dan operasi paling kompleks.

Hybrid hanya layak jika perbedaan SLA benar-benar besar dan terukur.

## 15. Panduan keputusan berdasarkan SLA

| Requirement | Pilihan yang paling masuk akal |
| --- | --- |
| Seluruh Client mulai dalam menit yang sama | Queue dengan worker cukup atau direct bounded pool |
| Selisih start maksimal 5–10 detik | Queue khusus dengan fixed warm workers, atau direct bounded pool |
| Selisih start di bawah 1 detik | Keduanya tidak memberi hard guarantee; perlu desain time-trigger di sisi Client atau sistem terdistribusi khusus |
| Request dapat berjalan puluhan menit | Queue lebih aman untuk isolation dan recovery |
| Jumlah Client kecil, respons cepat, dan stabil | Direct concurrent layak dan dapat lebih hemat resource |
| Burst tidak terprediksi | Queue lebih aman karena mempunyai buffer |
| VPS RAM sangat terbatas | Direct pool berpotensi lebih hemat, tetapi wajib dibatasi dan diuji |
| Operasional harus sederhana | Direct pool mengurangi service, tetapi recovery code bertambah |
| Harus mudah scale lintas server | Queue lebih sesuai |

## 16. Rekomendasi untuk Opsifin Scheduler

Sebelum mengganti arsitektur, ukur data production atau QA berikut selama periode
representatif:

1. jumlah maksimum Schedule due pada menit yang sama;
2. distribusi durasi HTTP p50, p95, dan p99;
3. p95/p99 `started_at - scheduled_for`;
4. maksimum Run queued bersamaan;
5. RAM aktual setiap Horizon worker;
6. jumlah DB connection dan socket saat peak;
7. endpoint yang mempunyai rate limit atau global lock;
8. jumlah job yang aman dieksekusi ulang.

Rekomendasi sementara:

- Jangan mengganti queue dengan eksekusi sequential.
- Jika masalah utamanya adalah semua Client harus mulai pada window yang rapat,
  validasi terlebih dahulu queue dengan **warm/fixed worker pool** yang sesuai
  peak fan-out. Perubahan ini lebih kecil dan mempertahankan reliability yang
  sudah ada.
- Pertimbangkan Direct Concurrent HTTP jika hasil pengukuran menunjukkan job
  seluruhnya I/O-bound, cepat, jumlah Client terkontrol, dan biaya worker standby
  terlalu tinggi.
- Untuk kedua pendekatan, tambahkan metric `start_lag_ms = started_at -
  scheduled_for` dan tetapkan SLA yang eksplisit.
- Untuk job dengan side effect, tambahkan idempotency key pada endpoint Client.

## 17. Acceptance test sebelum keputusan/cutover

### 17.1 Functional

- seluruh Schedule due dimaterialize tepat satu kali;
- Client/Template/Schedule nonaktif tidak menjalankan HTTP;
- placeholder dan credential resolve sama pada kedua desain;
- HTTP 2xx, non-2xx, connection failure, dan timeout menghasilkan status benar;
- `prevent_overlap` tetap bekerja;
- Run Now dan manual Retry tetap berjalan di background;
- pause setelah dispatch mencegah request yang belum dimulai.

### 17.2 Load

Uji setidaknya:

- 10, 50, 100, dan peak expected Client due bersamaan;
- response 100 ms, 5 detik, 30 detik, dan timeout maksimum;
- response body kecil dan batas maksimum;
- kombinasi endpoint cepat dan satu endpoint sangat lambat;
- resource CPU, memory, DB connection, socket, dan bandwidth;
- p50/p95/p99 start lag serta batch completion time.

### 17.3 Failure and recovery

- kill dispatcher ketika sebagian request belum dimulai;
- kill ketika request sedang in-flight;
- restart server ketika batch/queue masih aktif;
- database putus sementara;
- Redis/Horizon putus untuk desain queue;
- endpoint menerima request tetapi response terputus;
- duplicate invocation scheduler;
- deploy ketika pekerjaan masih berjalan.

## 18. Decision record template

Isi bagian berikut setelah pengukuran dan pengujian selesai:

```text
Keputusan:
Tanggal:
Pemilik keputusan:

SLA start lag:
Peak due jobs per minute:
HTTP duration p50/p95/p99:
Concurrency yang dipilih:
RAM/CPU hasil load test:

Pendekatan terpilih:
Alasan utama:
Trade-off yang diterima:
Mitigasi failure:
Rencana rollback:
```

## 19. Kesimpulan

Direct Concurrent HTTP dan Redis Queue + Horizon sama-sama dapat mengeksekusi
HTTP request secara concurrent. Direct pool berpotensi memberikan fan-out lebih
rapat dengan lebih sedikit PHP process, tetapi menggabungkan dispatcher dan
executor serta memperbesar dampak kegagalan satu process. Queue menggunakan
lebih banyak komponen dan dapat menambah waktu tunggu ketika worker tidak cukup,
tetapi memberikan buffering, isolation, scaling, graceful deployment, dan
observability yang lebih matang.

Keputusan sebaiknya tidak didasarkan pada rata-rata job harian. Gunakan peak
simultaneous schedules, distribusi durasi HTTP, kapasitas VPS dan endpoint, serta
SLA start lag. Jika kebutuhan utamanya hanya mengurangi queue wait pada fan-out,
menyediakan worker yang warm atau queue khusus biasanya merupakan perubahan
lebih kecil daripada menghapus queue sepenuhnya.
