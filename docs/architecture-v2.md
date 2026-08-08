# Arsitektur v2 — Platform Penjadwalan Job

**Status:** usulan desain, belum diimplementasikan
**Menggantikan:** [`architecture.md`](architecture.md) (sistem yang berjalan sekarang)
**Tanggal:** 5 Agustus 2026

Dokumen ini mendesain ulang sistem dari nol dengan empat sasaran yang diminta:
**reliable, mudah di-maintain, fleksibel, dan user friendly.** Sistem yang ada
sekarang dipakai sebagai bahan pelajaran, bukan sebagai batasan.

---

## Daftar isi

1. [Ringkasan keputusan](#1-ringkasan-keputusan)
2. [Konsep inti: occurrence](#2-konsep-inti-occurrence)
3. [Komponen](#3-komponen)
4. [Skema database](#4-skema-database)
5. [Algoritma ticker](#5-algoritma-ticker)
6. [Algoritma worker](#6-algoritma-worker)
7. [Algoritma watchdog](#7-algoritma-watchdog)
8. [Kebijakan per schedule](#8-kebijakan-per-schedule)
9. [Executor](#9-executor)
10. [Incident & alerting](#10-incident--alerting)
11. [Model kegagalan](#11-model-kegagalan)
12. [Antarmuka pengguna](#12-antarmuka-pengguna)
13. [Keamanan](#13-keamanan)
14. [Topologi & operasi](#14-topologi--operasi)
15. [Observability](#15-observability)
16. [Urutan implementasi](#16-urutan-implementasi)
17. [Yang dipertahankan dari sistem sekarang](#17-yang-dipertahankan-dari-sistem-sekarang)
18. [Catatan keputusan](#18-catatan-keputusan)
19. [Batas yang disadari](#19-batas-yang-disadari)

---

## 1. Ringkasan keputusan

| Keputusan | Pilihan | Konsekuensi utama |
| --- | --- | --- |
| Unit data | **Occurrence**, bukan eksekusi | "Tidak pernah jalan" punya baris sendiri |
| Pemicu | **Dua baris crontab statis** | Tidak ada file yang di-generate, tidak ada deploy |
| Dispatch ↔ eksekusi | **Dipisah lewat queue** | Worker boleh mati tanpa kehilangan pekerjaan |
| Anti-duplikat | **`UNIQUE (schedule_id, scheduled_for)`** | Ticker boleh jalan dua kali dengan aman |
| Anti-tumpang tindih | **Compare-and-set di kolom `running_run_id`** | Tidak ada lock file yang bisa bocor |
| Retry | **Mati secara default**, dibuka per task | Task keuangan tidak pernah dieksekusi ganda diam-diam |
| Pengawas | **Watchdog di baris crontab terpisah** | Kegagalan ticker tidak ikut mematikan alarmnya |
| Pemantau luar | **Wajib** | Sistem tidak bisa melaporkan kematiannya sendiri |

Yang **tidak ada** di desain ini, dan itu disengaja: file generation, langkah
deploy, rollback file, `flock`, dan kebutuhan akses root.

---

## 2. Konsep inti: occurrence

Tiga tingkat, dan pemisahannya adalah inti seluruh desain:

```
schedule                occurrence (run)              attempt
"definisi"              "satu titik waktu"            "satu percobaan"

gn / repost       ┌──► 2026-08-05 14:06  succeeded ──► #1  200 OK      124 ms
*/6 * * * *       ├──► 2026-08-05 14:12  failed    ──► #1  500        2.1 s
Asia/Jakarta      │                                 └──► #2  500        1.9 s
                  ├──► 2026-08-05 14:18  lost      ──► #1  (menggantung)
                  └──► 2026-08-05 14:24  pending   ──► (belum ada)
```

Baris `lost` dan `pending` itulah yang tidak punya representasi di desain
berbasis-eksekusi. Karena occurrence dibuat **sebelum** dieksekusi:

- Job yang tidak pernah jalan tetap terlihat — tanpa mekanisme deteksi khusus.
- Antrean yang menumpuk terlihat sebagai `pending` yang menua.
- Proses yang mati di tengah jalan terlihat sebagai `running` yang kedaluwarsa.
- Riwayat lengkap: berapa occurrence yang seharusnya terjadi vs yang berhasil.

Satu occurrence bisa punya beberapa attempt. Attempt-lah yang menyimpan detail
request–response; occurrence menyimpan kesimpulannya.

---

## 3. Komponen

```
                    ┌─────────────────────────────────────────┐
   cron OS          │ TICKER            cron:tick, tiap menit │
   2 baris statis   │                                          │
        │           │ 1. heartbeat mulai                       │
        ├──────────►│ 2. baca schedule yang runnable           │
        │           │ 3. hitung occurrence jatuh tempo          │
        │           │ 4. INSERT run (idempoten)                │
        │           │ 5. dispatch ke queue                     │
        │           │ 6. heartbeat selesai                     │
        │           └──────────────────┬───────────────────────┘
        │                              ▼
        │                     ┌──────────────────┐
        │                     │      QUEUE       │  redis / database
        │                     └────────┬─────────┘
        │                              ▼
        │           ┌─────────────────────────────────────────┐
        │           │ WORKER            queue:work, systemd   │
        │           │                                          │
        │           │ 1. claim run  (pending → running)         │
        │           │ 2. rebut slot schedule (compare-and-set) │
        │           │ 3. cek blackout                          │
        │           │ 4. resolve request                       │
        │           │ 5. Executor.execute() + catat attempt    │
        │           │ 6. lepas slot, simpan kesimpulan         │
        │           │ 7. nilai alert rule                      │
        │           └──────────────────────────────────────────┘
        │
        │           ┌─────────────────────────────────────────┐
        └──────────►│ WATCHDOG      cron:watchdog, tiap 5 mnt │
                    │                                          │
                    │ · heartbeat ticker basi?                 │
                    │ · run pending menua? → worker mati        │
                    │ · run running kedaluwarsa? → tandai lost │
                    │ · kirim heartbeat ke pemantau luar       │
                    └──────────────────────────────────────────┘
```

Tanggung jawab yang **tidak** boleh dicampur:

| Komponen | Boleh | Tidak boleh |
| --- | --- | --- |
| Ticker | Baca schedule, tulis occurrence | Memanggil endpoint apa pun |
| Worker | Eksekusi, tulis attempt | Menentukan kapan sesuatu jatuh tempo |
| Watchdog | Membaca & menandai yang mandek | Mengeksekusi ulang secara otomatis |

Ticker tidak pernah memanggil jaringan, jadi tidak pernah menggantung. Itu yang
membuat pemicunya bisa dipercaya.

---

## 4. Skema database

### 4.1 Definisi

**`clients`** — satu sistem tujuan.

| Kolom | Catatan |
| --- | --- |
| `code` | Pendek, dipakai di nama slot & incident |
| `name`, `base_url`, `timezone` | |
| `is_active` | Saklar induk client |
| `auth_type`, `auth_username`, `auth_secret`, `auth_secret_key` | Terenkripsi |
| `notes`, `needs_review`, `review_notes` | |

**`task_templates`** — definisi pekerjaan, dipakai bersama banyak client.

| Kolom | Catatan |
| --- | --- |
| `key`, `name`, `description` | |
| `executor` | `http` \| `shell` \| `artisan` |
| `config` (JSON) | Isi tergantung executor. Untuk `http`: method, path, body, headers |
| `timeout_sec`, `connect_timeout_sec` | Wajib |
| **`is_idempotent`** | **Gerbang yang mengizinkan retry sama sekali** |
| `max_attempts`, `retry_backoff_sec` | Diabaikan bila `is_idempotent = false` |
| `is_active` | Saklar induk task |

**`client_task_overrides`** — penyimpangan satu client dari template.

| Kolom | Catatan |
| --- | --- |
| `client_id`, `task_template_id` | Unik berpasangan |
| `config_override` (JSON) | Digabung di atas `task_templates.config` |
| `timeout_override`, `connect_timeout_override` | |
| `notes` | |

**`schedules`** — kombinasi client × task + kapan + kebijakan.

| Kolom | Catatan |
| --- | --- |
| `client_id`, `task_template_id` | |
| `cron_expression`, `timezone` | Timezone **per schedule**, tidak harus seragam |
| `is_enabled` | |
| `overlap_policy` | `skip` \| `queue` \| `allow` |
| `catchup_policy` | `none` \| `latest` \| `all` |
| `catchup_max` | Batas atas occurrence susulan, default 50 |
| `queue` | Nama antrean, untuk memisahkan task lambat |
| `priority` | |
| **`last_materialized_at`** | Sampai kapan occurrence sudah dibuat |
| **`running_run_id`** | Slot eksekusi. `NULL` = bebas |
| `created_by`, `updated_by` | |

`UNIQUE (client_id, task_template_id, cron_expression)`

### 4.2 Eksekusi

**`runs`** — satu occurrence. Inti seluruh sistem.

| Kolom | Catatan |
| --- | --- |
| `schedule_id`, `client_id`, `task_template_id` | Dua terakhir didenormalisasi |
| **`scheduled_for`** | Titik waktu terjadwal, disimpan UTC |
| `status` | Lihat tabel di bawah |
| `trigger` | `schedule` \| `manual` \| `backfill` \| `retry` |
| `queued_at`, `started_at`, `finished_at`, `duration_ms` | |
| `attempts_count` | |
| `http_status`, `error_message` | Ringkasan attempt terakhir |
| `worker` | Host/PID yang mengerjakannya |

`UNIQUE (schedule_id, scheduled_for)` ← **satu baris ini menggantikan seluruh urusan lock**

Indeks: `(status, scheduled_for)`, `(client_id, scheduled_for)`,
`(schedule_id, scheduled_for DESC)`

Status:

| Status | Arti |
| --- | --- |
| `pending` | Occurrence dibuat, menunggu worker |
| `running` | Worker sedang mengerjakan |
| `succeeded` | Selesai baik |
| `failed` | Selesai gagal, semua attempt habis |
| `lost` | Menggantung melewati batas — **hasilnya tidak diketahui** |
| `skipped_overlap` | Occurrence sebelumnya masih jalan, kebijakan `skip` |
| `skipped_blackout` | Jatuh di dalam jendela blackout |
| `skipped_disabled` | Schedule/client/task dimatikan setelah occurrence dibuat |
| `cancelled` | Dibatalkan manusia |

**`run_attempts`** — satu percobaan eksekusi.

| Kolom | Catatan |
| --- | --- |
| `run_id`, `attempt_no` | Unik berpasangan |
| `started_at`, `finished_at`, `duration_ms` | |
| `request_method`, `request_url` | Kredensial **tidak** disimpan |
| `http_status`, `response_excerpt` | Excerpt dipotong |
| `error_class`, `error_message` | |

Baris attempt ditulis **sebelum** panggilan dilakukan. Kalau worker mati di
tengah jalan, tetap ada jejak bahwa panggilan sudah berangkat — itulah yang
membedakan `lost` dari `pending`.

### 4.3 Pendukung

**`blackout_windows`** — jendela larangan jalan.

| Kolom | Catatan |
| --- | --- |
| `scope` | `global` \| `client` \| `task` \| `schedule` |
| `client_id`, `task_template_id`, `schedule_id` | Nullable sesuai scope |
| `starts_at`, `ends_at` | |
| `reason` | Muncul di UI sebagai alasan skip |

**`incidents`** — kegagalan berulang yang sudah dikelompokkan.

| Kolom | Catatan |
| --- | --- |
| **`fingerprint`** | Hash dari (schedule, kondisi). Kunci pengelompokan |
| `status` | `open` \| `acknowledged` \| `resolved` |
| `condition`, `title`, `body` | |
| `first_seen_at`, `last_seen_at`, `occurrence_count` | |
| `client_id`, `task_template_id`, `schedule_id` | |
| `acknowledged_by`, `acknowledged_at`, `resolved_at` | |

Indeks unik parsial: satu `fingerprint` hanya boleh punya satu baris yang belum
`resolved`. Inilah yang mengubah 200 kegagalan menjadi 1 baris dengan
`occurrence_count = 200`.

**`alert_rules`** — kapan incident dibuka. Cakupan bertingkat seperti sekarang
(global / client / task / schedule), kondisi: `on_failure`, `on_timeout`,
`consecutive_failures`, `missed_occurrence`, `run_lost`, `queue_backlog`.

**`ticker_state`** — satu baris tunggal.

| Kolom |
| --- |
| `last_tick_started_at`, `last_tick_finished_at`, `duration_ms` |
| `schedules_scanned`, `runs_created` |
| `last_error` |

**`audit_logs`** — siapa mengubah apa. Wajib mencatat perubahan
`cron_expression`, `is_enabled`, kredensial, dan kebijakan.

---

## 5. Algoritma ticker

Dijalankan `cron:tick` tiap menit. Harus **idempoten** dan **tidak pernah
memanggil jaringan**.

```
tickerState.last_tick_started_at = now()

FOR EACH schedule WHERE is_runnable:          -- lihat §8.1
    window_start = schedule.last_materialized_at ?? (now - catchup_window)
    window_end   = now

    occurrences = cronExpression.between(window_start, window_end, schedule.timezone)
                  -- eksklusif di awal, inklusif di akhir

    SWITCH schedule.catchup_policy:
        none   → occurrences = []
        latest → occurrences = [last(occurrences)]
        all    → occurrences = first(occurrences, schedule.catchup_max)
                 IF dipotong → buka incident "catch-up dipotong"

    FOR EACH occ IN occurrences:
        IF di dalam blackout window:
            INSERT run(status = skipped_blackout, scheduled_for = occ)  -- IGNORE bila duplikat
            CONTINUE

        inserted = INSERT INTO runs (schedule_id, scheduled_for, status='pending', ...)
                   ON CONFLICT DO NOTHING

        IF inserted:
            dispatch ke queue schedule.queue, membawa run.id

    schedule.last_materialized_at = window_end

tickerState.last_tick_finished_at = now()
```

Tiga sifat penting:

1. **Aman dijalankan dua kali.** Insert kedua ditolak oleh unique constraint.
   Ticker boleh tumpang tindih, boleh dijalankan manual, boleh jalan di dua
   mesin.
2. **`last_materialized_at` membuat catch-up terdefinisi.** Tanpa penunjuk ini,
   ticker yang mati semalam tidak punya cara tahu apa yang terlewat — dan
   menghitung dari awal waktu bukan pilihan.
3. **Blackout menghasilkan baris.** Occurrence yang dilewati tetap terlihat,
   lengkap dengan alasannya. Tidak ada kekosongan yang harus ditebak.

Ticker menulis satu occurrence per (schedule, waktu) — bukan satu per menit.
Untuk 236 schedule aktif, satu tick biasanya membuat 0–60 baris.

---

## 6. Algoritma worker

```
run = SELECT ... WHERE id = :run_id

-- 1. Claim: hanya satu worker yang boleh melanjutkan
affected = UPDATE runs SET status='running', started_at=now(), worker=:me
           WHERE id = :run_id AND status = 'pending'
IF affected = 0: RETURN            -- sudah diambil worker lain

-- 2. Rebut slot schedule (compare-and-set, tanpa transaksi)
IF schedule.overlap_policy != 'allow':
    affected = UPDATE schedules SET running_run_id = :run_id
               WHERE id = :schedule_id AND running_run_id IS NULL
    IF affected = 0:
        SWITCH overlap_policy:
            skip  → run.status = 'skipped_overlap'; RETURN
            queue → lepas claim (status kembali 'pending'); release ke queue + delay; RETURN

-- 3. Gerbang yang bisa berubah setelah occurrence dibuat
IF NOT schedule.is_runnable:  run.status = 'skipped_disabled'; goto RELEASE
IF sekarang di dalam blackout: run.status = 'skipped_blackout'; goto RELEASE

-- 4. Susun request final
request = Executor.resolve(task_template, override, client)

-- 5. Eksekusi
max = task.is_idempotent ? task.max_attempts : 1

FOR attempt_no IN 1..max:
    INSERT run_attempts(run_id, attempt_no, started_at=now())    -- SEBELUM memanggil
    result = Executor.execute(request)
    UPDATE run_attempts SET finished_at, duration_ms, http_status, ...

    IF result.success:            run.status='succeeded'; BREAK
    IF NOT result.retryable:      run.status='failed';    BREAK
    IF attempt_no = max:          run.status='failed';    BREAK
    sleep(backoff(attempt_no))

RELEASE:
    UPDATE schedules SET running_run_id = NULL WHERE running_run_id = :run_id
    run.finished_at, duration_ms, attempts_count, ringkasan hasil
    AlertEvaluator.evaluate(run)
```

Catatan desain:

- **Claim dua tingkat.** Tingkat pertama (`pending → running`) mencegah dua
  worker mengerjakan occurrence yang sama. Tingkat kedua (slot schedule)
  mencegah dua occurrence berbeda dari schedule yang sama berjalan bersamaan.
  Keduanya `UPDATE ... WHERE`, atomik, tanpa transaksi eksplisit.
- **Slot dilepas di satu tempat** (`RELEASE`), termasuk pada jalur gagal. Kalau
  worker mati sebelum sampai situ, watchdog yang melepaskannya (§7).
- **`retryable`** ditentukan executor: timeout dan 5xx boleh diulang, 4xx tidak
  — mengulang request yang ditolak karena salah tidak akan berubah hasilnya.
- **Retry hanya hidup kalau `is_idempotent`.** Ini bukan default yang bisa
  kelewat: `max = 1` untuk task yang tidak menyatakan dirinya idempoten.

---

## 7. Algoritma watchdog

Dijalankan `cron:watchdog` tiap 5 menit dari **baris crontab sendiri**, bukan
dari dalam ticker. Kalau ticker mati, watchdog harus tetap hidup untuk
melaporkannya.

```
-- 1. Ticker mandek
IF tickerState.last_tick_finished_at < now - 3 menit:
    buka incident "scheduler stalled"

-- 2. Worker tidak mengonsumsi
backlog = COUNT runs WHERE status='pending' AND queued_at < now - 5 menit
IF backlog > ambang:
    buka incident "queue backlog"

-- 3. Run menggantung
FOR EACH run WHERE status='running'
             AND started_at < now - (task.timeout_sec * attempts + margin):
    run.status = 'lost'
    UPDATE schedules SET running_run_id = NULL WHERE running_run_id = run.id
    buka incident "run lost"          -- TIDAK diulang otomatis

-- 4. Occurrence yang tidak pernah dibuat
FOR EACH schedule WHERE is_runnable
                  AND last_materialized_at < now - (2 × interval terpendeknya):
    buka incident "schedule not materialised"

-- 5. Kirim heartbeat ke pemantau luar
ping(EXTERNAL_HEARTBEAT_URL)
```

Poin ketiga adalah inti kejujuran sistem ini: run yang `lost` **tidak pernah
dieksekusi ulang otomatis**. Sistem tidak tahu apakah request-nya sudah sampai
ke endpoint. Yang dilakukan: tandai, munculkan, sediakan tombol *Retry* untuk
manusia yang sudah memeriksa.

---

## 8. Kebijakan per schedule

### 8.1 Gerbang: `is_runnable`

```
schedules.is_enabled AND clients.is_active AND task_templates.is_active
```

Tiga toggle tetap ada karena tiga-tiganya berguna. Yang berubah: di UI
ditampilkan sebagai **satu status turunan**, bukan tiga kotak centang yang harus
dicek satu per satu.

| Tampilan | Arti |
| --- | --- |
| `Running` | Ketiganya aktif |
| `Paused — schedule` | Schedule dimatikan |
| `Paused — client` | Client dimatikan |
| `Paused — task` | Template dimatikan |
| `Blackout until 06:00` | Sedang di dalam jendela blackout |

### 8.2 Overlap

| Kebijakan | Perilaku | Cocok untuk |
| --- | --- | --- |
| `skip` | Lewati kalau yang lama masih jalan | **Default.** Hampir semua job |
| `queue` | Antre sampai slot bebas | Job yang setiap occurrence-nya harus terjadi |
| `allow` | Jalan bersamaan | Job read-only yang benar-benar independen |

### 8.3 Catch-up

Apa yang terjadi kalau sistem mati 2 jam lalu hidup lagi?

| Kebijakan | Perilaku | Cocok untuk |
| --- | --- | --- |
| `none` | Lupakan yang terlewat | Polling — jalan berikutnya sudah cukup |
| `latest` | Jalankan satu kali saja | **Default.** Sinkronisasi keadaan |
| `all` | Jalankan semua yang terlewat, sampai `catchup_max` | Pemrosesan batch per periode |

Ini pertanyaan yang selalu muncul saat insiden, dan hampir selalu dijawab
dadakan. Menaruhnya sebagai kolom memaksa jawabannya diputuskan lebih dulu.

### 8.4 Idempotensi & retry

| `is_idempotent` | `max_attempts` | Hasilnya |
| --- | --- | --- |
| `false` (default) | diabaikan | Satu percobaan. Gagal → `failed`, menggantung → `lost` |
| `true` | 3 | Diulang dengan backoff pada timeout/5xx |

Form task template menampilkan peringatan eksplisit saat flag ini dinyalakan.
Untuk task seperti `repost` atau `settlement`, jawabannya hampir selalu `false`.

---

## 9. Executor

Satu antarmuka, banyak implementasi. Inilah sumber fleksibilitasnya.

```php
interface Executor
{
    /** Susun perintah final dari template + override + client. */
    public function resolve(TaskTemplate $t, ?Override $o, Client $c): ResolvedRequest;

    /** Jalankan. Tidak pernah melempar untuk kegagalan yang diharapkan. */
    public function execute(ResolvedRequest $r): ExecutionResult;

    /** Ringkasan siap tampil untuk dry run. */
    public function describe(ResolvedRequest $r, bool $revealSecrets): string;
}
```

`ExecutionResult`: `success`, `retryable`, `status_code`, `output_excerpt`,
`error_class`, `error_message`, `duration_ms`.

| Executor | `config` berisi | Kegunaan |
| --- | --- | --- |
| `http` | method, path, body, headers | Kasus sekarang — panggil endpoint client |
| `shell` | command, working dir, env | Backup, housekeeping di server |
| `artisan` | command, arguments | Memicu perintah aplikasi lain |
| `webhook` | url, secret, algoritma signing | Integrasi pihak ketiga |

Menambah jenis pekerjaan = menambah satu kelas. Ticker, worker, watchdog, UI,
dan alerting tidak berubah sama sekali.

**Placeholder** di `config` diisi saat resolve: `{{client.code}}`,
`{{client.username}}`, `{{client.secret_key}}`, `{{run.scheduled_for}}`.

---

## 10. Incident & alerting

### Kenapa incident, bukan alert per kejadian

Job yang gagal tiap 6 menit menghasilkan 240 kegagalan per hari. Sebagai alert
satuan itu banjir yang akan diabaikan orang dalam dua hari. Sebagai incident:
**satu baris**, `occurrence_count: 240`, `first_seen`, `last_seen`.

```
alert_rule cocok
      │
      ▼
fingerprint = hash(schedule_id, condition)
      │
      ├── sudah ada incident open dengan fingerprint ini?
      │      → occurrence_count++, last_seen_at = now
      │      → kirim notifikasi HANYA bila melewati cooldown
      │
      └── belum ada?
             → buat incident baru, kirim notifikasi
```

Cooldown tetap ada, tapi perannya berbeda: **membatasi notifikasi**, bukan
membatasi pencatatan. Semua kejadian tetap terhitung.

### Siklus hidup

```
open ──[acknowledge]──► acknowledged ──[resolve]──► resolved
  └────────────────[resolve]─────────────────────────┘
```

Incident yang `resolved` lalu terjadi lagi membuka **incident baru** — supaya
"sudah berapa kali kambuh" terbaca dari riwayat.

### Kanal

Satu antarmuka `NotificationChannel` dengan implementasi in-app, Slack, email,
Telegram. Rule menentukan *kapan*, channel menentukan *ke mana*. Keduanya tidak
saling tahu.

---

## 11. Model kegagalan

| Yang mati | Yang terjadi | Yang mendeteksi | Pulih sendiri? |
| --- | --- | --- | --- |
| Satu worker | Pekerjaan diambil worker lain | — | Ya |
| Semua worker | `pending` menumpuk, tidak hilang | Watchdog §7.2 | Ya, saat worker hidup |
| Worker mati saat eksekusi | Run jadi `lost`, slot dilepas | Watchdog §7.3 | **Tidak — sengaja** |
| Ticker | Occurrence tidak dibuat | Watchdog §7.1 | Ya, dengan catch-up |
| Watchdog | Alarm senyap | **Pemantau luar** | — |
| Database | Semuanya berhenti | Pemantau luar | Ya |
| Endpoint client | `failed`, retry bila diizinkan | Alert rule | Tergantung endpoint |
| VPS | Semuanya berhenti | Pemantau luar | — |

Dua baris yang perlu digarisbawahi:

- **Run yang `lost` tidak pulih sendiri.** Itu keputusan, bukan kekurangan.
- **Watchdog dan pemantau luar tidak saling menggantikan.** Watchdog mengawasi
  komponen dari dalam; pemantau luar mengawasi bahwa mesinnya masih hidup.

---

## 12. Antarmuka pengguna

### Matrix — pandangan armada

Grid client × task, satu sel = satu schedule. Warna dari status turunan §8.1,
bukan dari `is_enabled` mentah. Hover menampilkan cron, occurrence berikutnya,
dan hasil terakhir. Aksi massal per baris dan per kolom.

### Timeline schedule — pandangan satu job

Ini yang menggantikan tabel log mentah:

```
gn / repost   */6 * * * *   Asia/Jakarta

14:00 ██  14:06 ██  14:12 ██  14:18 ▓▓  14:24 ██  14:30 ░░  14:36 ██
                              lost        skip
      ██ succeeded   ▓▓ lost   ░░ skipped   ██ failed
```

Satu occurrence satu blok. Kekosongan terlihat sebagai kekosongan. Klik satu
blok → daftar attempt-nya.

### Editor jadwal

- **Preset dulu**: "tiap N menit", "harian jam HH:MM", "hari kerja", "tiap jam"
- **Mode lanjutan** untuk ekspresi cron mentah
- Selalu tampilkan **5 occurrence berikutnya** dalam timezone schedule
- Peringatan untuk pola menjebak: `*/7` yang jedanya tidak seragam
- Perubahan **berlaku dalam satu menit** — tidak ada langkah deploy

### Yang bisa dilakukan tanpa SSH

| Aksi | Efek |
| --- | --- |
| **Run now** | Membuat occurrence `trigger=manual` seketika |
| **Dry run** | Menampilkan request final tanpa memanggilnya |
| **Retry** | Membuat occurrence `trigger=retry` dari run yang gagal/lost |
| **Backfill** | Membuat occurrence untuk rentang waktu lampau |
| **Pause / resume** | Per schedule, per client, atau per task |
| **Blackout** | Jendela larangan jalan, dengan alasan |

### Riwayat perubahan

Setiap perubahan cron, status, kredensial, dan kebijakan tercatat: siapa, kapan,
dari apa ke apa. Pertanyaan pertama saat insiden selalu "kemarin ada yang
diubah?" — dan jawabannya harus ada di layar yang sama.

---

## 13. Keamanan

| Aspek | Keputusan |
| --- | --- |
| Kredensial | Terenkripsi di kolom. Tidak pernah masuk `run_attempts`, log, atau notifikasi |
| Menampilkan kredensial | Hanya di form edit client, hanya untuk role yang boleh mengubahnya |
| Dry run | Menyamarkan kredensial bila hasilnya disimpan; menampilkan penuh hanya di layar, hanya untuk yang berwenang |
| Role | `viewer` (baca + dry run) → `operator` (pause, run, retry, tangani incident) → `admin` (data master, kebijakan, user) |
| Audit | Wajib untuk perubahan jadwal, status, kredensial, kebijakan |
| Akses jaringan | Panel di balik TLS. Tidak terbuka ke publik |
| Rotasi kredensial | Alur khusus: ubah → tes koneksi → simpan. Tanpa tes koneksi lolos, simpan diberi peringatan |

---

## 14. Topologi & operasi

### Satu mesin (titik mulai)

```
nginx + php-fpm          → UI
systemd: 2–4 queue worker → eksekusi
cron: 2 baris             → ticker + watchdog
MySQL                     → satu-satunya state
Redis (opsional)          → queue lebih cepat
pemantau eksternal        → wajib
```

```
* * * * *   cd /opt/opsifin-cron && php artisan cron:tick
*/5 * * * * cd /opt/opsifin-cron && php artisan cron:watchdog
```

Dua baris itu tidak pernah berubah lagi seumur hidup sistem.

Worker sebagai unit systemd:

```ini
[Service]
User=opsifin
Restart=always
RestartSec=3
ExecStart=/usr/bin/php /opt/opsifin-cron/artisan queue:work \
          --queue=high,default,slow --sleep=1 --tries=1 --max-time=3600
```

`--tries=1` disengaja: retry dikelola aplikasi lewat `run_attempts` supaya
terlihat di UI, bukan diam-diam oleh queue.

### Bertumbuh

| Kebutuhan | Yang diubah |
| --- | --- |
| Throughput lebih tinggi | Tambah worker; pindah queue ke Redis |
| Isolasi task lambat | Antrean terpisah + worker khusus |
| Worker di mesin lain | Tidak ada perubahan kode — worker hanya butuh DB & queue |
| Ticker HA | Jalankan di dua mesin; unique constraint sudah menjaga |

### Rilis

```bash
git pull
composer install --no-dev -o
npm ci && npm run build
php artisan migrate --force
php artisan queue:restart        # worker mengambil kode baru dengan bersih
systemctl reload php8.3-fpm
```

`queue:restart` membuat worker menyelesaikan job yang sedang jalan lalu keluar,
dan systemd menghidupkannya kembali dengan kode baru. Tidak ada job yang
terpotong di tengah.

---

## 15. Observability

Angka yang harus terbaca tanpa membuka database:

| Metrik | Kenapa penting |
| --- | --- |
| Umur heartbeat ticker | Detak jantung seluruh sistem |
| Kedalaman & umur antrean | Worker mengejar atau tertinggal |
| Occurrence per status, 24 jam | Kesehatan menyeluruh |
| Success rate per client | Mana yang bermasalah |
| p50 / p95 durasi per task | Deteksi pelambatan sebelum jadi timeout |
| Jumlah `lost` | Harus nol. Bukan nol = ada yang perlu diputuskan manusia |
| `skipped_overlap` per schedule | Eksekusi lebih lambat dari intervalnya |
| Incident terbuka | Yang belum dipegang siapa pun |

Dua yang paling sering dilupakan dan paling berguna: **umur heartbeat** dan
**`skipped_overlap`**. Yang pertama menjawab "apakah sistemnya hidup", yang
kedua memperingatkan jauh sebelum ada yang benar-benar gagal.

---

## 16. Urutan implementasi

Tiap tahap menghasilkan sistem yang jalan, bukan potongan.

| Tahap | Isi | Perkiraan |
| --- | --- | --- |
| **1. Inti** | Skema, ticker, worker, `runs` + `run_attempts`, HttpExecutor, claim & slot | 4–5 hari |
| **2. Pengawasan** | Watchdog, `ticker_state`, incident + fingerprint, notifikasi in-app | 2–3 hari |
| **3. UI** | Matrix, timeline, editor jadwal, dry run, run now, retry | 4–5 hari |
| **4. Kebijakan** | Overlap, catch-up, blackout, idempotensi + retry | 2 hari |
| **5. Operasi** | Kanal alert keluar, backfill, riwayat perubahan, retensi | 2–3 hari |
| **6. Migrasi** | Impor data dari sistem sekarang, cutover per client | 2–3 hari |

**Total ±3–4 minggu** untuk satu orang.

Tahap 1 sudah bisa menjalankan job sungguhan. Tahap 2 membuatnya layak
dipercaya. Sisanya membuatnya nyaman dipakai.

---

## 17. Yang dipertahankan dari sistem sekarang

Tidak semuanya dibuang. Yang paling mahal justru bisa dipakai ulang:

| Bagian | Nasib | Alasan |
| --- | --- | --- |
| Importer legacy (`LegacyImport/`) | **Pakai apa adanya** | 476 script sudah terurai dan terverifikasi. Ini aset termahal |
| Model `clients` / `task_templates` / `overrides` | **Pakai, sedikit tambah kolom** | Abstraksi template × tenant sudah benar |
| `CronDescriber` | **Pakai apa adanya** | |
| `ConnectionTester` | **Pakai apa adanya** | |
| Logika HTTP di `JobRunner` | **Jadi `HttpExecutor`** | Isinya sudah matang: timeout, mask header, excerpt |
| UI Clients & Task templates | **Pakai, sesuaikan field baru** | |
| Matrix | **Pakai, ubah semantik sel** | Warna dari status turunan, bukan `is_enabled` |
| `alert_rules` + evaluator | **Rombak jadi incident** | Konsep cakupan & cooldown dipertahankan |
| `runs` | **Ganti** | Kehilangan `scheduled_for` dan unique constraint |
| `CrontabRenderer` / `Deployer` | **Hapus** | Tidak ada lagi file yang di-generate |
| `cron:render` / `cron:rollback` | **Hapus** | |
| Halaman Deploy crontab | **Hapus** | |
| `JobLock` (flock) | **Hapus** | Digantikan slot compare-and-set |

Kira-kira **60% kode sekarang tetap terpakai**. Yang dibongkar adalah lapisan
eksekusi dan penjadwalan — dan itu memang lapisan yang selama ini paling banyak
menimbulkan aturan khusus.

### Migrasi datanya

Skema client/task/override nyaris tidak berubah, jadi datanya berpindah dengan
`INSERT ... SELECT`. `schedules` bertambah kolom kebijakan dengan default aman
(`overlap=skip`, `catchup=latest`, `is_idempotent=false`). `runs` lama
diarsipkan apa adanya — tidak perlu dikonversi, karena riwayat lama tidak punya
`scheduled_for` yang bisa dipercaya.

---

## 18. Catatan keputusan

### K-1 — Occurrence sebagai unit data

**Alternatif:** menyimpan hanya eksekusi yang benar-benar terjadi.
**Dipilih occurrence** karena "seharusnya jalan tapi tidak" adalah keadaan yang
harus bisa ditanyakan ke database. Tanpa ini, deteksi job mati selalu jadi
lapisan tambalan yang terpisah dari data.
**Biaya:** baris lebih banyak, termasuk untuk occurrence yang di-skip. Ditutup
oleh retensi.

### K-2 — Queue di antara ticker dan eksekusi

**Alternatif:** ticker langsung mengeksekusi.
**Dipilih queue** karena memisahkan komponen cepat-dan-kritis dari komponen
lambat-dan-rawan. Ticker yang memanggil endpoint akan menggantung, dan pemicu
yang bisa menggantung tidak bisa dipercaya.
**Biaya:** satu komponen lagi yang harus diawasi. Ditutup oleh watchdog §7.2.

### K-3 — Unique constraint, bukan lock file

**Alternatif:** `flock` seperti sekarang.
**Dipilih unique constraint** karena menjawab pertanyaan yang berbeda dan lebih
tepat: bukan "apakah ada yang sedang jalan" tapi "apakah titik waktu ini sudah
ditangani". Ini yang membuat ticker aman dijalankan ulang.
**Catatan:** anti-tumpang-tindih tetap perlu, dan dijawab slot
`running_run_id` — juga di database, juga atomik, tanpa file yang bisa bocor.

### K-4 — Retry mati secara default

**Alternatif:** retry menyala dengan `max_attempts = 3`.
**Dipilih mati** karena job ini memindahkan uang. Request yang timeout mungkin
sudah diproses endpoint; mengulanginya adalah cara membuat transaksi ganda.
**Biaya:** lebih banyak yang butuh perhatian manusia. Itu memang yang
diinginkan untuk kelas pekerjaan ini.

### K-5 — Dua baris crontab, bukan file yang di-generate

**Alternatif:** generate `/etc/cron.d` seperti sekarang.
**Dipilih dua baris statis** karena file yang di-generate menciptakan keadaan
kedua yang bisa berbeda dari database — beserta seluruh perkakas untuk
mengelolanya: render, validasi, diff, backup, rollback, izin root.
**Yang hilang:** sysadmin tidak bisa lagi membaca jadwal dari `cat`. Digantikan
`php artisan schedule:describe` dan UI.

### K-6 — Watchdog di baris crontab terpisah

**Alternatif:** watchdog sebagai task di dalam ticker.
**Dipilih terpisah** karena pengawas yang mati bersama yang diawasinya tidak
mengawasi apa pun.
**Biaya:** satu baris crontab. Murah.

---

## 19. Batas yang disadari

Hal-hal yang **tidak** dijanjikan desain ini, dan sebaiknya tidak dijanjikan ke
siapa pun:

1. **Bukan exactly-once.** Yang dijamin: dispatch tepat sekali, dan eksekusi
   paling banyak sekali untuk task non-idempoten. Kalau endpoint memproses
   request yang koneksinya lalu putus, sistem tidak akan pernah tahu.

2. **Granularitas satu menit.** Ticker berdetak tiap menit. Butuh sub-menit?
   Ganti ticker jadi daemon berdurasi 5–10 detik — komponen lain tidak berubah.

3. **Tidak bisa melaporkan kematiannya sendiri.** Pemantau di luar mesin wajib.
   Tidak ada desain yang bisa menghindari ini.

4. **Belum ada ketergantungan antar job.** "Jalankan B setelah A berhasil" tidak
   dimodelkan. Bisa ditambahkan sebagai `depends_on_schedule_id` + pemeriksaan
   di worker, tapi jangan dibangun sebelum ada yang benar-benar membutuhkannya.

5. **Satu database sebagai titik pusat.** Kalau MySQL mati, semuanya berhenti.
   Untuk skala ini itu pertukaran yang benar; HA database adalah masalah yang
   berbeda dan jauh lebih mahal.

6. **Catch-up `all` bisa mengejutkan.** Sistem mati sehari untuk job tiap 6
   menit = 240 occurrence susulan. Karena itu `catchup_max` ada, defaultnya
   `latest`, dan pemotongan membuka incident.

---

## Ringkasan satu paragraf

Occurrence dibuat lebih dulu oleh ticker yang tidak pernah menyentuh jaringan,
dijaga tunggal oleh satu unique constraint, dikerjakan worker yang boleh mati
kapan saja, diawasi watchdog di jalur terpisah, dan tidak pernah diulang
diam-diam kalau hasilnya tidak diketahui. Semua kebijakan yang biasanya jadi
keputusan dadakan saat insiden — tumpang tindih, susulan, pengulangan, jendela
larangan — dijadikan kolom yang harus diisi sejak awal. Sisanya adalah UI yang
membuat semua itu terbaca.
