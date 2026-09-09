# Artifact Teknis Opsifin Scheduler

| Metadata | Nilai |
| --- | --- |
| Dokumen | Arsitektur dan workflow teknis end-to-end |
| Sistem | Opsifin Scheduler |
| Versi artifact | 1.0 |
| Diperbarui | 21 Agustus 2026 |
| Target runtime | Laravel 13, Filament 5, MySQL, Redis, Horizon, Supervisor |
| Timezone bisnis | `Asia/Jakarta` |
| Status arsitektur | Redis Queue dan Horizon adalah target runtime terbaru |

Dokumen ini menjelaskan Opsifin Scheduler dari hulu ke hilir: bagaimana data
Client dan Job Template dibentuk, bagaimana Schedule dihitung, bagaimana Run
dibuat dan dipublikasikan ke Redis, bagaimana Horizon mengeksekusi HTTP request,
bagaimana hasil disimpan, dan bagaimana sistem pulih ketika terjadi gangguan.

Dokumen ini menggunakan implementasi pada source code sebagai sumber kebenaran.
Runbook deployment terpisah tersedia di
[redis-horizon-cutover-vps.md](redis-horizon-cutover-vps.md).

## 1. Ringkasan sistem

Opsifin Scheduler adalah control plane untuk menjalankan HTTP job terjadwal ke
banyak Client. Operator tidak membuat cron Linux untuk setiap Client. Satu
Laravel Scheduler membaca seluruh konfigurasi Schedule dari MySQL, membuat Run,
dan mengirim payload eksekusi ke Redis Queue.

Karakteristik utama sistem:

- definisi HTTP job dibuat sekali sebagai Task Template;
- satu Task Template dapat di-assign ke banyak Client;
- setiap assignment memiliki cron expression, timezone, status enable, dan
  aturan overlap sendiri;
- satu system cron memanggil Laravel Scheduler setiap menit;
- Redis menyimpan payload queue;
- Horizon mengatur dan memantau worker Redis;
- hasil eksekusi dan histori bisnis tetap disimpan di MySQL;
- setiap Run melakukan tepat satu HTTP attempt;
- HTTP failure tidak di-retry otomatis;
- retry dilakukan secara eksplisit oleh operator;
- perubahan domain penting dicatat di Audit Log.

## 2. Tujuan dan batas sistem

### 2.1 Tujuan

Sistem dibangun untuk:

- menggantikan ratusan script dan entry crontab legacy dengan konfigurasi yang
  dapat dikelola melalui UI;
- menjaga katalog job agar canonical dan tidak digandakan per Client;
- memberikan histori eksekusi yang dapat difilter dan diperiksa;
- memberikan pause, resume, run now, cancel queued run, dan retry manual;
- mencegah overlap untuk Schedule yang sama;
- memisahkan beban queue dari MySQL menggunakan Redis;
- menyediakan observability worker melalui Horizon dan observability teknis
  Laravel melalui Telescope.

### 2.2 Batas yang disengaja

Sistem tidak menyediakan:

- automatic retry untuk HTTP 4xx, 5xx, connection error, atau timeout;
- replay seluruh occurrence yang terlewat selama downtime;
- cron Linux per Client atau per job;
- runtime request override khusus per Client;
- blackout calendar;
- incident management atau alert engine internal;
- distributed Redis/MySQL high availability bawaan;
- pembatalan paksa untuk HTTP request yang sudah berjalan.

## 3. Technology stack

| Layer | Teknologi | Tanggung jawab |
| --- | --- | --- |
| Backend | PHP 8.3+ dan Laravel 13 | Domain, scheduling, queue dispatch, execution |
| Admin UI | Filament 5 | Pengelolaan Client, Template, Schedule, Run, User |
| Primary storage | MySQL | Data bisnis, histori Run, session, cache, failed jobs |
| Queue broker | Redis | Payload queue dan data internal Horizon |
| Redis client | Predis | Koneksi Laravel/PHP ke Redis Server |
| Queue manager | Laravel Horizon 5 | Worker pool, balancing, queue metrics, dashboard |
| Process manager | Supervisor | Menjaga master Horizon tetap hidup |
| Scheduler trigger | system cron | Menjalankan `artisan schedule:run` setiap menit |
| Technical observability | Laravel Telescope | Request, job, exception, command, schedule |
| HTTP client | Laravel HTTP Client | Eksekusi request ke endpoint Client |
| Frontend build | Vite 8 dan Tailwind CSS 4 | Asset aplikasi |

## 4. System context

```text
                       +------------------------+
                       | Administrator/Operator |
                       | Viewer                 |
                       +-----------+------------+
                                   |
                                   | HTTPS
                                   v
+---------------+       +----------+-----------+       +----------------+
| system cron   +------>| Laravel + Filament   +------>| MySQL          |
| setiap menit  |       | Opsifin Scheduler    |       | source of truth|
+---------------+       +----------+-----------+       +----------------+
                                   |
                                   | publish ExecuteRun
                                   v
                       +-----------+------------+
                       | Redis Queue            |
                       | Horizon metadata       |
                       +-----------+------------+
                                   |
                                   v
                       +-----------+------------+
                       | Horizon workers        |
                       | dijaga Supervisor      |
                       +-----------+------------+
                                   |
                                   | HTTP/HTTPS
                                   v
                       +-----------+------------+
                       | Endpoint Client        |
                       +------------------------+
```

## 5. Runtime topology

### 5.1 MySQL

MySQL adalah source of truth untuk:

- user dan role;
- Client dan credential;
- Task Template;
- Schedule dan `next_run_at`;
- Run dan hasil eksekusi;
- Audit Log;
- import history dan findings;
- Laravel session;
- Laravel cache;
- Laravel failed queue jobs;
- Telescope entries.

### 5.2 Redis

Redis hanya dipakai oleh subsistem queue dan Horizon:

| Logical DB | Isi |
| --- | --- |
| Redis DB 0 | Metadata, supervisor state, recent jobs, dan metrics Horizon |
| Redis DB 2 | Payload queue `default` |

`REDIS_CACHE_DB=1` boleh tersedia dalam konfigurasi framework, tetapi tidak
digunakan selama `CACHE_STORE=database`.

Logical DB bukan Redis Server terpisah. Keduanya adalah namespace pada Redis
Server yang sama. Untuk production, Redis harus menggunakan AOF,
`appendfsync everysec`, dan `maxmemory-policy noeviction`.

### 5.3 Horizon dan Supervisor

Supervisor menjalankan satu master process:

```bash
php artisan horizon
```

Master Horizon mengatur worker berdasarkan konfigurasi:

| Parameter | Production default |
| --- | --- |
| Connection | `redis` |
| Queue | `default` |
| Balancing | `auto`, strategy `time` |
| Minimum worker | 2 |
| Maksimum worker | 10 |
| Worker memory | 128 MB |
| Worker max time | 3600 detik |
| Job tries | 1 |
| Worker timeout | 1900 detik |
| Redis retry after | 2000 detik |
| Blocking pop | 5 detik |

Invariant waktunya:

```text
Task request timeout <= Horizon worker timeout
Horizon worker timeout < Redis retry_after
Supervisor stopwaitsecs >= Redis retry_after
```

Form Task Template membatasi request timeout maksimal 1800 detik. Margin antara
1800, 1900, dan 2000 detik mengurangi risiko payload diambil worker lain sebelum
worker pertama benar-benar dihentikan.

## 6. Domain model

```text
users
  |
  +---- audit_logs

clients 1 ----- * schedules * ----- 1 task_templates
   |                  |                       |
   |                  +----- 1 ----- * runs --+
   |                                      |
   +--------------------------- * --------+

runs 0..1 ----- source_run_id ----- 1 runs

import_runs 1 ----- * import_findings
```

### 6.1 User

User mengakses panel Filament. Role yang tersedia:

| Role | Kemampuan utama |
| --- | --- |
| Administrator | CRUD master data, kebijakan Schedule, User, Horizon, Telescope |
| Operator | Pause/resume, run now, cancel queued Run, retry Run gagal |
| Viewer | Membaca konfigurasi, preview aman, histori, dan Audit Log |

Hanya user aktif yang dapat masuk panel. Horizon dan Telescope dibatasi untuk
Administrator.

### 6.2 Client

Client merepresentasikan satu target Opsifin dan menyimpan:

- `code` dan nama;
- `base_url`;
- timezone;
- status aktif;
- tipe autentikasi `basic`, `bearer`, atau `none`;
- username, secret, dan secret key;
- metadata serta catatan migrasi legacy.

Credential disimpan sesuai nilai input dan disembunyikan dari serialisasi model.
Karena credential berada di database, backup MySQL harus dianggap sensitif.

Menonaktifkan Client mencegah semua assignment-nya dieksekusi tanpa menghapus
Schedule atau histori Run.

### 6.3 Task Template

Task Template adalah definisi canonical sebuah HTTP job:

- key stabil;
- nama dan deskripsi;
- executor, saat ini hanya `http`;
- HTTP method;
- endpoint path;
- headers tambahan;
- JSON body;
- connect timeout dan request timeout;
- status aktif;
- kebijakan default Schedule untuk Client baru;
- metadata migrasi legacy.

Placeholder yang didukung:

```text
{{client.code}}
{{client.username}}
{{client.secret}}
{{client.password}}
{{client.secret_key}}
{{run.scheduled_for}}
```

`{{client.password}}` adalah alias kompatibilitas untuk
`{{client.secret}}`. `{{run.scheduled_for}}` dikirim sebagai ISO-8601 UTC.

### 6.4 Schedule

Schedule menghubungkan satu Client dengan satu Task Template pada waktu tertentu:

```text
client_id
task_template_id
cron_expression
timezone
is_enabled
next_run_at
queue
prevent_overlap
running_run_id
```

Kombinasi berikut unik:

```text
client_id + task_template_id + cron_expression
```

Satu job dapat memiliki lebih dari satu timing untuk Client yang sama selama
cron expression berbeda.

Ketika Schedule di-pause:

```text
is_enabled = false
next_run_at = null
```

Ketika di-resume, `next_run_at` dihitung ulang dari waktu sekarang. Sistem tidak
memutar ulang semua occurrence selama Schedule di-pause.

### 6.5 Run

Run adalah satu occurrence atau satu permintaan eksekusi. Run menyimpan:

- referensi Schedule, Client, dan Task Template;
- `source_run_id` untuk retry;
- `materialization_key` untuk idempotensi occurrence terjadwal;
- `scheduled_for`;
- trigger `schedule`, `manual`, atau `retry`;
- status;
- queue job ID Redis;
- waktu queued, started, finished, dan execution deadline;
- worker identity;
- HTTP status;
- duration;
- response excerpt;
- error message.

Referensi Client dan Task Template didenormalisasi ke Run agar histori tetap
dapat difilter walaupun Schedule kemudian dihapus.

### 6.6 Audit Log

Observer mencatat create, update, dan delete pada Client, Task Template,
Schedule, dan User ketika perubahan dilakukan oleh user login. Nilai dengan nama
password, secret, token, authorization, atau API key di-redact sebelum disimpan.

### 6.7 Import Run dan Finding

Import Run mencatat satu proses import dari repository cron legacy. Import
Finding menyimpan error, warning, atau informasi yang membutuhkan rekonsiliasi
manual.

## 7. Workflow konfigurasi dari UI

### 7.1 Membuat Client baru

```text
Administrator membuat Client
        |
        v
Client disimpan di MySQL
        |
        v
DefaultScheduleProvisioner membaca Task Template aktif
yang auto_assign_to_new_clients=true
        |
        v
Schedule default dibuat secara transaction
        |
        v
Schedule default paused kecuali kebijakan template menyatakan enabled
```

Default paused memberi ruang untuk memeriksa URL, credential, request preview,
cron expression, dan timezone sebelum eksekusi pertama.

### 7.2 Membuat atau mengubah Task Template

Administrator menentukan request canonical. Perubahan template berlaku ke semua
Schedule yang menggunakan template tersebut. Existing Schedule tidak otomatis
mengikuti perubahan default cron karena default hanya digunakan saat assignment
baru dibuat.

### 7.3 Assignment

Administrator dapat:

- assign template ke seluruh Client aktif;
- assign ke Client terpilih;
- menghapus assignment terpilih;
- mengatur cron dan timezone;
- memilih apakah assignment langsung enabled.

Assignment dibuat idempotent: assignment yang sudah ada tidak dibuat ulang dan
konfigurasi Schedule lama tidak diubah diam-diam.

### 7.4 Inspect request

UI dapat resolve request tanpa memanggil endpoint. Preview menampilkan method,
URL, headers, body, dan timeout. Credential dan header sensitif disamarkan.

### 7.5 Pause dan resume

Operator atau Administrator dapat pause/resume Schedule. Resume menghitung
`next_run_at` berikutnya berdasarkan cron dan timezone dari waktu sekarang.

## 8. Workflow import legacy

Import legacy hanya digunakan untuk migrasi awal, bukan runtime harian.

Sumber yang dibaca:

```text
opsifin_env.sh
configs/*.conf
gateway.sh
jobs/*.sh
folder-client/*.sh
opsifin_crontab atau crontab.txt
```

Alurnya:

```text
Parse environment dan config
        |
        v
Parse gateway dan canonical jobs/*.sh
        |
        v
Parse script di folder Client
        |
        v
Bentuk Task Template dari jobs/*.sh
        |
        v
Bentuk Client dari config/folder
        |
        v
Petakan entry crontab menjadi Schedule
        |
        v
Simpan finding untuk drift atau data yang tidak dapat dipetakan
        |
        v
Semua Schedule hasil import tetap disabled
```

Prinsip import:

- `jobs/*.sh` adalah katalog canonical;
- script Client hanya menentukan assignment lama;
- perbedaan request Client tidak membuat template baru;
- ketidakcocokan dicatat, bukan ditebak diam-diam;
- dry run menggunakan transaction rollback;
- `--fresh` diperlukan jika domain sudah berisi data dan hanya boleh dilakukan
  setelah backup.

Command terkait:

```bash
php artisan cron:import --fresh --dry-run --report=<path>
php artisan cron:import --fresh --report=<path>
php artisan cron:verify-import
php artisan cron:cutover-status
```

## 9. Workflow scheduling otomatis

### 9.1 Trigger paling hulu

VPS memiliki tepat satu system cron:

```cron
* * * * * <app-user> cd <project-path> && <php-binary> artisan schedule:run
```

Laravel Scheduler mendaftarkan:

| Frekuensi | Command | Fungsi |
| --- | --- | --- |
| Setiap menit | `jobs:dispatch-due` | Membentuk Run untuk Schedule due |
| Setiap menit | `jobs:reconcile-queued` | Memulihkan Run tanpa payload queue |
| Setiap 5 menit | `horizon:snapshot` | Menyimpan snapshot metrics Horizon |
| 02:30 setiap hari | `telescope:prune --hours=168` | Retensi Telescope |
| 03:00 setiap hari | `cron:purge-runs` | Retensi Run terminal |

Setiap command memakai Laravel `withoutOverlapping` agar invocation sebelumnya
tidak bertumpuk.

### 9.2 Pemilihan Schedule due

`DueScheduleDispatcher` melakukan:

1. membulatkan waktu kerja ke awal menit;
2. memulihkan Run `running` yang melewati execution deadline;
3. mencari Schedule enabled dengan `next_run_at <= sekarang`;
4. hanya mengambil Schedule dengan Client dan Task Template aktif;
5. memproses setiap Schedule dalam transaction terpisah;
6. melakukan row lock pada Schedule;
7. menghitung occurrence terbaru yang seharusnya berjalan;
8. menghitung `next_run_at` berikutnya dari waktu sekarang;
9. membuat Run `queued` atau `skipped`;
10. commit transaction;
11. memublikasikan `ExecuteRun` ke Redis setelah commit.

### 9.3 Kebijakan downtime

Jika dispatcher berhenti selama beberapa waktu, sistem hanya membuat occurrence
terbaru yang due saat kembali hidup. Sistem tidak membuat seluruh backlog yang
terlewat. `next_run_at` berikutnya dihitung dari waktu sekarang.

Kebijakan ini mencegah ratusan request lama membanjiri endpoint Client setelah
downtime.

### 9.4 Materialization idempotency

Untuk trigger schedule, sistem membuat key:

```text
SHA-256(schedule ID + scheduled_for UTC hingga resolusi menit)
```

Kolom `materialization_key` unik di MySQL. Jika dua invocation mencoba
membentuk occurrence yang sama, hanya satu Run yang menjadi sumber eksekusi.

## 10. Transaction boundary MySQL ke Redis

MySQL dan Redis tidak berada dalam distributed transaction yang sama. Karena
itu publish dilakukan dengan pola berikut:

```text
BEGIN MySQL transaction
  lock Schedule
  advance next_run_at
  insert Run(status=queued, queue_job_id=null)
COMMIT MySQL transaction

push ExecuteRun(run_id) ke Redis
update Run.queue_job_id dengan Redis job UUID
```

Publish tidak dilakukan sebelum commit karena worker Redis dapat mengambil job
dengan sangat cepat. Jika worker melihat Run yang belum committed, job dapat
menjadi no-op atau gagal ditemukan.

Pola setelah commit mempunyai celah lain: process dapat crash setelah commit
tetapi sebelum Redis push atau sebelum queue job ID disimpan. Celah ini ditutup
oleh `jobs:reconcile-queued`.

Reconciler mencari:

```text
status = queued
queue_job_id IS NULL
queued_at lebih lama dari satu menit
```

Kemudian Run dipublikasikan kembali ke queue. Batas satu menit mencegah
reconciler terlalu cepat bersaing dengan proses publish normal.

## 11. Redis Queue dan Horizon workflow

```text
RunDispatcher
    |
    | push ExecuteRun(run_id), queue=default
    v
Redis DB 2
    |
    v
Horizon supervisor-1
    |
    | auto balance 2..10 worker
    v
ExecuteRun::handle
    |
    v
RunWorker::process(run_id)
```

Setiap `ExecuteRun` memiliki tag:

```text
run:<run_id>
```

Tag memudahkan pencarian job tertentu pada Horizon.

Horizon DB 0 menyimpan data monitoring. Payload queue tetap berada di DB 2.
Horizon metrics bukan pengganti histori bisnis; histori authoritative tetap
berada pada tabel `runs`.

## 12. Workflow worker

`RunWorker` menjalankan langkah berikut:

1. mengambil Run beserta Schedule, Client, dan Task Template;
2. berhenti sebagai no-op jika Run tidak ada, sudah terminal, atau sudah running;
3. menandai skipped jika relasi domain sudah tidak ada;
4. melakukan atomic claim `queued -> running`;
5. mengisi `started_at`, execution deadline, dan worker identity;
6. melakukan atomic claim overlap slot bila `prevent_overlap=true`;
7. memeriksa ulang status Client, Task Template, dan Schedule;
8. memilih executor berdasarkan tipe Task Template;
9. resolve URL, placeholder, headers, authorization, body, dan timeout;
10. mengirim tepat satu HTTP request;
11. menyimpan status, HTTP status, duration, response excerpt, atau error;
12. melepaskan overlap slot pada blok `finally`.

Atomic claim menggunakan update bersyarat:

```text
UPDATE runs
SET status = running
WHERE id = <run_id> AND status = queued
```

Jika payload Redis terduplikasi, hanya worker pertama yang dapat mengubah status
dari queued menjadi running. Worker berikutnya menjadi no-op.

## 13. Workflow HTTP executor

### 13.1 Resolve request

URL dibentuk dari:

```text
Task Template config.base_url atau Client.base_url
        +
Task Template config.path
```

Kemudian placeholder diganti dengan nilai Client dan Run.

Authorization ditambahkan otomatis:

| Auth type | Header |
| --- | --- |
| Basic | `Basic base64(username:secret)` |
| Bearer | `Bearer <secret>` |
| None | Tidak ada Authorization header |

Headers tambahan dan body berasal dari Task Template. Body array dikonversi
menjadi JSON.

### 13.2 Execute request

HTTP executor mengatur:

- default `Accept: application/json`;
- default `Content-Type: application/json`;
- connect timeout dari template;
- request timeout dari template;
- satu HTTP attempt;
- response excerpt maksimal 2000 karakter.

Respons HTTP 2xx dianggap sukses. Respons non-2xx, connection error, timeout,
atau exception dianggap gagal.

### 13.3 Penyimpanan hasil

Hasil ditulis ke Run:

```text
status
http_status
duration_ms
response_excerpt
error_message
finished_at
```

Secret Client di-redact dari response excerpt dan error sebelum data disimpan.

## 14. Run state machine

```text
                         +----------------+
                         |                |
                         v                |
queued -------> running -------> succeeded
  |                |
  |                +-----------> failed
  |
  +----------------------------> cancelled
  |
  +----------------------------> skipped

failed -------- manual retry --------> queued (Run baru)
```

Arti status:

| Status | Makna |
| --- | --- |
| `queued` | Run tercatat dan menunggu worker |
| `running` | Worker sudah melakukan atomic claim |
| `succeeded` | Endpoint menghasilkan HTTP 2xx |
| `failed` | HTTP non-2xx, timeout, connection error, atau execution error |
| `skipped` | Tidak dieksekusi karena overlap, pause, atau relasi tidak tersedia |
| `cancelled` | Dibatalkan ketika masih queued |

## 15. Trigger otomatis, manual, dan retry

### 15.1 Schedule trigger

Trigger `schedule` hanya dieksekusi jika Schedule, Client, dan Task Template masih
aktif ketika worker mulai. Jika dipause setelah payload masuk queue, Run menjadi
skipped tanpa memanggil endpoint.

### 15.2 Run now

Run now membuat Run baru dengan trigger `manual`. Manual Run tetap mensyaratkan
Client dan Task Template aktif, tetapi boleh berjalan ketika Schedule paused.
Overlap guard tetap berlaku.

### 15.3 Retry

Retry hanya diperbolehkan untuk Run `failed`. Retry membuat Run baru dengan:

```text
trigger = retry
source_run_id = ID Run gagal
```

Run lama tidak diubah sehingga histori keputusan operator tetap terlihat.

### 15.4 Cancel queued Run

Cancel hanya berlaku ketika Run masih `queued`:

1. Run di-lock dalam transaction MySQL;
2. payload pending dicari dan dihapus dari Redis Queue;
3. status Run diubah menjadi `cancelled`;
4. tindakan dicatat ke Audit Log.

Ada race condition alami jika worker mengambil payload tepat sebelum cancel.
Status Run tetap menjadi pengaman: worker hanya boleh claim Run yang masih
`queued`. Payload yang sudah reserved tetapi Run telah cancelled akan menjadi
no-op.

## 16. Overlap protection

`prevent_overlap=true` adalah pengganti `flock -n` pada sistem legacy.

Sistem menggunakan dua pemeriksaan:

1. dispatcher melihat `schedules.running_run_id` dan dapat membuat occurrence
   `skipped` sebelum masuk queue;
2. worker melakukan atomic claim pada `running_run_id` sebelum HTTP execution.

Worker kedua tidak mendapat slot jika Schedule yang sama masih memiliki Run
aktif. Occurrence tersebut dicatat `skipped` dengan alasan bahwa Run sebelumnya
masih berjalan.

Jika worker mati dan tidak membersihkan slot, dispatcher memulihkan Run yang
melewati `execution_deadline_at`, menandainya failed, lalu melepas slot.

## 17. Timezone dan representasi waktu

Kebijakan waktu:

```dotenv
APP_TIMEZONE=Asia/Jakarta
DB_TIMEZONE=+07:00
CRON_DEFAULT_TIMEZONE=Asia/Jakarta
```

Prinsipnya:

- cron expression dihitung dalam timezone milik Schedule;
- default timezone Schedule adalah Asia/Jakarta;
- waktu occurrence mewakili instant yang sama secara konsisten;
- `{{run.scheduled_for}}` dikirim ke endpoint sebagai ISO-8601 UTC;
- UI menampilkan waktu operasional menggunakan Asia/Jakarta;
- MySQL session diselaraskan ke `+07:00` agar pembacaan dan penulisan timestamp
  konsisten dengan aplikasi.

Migration normalisasi timezone hanya dipakai untuk memperbaiki timestamp lama
yang sebelumnya ditulis saat session database dan Laravel tidak selaras.

## 18. Failure semantics

### 18.1 Business execution failure

Contoh:

- HTTP 400, 401, 403, 404, 422, atau 500;
- DNS atau TLS error;
- connection timeout;
- request timeout;
- request tidak dapat di-resolve.

Hasilnya:

- Run ditandai `failed` di MySQL;
- error dan response excerpt disimpan setelah redaction;
- operator dapat membuat Retry baru;
- job queue dapat terlihat completed di Horizon karena worker berhasil menangani
  kegagalan bisnis dan menyimpan hasilnya.

Karena itu, keberhasilan job di Horizon tidak selalu berarti endpoint sukses.
Status bisnis authoritative harus dilihat pada Execution Logs atau tabel `runs`.

### 18.2 Queue infrastructure failure

Contoh:

- Redis tidak dapat dihubungi;
- Horizon mati;
- payload tidak dapat di-deserialize;
- exception keluar dari job handler;
- worker dihentikan paksa.

Hasilnya dapat terlihat pada:

- Horizon dashboard;
- Supervisor status dan log;
- Laravel log;
- Telescope job/exception entry;
- tabel `failed_jobs` untuk queue job yang benar-benar gagal pada level Laravel.

### 18.3 Failure matrix

| Failure | Dampak | Recovery |
| --- | --- | --- |
| System cron mati | Tidak ada due dispatch baru | Hidupkan cron; occurrence terbaru diproses |
| Dispatcher crash sebelum commit | Tidak ada Run committed | Menit berikutnya akan mencoba lagi |
| Crash setelah commit sebelum Redis push | Run queued tanpa payload | `jobs:reconcile-queued` memublikasikan ulang |
| Horizon mati | Payload menunggu di Redis | Supervisor restart Horizon |
| Worker mati saat HTTP call | Run sementara tetap running | Deadline recovery menandai failed dan melepas slot |
| Redis restart | Queue berhenti sementara | Redis memuat AOF lalu Horizon melanjutkan |
| Redis penuh | Push baru ditolak | `noeviction`, log error, reconciler mencoba lagi |
| HTTP non-2xx | Run failed | Operator review lalu Retry manual |
| Client/Template/Schedule dipause | Scheduled Run skipped | Resume bila memang perlu |
| Duplicate payload | Worker kedua tidak dapat claim | Atomic Run state menjadikannya no-op |
| Previous Run aktif | Occurrence baru skipped | Tidak ada backlog retry otomatis |

## 19. Reliability boundaries

Redis dan Horizon meningkatkan operational reliability melalui antrean cepat,
autoscaling, process supervision, metrics, dan diagnosis yang lebih jelas.
Namun batas berikut tetap ada:

- Redis pada VPS yang sama masih menjadi single point of failure bersama
  aplikasi dan MySQL;
- AOF `everysec` secara teori dapat kehilangan perubahan paling akhir ketika
  host mati mendadak;
- reconciler otomatis hanya mengambil Run queued dengan `queue_job_id` null;
- kehilangan total Redis setelah job ID tersimpan membutuhkan prosedur recovery
  terkontrol;
- tidak ada automatic HTTP retry untuk mencegah efek bisnis ganda;
- exactly-once delivery tidak dapat dijamin pada sistem terdistribusi, sehingga
  endpoint tujuan idealnya idempotent.

Untuk high availability yang lebih tinggi, Redis dan MySQL perlu memakai managed
service atau replication/failover di host terpisah.

## 20. Observability

### 20.1 Dashboard aplikasi

Dashboard Filament menampilkan:

- jumlah Schedule enabled;
- success rate 24 jam;
- Run queued;
- Run running;
- occurrence queued paling lama;
- Client Job Summary dan assignment yang belum lengkap.

Runs table melakukan polling setiap 15 detik dan dapat difilter berdasarkan
Client, Task Template, status, trigger, dan periode.

### 20.2 Horizon

Horizon dipakai untuk:

- status master dan supervisor;
- worker count;
- queue throughput;
- wait time;
- recent, pending, completed, dan failed queue jobs;
- pencarian tag `run:<id>`;
- balancing worker otomatis.

Horizon menyimpan metrics snapshot setiap lima menit. Dashboard hanya boleh
diakses Administrator aktif.

### 20.3 Telescope

Telescope merekam area yang relevan:

- application request;
- outbound client request;
- queue job;
- exception;
- error log;
- Artisan command;
- scheduled task.

Parameter dan header sensitif disembunyikan. Telescope menggunakan MySQL dan
dipangkas setiap hari dengan retention 168 jam.

### 20.4 Log

| Log | Kegunaan |
| --- | --- |
| `storage/logs/laravel.log` | Exception dan error aplikasi |
| Horizon log | Lifecycle master dan worker |
| Scheduler log | Output `artisan schedule:run` |
| Web server/PHP-FPM log | Error HTTP ingress dan PHP runtime |
| Execution Logs | Hasil bisnis setiap HTTP execution |
| Audit Log | Perubahan konfigurasi oleh user |

## 21. Troubleshooting decision tree

```text
Run tidak dibuat?
  -> periksa system cron, schedule:list, scheduler log, next_run_at

Run queued dan queue_job_id null?
  -> periksa Redis connectivity dan jobs:reconcile-queued

Run queued dan queue_job_id terisi?
  -> periksa redis-cli ping, Horizon status, Supervisor, queue wait

Run lama di running?
  -> periksa Horizon/Laravel log, HTTP timeout, execution_deadline_at

Run failed dengan HTTP status?
  -> periksa endpoint, credential, response excerpt

Run failed tanpa HTTP status?
  -> periksa DNS, TLS, network, timeout, resolved request

Run skipped?
  -> periksa pause state, active state, atau overlap slot
```

Command diagnosis utama:

```bash
redis-cli ping
sudo supervisorctl status opsifin-scheduler-horizon
php artisan horizon:status
php artisan schedule:list
php artisan queue:failed
php artisan jobs:reconcile-queued
```

## 22. Security model

### 22.1 Application access

- hanya user aktif dapat mengakses panel;
- Administrator mengelola master data dan user;
- Operator hanya menjalankan operasi scheduler;
- Viewer hanya membaca;
- Horizon dan Telescope hanya untuk Administrator;
- production wajib menggunakan HTTPS.

### 22.2 Secret handling

- credential Client disembunyikan dari serialisasi model;
- Authorization header dibentuk saat runtime;
- preview request menyamarkan secret;
- response dan error di-redact sebelum disimpan;
- Audit Log meredact field sensitif;
- Telescope menyembunyikan request parameter, header, dan response field
  sensitif;
- database dump, backup, dan `.env` harus dianggap secret.

### 22.3 Infrastructure

- Redis bind ke localhost atau private network;
- port 6379 tidak dipublikasikan ke internet;
- MySQL tidak dipublikasikan ke internet;
- worker dan scheduler berjalan sebagai app user, bukan root;
- `.env` tidak disimpan di repository;
- backup disimpan di luar web root;
- log menggunakan rotation dan retention.

## 23. Retention

Run terminal lebih lama dari `CRON_RUNS_RETENTION_DAYS`, default 90 hari,
dihapus setiap pukul 03:00 dalam chunk. Run queued dan running tidak pernah
dihapus oleh retention job.

Telescope entries dipangkas menjadi sekitar tujuh hari melalui:

```bash
php artisan telescope:prune --hours=168
```

Horizon recent/completed jobs disimpan 60 menit dan failed/monitored jobs disimpan
10080 menit pada metadata Redis sesuai konfigurasi Horizon.

## 24. Deployment lifecycle

### 24.1 Initial Redis cutover

Urutan aman:

1. install dan harden Redis;
2. backup MySQL, `.env`, dan Supervisor configuration;
3. nonaktifkan cron aplikasi sementara;
4. drain database queue lama;
5. hentikan database worker;
6. deploy release Redis/Horizon;
7. isi `.env` Redis;
8. tes koneksi Redis dari Laravel;
9. jalankan migration;
10. install Supervisor Horizon;
11. jalankan reconciler;
12. aktifkan kembali scheduler;
13. smoke test satu endpoint aman;
14. pantau minimal dua siklus.

Detail command tersedia di
[redis-horizon-cutover-vps.md](redis-horizon-cutover-vps.md).

### 24.2 Release berikutnya

```text
backup
  -> checkout release
  -> composer install
  -> build asset
  -> migrate --force
  -> optimize
  -> horizon:terminate
  -> Supervisor restart master
  -> smoke test
```

`horizon:terminate` digunakan agar worker lama selesai secara graceful dan
Supervisor menjalankan master baru dengan source code release terbaru.

## 25. Capacity model

Lima ratus Schedule per hari bukan beban besar secara rata-rata:

```text
500 / 24 jam = sekitar 20,8 per jam
500 / 1440 menit = sekitar 0,35 per menit
```

Faktor yang lebih penting daripada total harian:

- berapa banyak Schedule jatuh pada menit yang sama;
- rata-rata dan persentil durasi HTTP;
- kapasitas endpoint Client;
- RAM per PHP worker;
- timeout;
- pertumbuhan Run history;
- frekuensi error dan retry manual.

Horizon 2 sampai 10 worker memberi kapasitas burst, tetapi maksimum worker harus
disesuaikan dengan RAM VPS dan kemampuan endpoint tujuan. Lebih banyak worker
tidak selalu lebih baik jika endpoint Client memiliki rate limit atau locking.

## 26. Testing strategy

Test suite mencakup:

- admin panel dan authorization;
- Client credential storage;
- default Schedule provisioning;
- Schedule management;
- due dispatcher dan downtime semantics;
- overlap behavior;
- Run Worker success, failure, skip, dan recovery;
- HTTP executor dan redaction;
- queued Run cancellation;
- reconciler;
- Horizon access dan queue invariant;
- Telescope access;
- legacy import dan parser;
- retention;
- trusted proxy;
- health route.

Command verifikasi:

```bash
php artisan test --compact
php vendor/bin/pint --test
CACHE_STORE=array php artisan schedule:list
npm run build
composer validate --strict
composer audit --locked --no-interaction
```

## 27. Operational checklist harian

- pastikan Redis menjawab `PONG`;
- pastikan Supervisor Horizon `RUNNING`;
- pastikan `artisan horizon:status` menyatakan aktif;
- periksa Run queued paling lama;
- periksa Run running yang melewati deadline;
- periksa failure rate Execution Logs;
- periksa `queue:failed` untuk infrastructure failure;
- periksa CPU, RAM, disk, inode, dan pertumbuhan log;
- pastikan backup MySQL terbaru tersedia;
- pastikan Redis AOF aktif dan tidak mengalami eviction.

## 28. Source code map

### Scheduling

```text
routes/console.php
app/Console/Commands/DispatchDueJobsCommand.php
app/Console/Commands/ReconcileQueuedRunsCommand.php
app/Services/Scheduling/NextRunCalculator.php
app/Services/Scheduling/DueScheduleDispatcher.php
app/Services/Scheduling/RunDispatcher.php
app/Services/Scheduling/RunWorker.php
app/Services/Scheduling/QueuedRunCanceller.php
app/Jobs/ExecuteRun.php
```

### HTTP execution

```text
app/Services/Execution/ExecutorManager.php
app/Services/Execution/HttpExecutor.php
app/Services/Execution/Dto/ResolvedRequest.php
app/Services/Execution/Dto/ExecutionResult.php
```

### Domain

```text
app/Models/Client.php
app/Models/TaskTemplate.php
app/Models/Schedule.php
app/Models/Run.php
app/Models/User.php
app/Models/AuditLog.php
```

### Redis dan Horizon

```text
config/database.php
config/queue.php
config/horizon.php
app/Providers/HorizonServiceProvider.php
deploy/vps/supervisor-worker.conf.template
```

### Observability dan maintenance

```text
app/Providers/TelescopeServiceProvider.php
config/telescope.php
app/Services/Maintenance/RetentionService.php
app/Console/Commands/CronPurgeRunsCommand.php
```

### Legacy import

```text
app/Services/LegacyImport/
app/Console/Commands/CronImportCommand.php
app/Console/Commands/CronVerifyImportCommand.php
app/Console/Commands/CronCutoverStatusCommand.php
```

### Deployment

```text
deploy/vps/
deploy/aapanel/
docs/deployment-vps.md
docs/database-migration-vps.md
docs/redis-horizon-cutover-vps.md
```

## 29. Configuration reference

Minimal production configuration:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Asia/Jakarta
DB_TIMEZONE=+07:00

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=redis
QUEUE_FAILED_DRIVER=database-uuids

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=<secret-atau-null>
REDIS_PORT=6379
REDIS_DB=0
REDIS_QUEUE_DB=2
REDIS_QUEUE_CONNECTION=queue
REDIS_QUEUE=default
REDIS_QUEUE_RETRY_AFTER=2000
REDIS_QUEUE_BLOCK_FOR=5

HORIZON_NAME="Opsifin Scheduler Production"
HORIZON_REDIS_CONNECTION=default
HORIZON_MIN_PROCESSES=2
HORIZON_MAX_PROCESSES=10
HORIZON_TIMEOUT=1900

CRON_DEFAULT_TIMEZONE=Asia/Jakarta
CRON_RUNS_RETENTION_DAYS=90
CRON_EXECUTION_MARGIN_SEC=60
```

## 30. Definition of healthy

Sistem dianggap sehat jika seluruh kondisi berikut terpenuhi:

```text
system cron aktif dan hanya satu
artisan schedule:list dapat dibaca
Redis PING = PONG
Supervisor Horizon = RUNNING
artisan horizon:status = running
queued Run bergerak dalam SLA yang disepakati
tidak ada running Run melewati execution deadline
failure rate endpoint masih dalam batas normal
tidak ada error berulang di Laravel/Horizon log
MySQL backup dan Redis AOF berjalan
CPU, RAM, disk, dan inode berada dalam batas aman
```

## 31. Dokumen terkait

- [Arsitektur ringkas](architecture.md)
- [Runbook cutover Redis dan Horizon](redis-horizon-cutover-vps.md)
- [Deployment production VPS](deployment-vps.md)
- [Migrasi database existing](database-migration-vps.md)
- [Operations dan troubleshooting](operations.md)
- [Panduan pengguna](user-guide.md)
- [Instalasi development](installation.md)
