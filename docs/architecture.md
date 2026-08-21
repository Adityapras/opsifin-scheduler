# Arsitektur Opsifin Scheduler

Ini adalah satu-satunya dokumen arsitektur aktif. Sistem sengaja dibatasi pada
kebutuhan URL/job, cron, queue, status eksekusi, pause/resume, dan retry manual.

## Keputusan utama

| Area | Implementasi |
| --- | --- |
| Clock | Satu system cron menjalankan `artisan schedule:run` setiap menit |
| Jadwal dinamis | Dispatcher membaca `schedules.next_run_at` dari database |
| Eksekusi | Redis queue dengan Laravel Horizon yang dijaga Supervisor |
| Retry | Satu HTTP attempt; retry hanya manual dari UI |
| Downtime | Maksimal satu occurrence terbaru, tanpa replay backlog |
| Overlap | Satu running slot per schedule; occurrence lain menjadi skipped |
| Katalog legacy | Setiap file `crontab-legacy/jobs/*.sh` menjadi tepat satu task template |
| Variasi request | Definisi canonical di template, bukan runtime override per client |
| Production | VPS manual tanpa aaPanel |
| Development | WSL2 + aaPanel |

## Diagram runtime

```text
Linux/aaPanel cron
  artisan schedule:run setiap menit
              │
              ▼
Laravel Scheduler
  jobs:dispatch-due setiap menit
  cron:purge-runs pukul 03:00
              │
              ▼
DueScheduleDispatcher
  query due → row lock → create Run → advance next_run_at
              │
              ▼
Redis queue
              │
              ▼
Supervisor → Horizon → ExecuteRun → RunWorker → HttpExecutor
              │
              ▼
endpoint client → succeeded / failed
```

System cron tidak dibuat per job. Job baru cukup dibuat dan di-assign dari UI;
dispatcher yang sama otomatis membacanya dari database.

## Model domain

```text
clients 1 ───── * schedules * ───── 1 task_templates
                         │
                         └──── 1 ───── * runs
```

### Client

Menyimpan base URL, timezone, active state, dan credential sesuai nilai input.
Menonaktifkan client menghentikan seluruh assignment tanpa menghapus histori.

### Task Template

Menyimpan HTTP method, path, headers, body, connect timeout, dan request timeout.
Satu template dapat di-assign ke banyak client. Perubahan template memengaruhi
seluruh assignment-nya.

Untuk import legacy, folder `crontab-legacy/jobs/` adalah satu-satunya sumber
kebenaran. Nama file menjadi `key`, sedangkan curl di dalam file menjadi method,
path, headers, dan body. Script pada folder client hanya dipakai untuk membaca
assignment lama. Perbedaannya dicatat sebagai finding dan tidak membuat template
khusus client.

Placeholder yang didukung:

```text
{{client.code}}
{{client.username}}
{{client.secret}}
{{client.secret_key}}
{{run.scheduled_for}}
```

Alias lama `{{client.password}}` tetap dibaca untuk kompatibilitas.

### Schedule

Schedule menghubungkan client, template, dan satu waktu cron:

```text
client_id, task_template_id
cron_expression, timezone
is_enabled, next_run_at
queue, prevent_overlap, running_run_id
```

Kombinasi `client_id + task_template_id + cron_expression` unik. Karena itu satu
job canonical boleh memiliki beberapa timing untuk client yang sama tanpa
menggandakan task template.

`next_run_at` disimpan UTC dan di-index bersama `is_enabled`. Pause mengubah
`is_enabled=false` dan `next_run_at=null`; resume menghitung waktu berikutnya
dari sekarang.

`prevent_overlap=true` adalah padanan `flock -n` untuk schedule dinamis. Sistem
memakai atomic database slot `running_run_id`, sehingga aman untuk beberapa queue
worker dan tidak bergantung pada file lock lokal. Occurrence berikutnya dicatat
sebagai `skipped` bila run sebelumnya masih aktif.

### Run

```text
queued → running → succeeded
                 └→ failed
       └──────────→ skipped
```

Retry membuat Run baru dengan `source_run_id`; Run lama tidak ditimpa.

## Algoritma dispatcher

Setiap menit:

1. pulihkan Run running yang melewati execution deadline;
2. cari schedule enabled dan due dengan client/template aktif;
3. proses setiap schedule dalam transaction dan `SELECT ... FOR UPDATE`;
4. hitung occurrence cron terbaru yang tidak melebihi waktu sekarang;
5. hitung `next_run_at` berikutnya dari waktu sekarang;
6. bila `prevent_overlap` aktif, buat Run skipped jika running slot masih dipakai;
7. selain itu buat Run queued;
8. commit transaction, lalu publish payload ke Redis.

`materialization_key` unik mencegah occurrence yang sama dibuat dua kali.

## Algoritma worker

1. Atomic claim Run `queued → running`.
2. Isi worker dan execution deadline.
3. Bila overlap guard aktif, atomic claim `schedules.running_run_id` bila null.
4. Periksa ulang client, template, dan schedule.
5. Resolve URL, placeholder, Authorization, headers, dan body.
6. Kirim tepat satu HTTP request.
7. Simpan HTTP status, duration, response excerpt, atau error.
8. Redact credential sebelum data disimpan.
9. Lepas running slot pada blok `finally`.

## Operasi bulk

Job Templates:

- Assign all active clients;
- Assign selected clients;
- Remove from selected clients.

Schedules:

- Set cron in bulk;
- Pause selected;
- Resume selected.

Assignment idempotent. Pasangan yang sudah ada tidak dibuat ulang dan cron/state
lama tidak diubah diam-diam. Assignment baru default paused.

## Failure model

| Failure | Hasil |
| --- | --- |
| HTTP 4xx/5xx | Run failed; operator boleh Retry |
| Connection/timeout | Run failed; tidak ada automatic retry |
| Worker mati | Deadline recovery menandai failed dan melepas slot |
| Dispatcher downtime | Saat pulih hanya occurrence terbaru dibuat |
| State dipause setelah queue | Worker menyimpan skipped tanpa HTTP call |
| Previous run aktif | Occurrence berikutnya skipped |

## Queue timing invariant

```text
task timeout <= worker --timeout (1900)
worker --timeout < REDIS_QUEUE_RETRY_AFTER (2000)
Supervisor stopwaitsecs >= 2000
worker --tries=1
```

`RunDispatcher` mem-publish Redis secara eksplisit setelah transaction pembentukan
Run selesai. `jobs:reconcile-queued` memulihkan Run yang sudah commit tetapi belum
sempat mendapat payload Redis. Redis wajib memakai persistence AOF dan
`maxmemory-policy noeviction` agar payload queue tidak dieviction.

## Security boundary

- credential Client disimpan plaintext/as-is dan tidak bergantung pada `APP_KEY`;
- secret disembunyikan dari serialisasi model dan hanya dapat dilihat melalui
  form Administrator;
- inspect request, response, dan error melewati redaction;
- production wajib HTTPS, MySQL tidak dibuka ke internet, dan backup database
  harus diperlakukan sebagai data sensitif karena memuat credential plaintext;
- cron/worker berjalan sebagai service user, bukan root;
- viewer tidak boleh melakukan mutation.

## Source map

```text
routes/console.php
app/Console/Commands/DispatchDueJobsCommand.php
app/Services/Scheduling/DueScheduleDispatcher.php
app/Services/Scheduling/NextRunCalculator.php
app/Services/Scheduling/ScheduleManager.php
app/Services/Scheduling/RunDispatcher.php
app/Services/Scheduling/RunWorker.php
app/Jobs/ExecuteRun.php
app/Services/Execution/HttpExecutor.php
```

## Scope yang sengaja tidak ada

Tidak ada full catch-up, automatic HTTP retry, blackout, incident/alert engine,
runtime client override, attempt table, schedule
matrix, generated crontab, atau internal watchdog.
