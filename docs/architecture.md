# Arsitektur & Alur Aplikasi

Dokumen ini menjelaskan cara kerja sistem dari ujung ke ujung. Kalau Anda baru
pertama kali membuka repo ini, baca dokumen ini lebih dulu sebelum yang lain.

> **Catatan:** dokumen ini menjelaskan sistem **yang berjalan sekarang**. Ada
> usulan desain ulang di [`architecture-v2.md`](architecture-v2.md) yang
> mengganti file crontab hasil generate dengan ticker + queue + watchdog.
> Usulan itu belum diimplementasikan.

---

## 1. Gagasan pokoknya

Sistem lama menyimpan kebenaran di **file**: 478 script `.sh`, 26 file `.conf`,
dan satu `opsifin_crontab`. Menambah client berarti menyalin belasan file;
mengganti token berarti menyunting di tiga tempat yang gampang drift.

Sistem baru memindahkan kebenaran ke **database**. Yang tidak berubah: **cron OS
tetap yang mengeksekusi**. Aplikasi ini tidak punya daemon penjadwal sendiri.

```
  ┌──────────────┐   render    ┌──────────────────┐   trigger   ┌────────────┐
  │   DATABASE   │ ──────────► │  /etc/cron.d/    │ ──────────► │ cron OS    │
  │ source of    │             │  opsifin         │             │ daemon     │
  │ truth        │ ◄────────── │  (file generated)│             └─────┬──────┘
  └──────────────┘   catat     └──────────────────┘                   │
        ▲                                                             │
        │                      php artisan cron:run <id>              │
        └─────────────────────────────────────────────────────────────┘
```

Alasan cron OS dipertahankan: risikonya nol. Kalau aplikasi mati, job yang sudah
ter-deploy tetap jalan. Kalau aplikasi salah, file crontab-nya bisa di-rollback
dalam satu perintah.

---

## 2. Peta komponen

| Lapisan | Isi | Letak |
| --- | --- | --- |
| **Importer** | Membaca repo cron legacy sekali saat migrasi | `app/Services/LegacyImport/` |
| **Renderer** | Menyusun isi file `cron.d` dari tabel `schedules` | `app/Services/Crontab/CrontabRenderer.php` |
| **Deployer** | Menulis file itu dengan backup + audit | `app/Services/Crontab/CrontabDeployer.php` |
| **Runner** | Mengeksekusi satu schedule, mencatat hasilnya | `app/Services/Runner/JobRunner.php` |
| **Lock** | Mencegah eksekusi menumpuk | `app/Services/Runner/JobLock.php` |
| **Alerting** | Menilai hasil, menerbitkan alert | `app/Services/Alerting/` |
| **UI** | Panel admin Filament | `app/Filament/` |

---

## 3. Tiga gerbang

Konsep paling penting untuk dipahami. **Sebuah schedule ikut di-render ke crontab
hanya kalau ketiganya benar:**

```
schedules.is_enabled  AND  clients.is_active  AND  task_templates.is_active
```

Sumbernya `CrontabRenderer::enabledSchedules()`. Karena berlapis:

- Mematikan **satu client** mematikan seluruh task-nya sekaligus — inilah saklar
  induk yang dipakai saat migrasi bertahap.
- Mematikan **satu template** mematikan satu jenis task di semua client.
- Mematikan **satu schedule** hanya memengaruhi satu kombinasi.

Dan yang selalu berlaku: **perubahan di database tidak berlaku sampai crontab
di-deploy.** Menekan toggle di panel hanya mengubah baris tabel.

---

## 4. Alur impor legacy (sekali saja, saat migrasi)

```
CRON_SOURCE_PATH (read-only)
   │
   ├── opsifin_env.sh ────────► variabel & kredensial global
   ├── configs/*.conf ────────► kredensial per client
   ├── gateway.sh ────────────► tabel routing task → file job
   ├── jobs/*.sh ─────────────► definisi endpoint gateway  (Pola B)
   ├── <client>/*.sh ─────────► satu ParsedCurl per script (Pola A)
   └── opsifin_crontab ───────► jadwal + status aktif/comment
                │
                ▼
      ┌───────────────────────────────────────────────┐
      │ Script dikelompokkan per nama.                │
      │ Grup dengan endpoint identik → satu template. │
      │ Yang menyimpang → client_task_overrides.      │
      └───────────────────────────────────────────────┘
                │
                ▼
   clients · task_templates · client_task_overrides · schedules
                │
                ▼
        import_findings ──► laporan rekonsiliasi (markdown)
```

**Prinsip yang dipegang importer: tidak pernah menebak diam-diam.** Setiap
ketidakcocokan — kredensial berbeda antar sumber, host tidak cocok, routing
menunjuk file yang tidak ada — dicatat sebagai `import_findings` dengan severity
`error`/`warning`/`info`, lalu muncul di laporan rekonsiliasi yang wajib dibaca
manusia sebelum client mana pun diaktifkan.

Importer **tidak pernah menulis** ke repo legacy. Mode `--dry-run` membungkus
semuanya dalam transaksi yang di-rollback, jadi bisa dijalankan berulang kali.

Verifikasi baliknya: `cron:verify-import` membaca ulang script `.sh` asli dan
membandingkannya dengan request yang tersimpan di database — URL, method, body,
Authorization, SecretKey. Angka *Different* yang tidak nol berarti ada yang perlu
ditelusuri.

---

## 5. Alur render & deploy

```
   tabel schedules
        │  enabledSchedules()  ← tiga gerbang
        ▼
   ┌─────────────────────────────────────────────┐
   │ CrontabRenderer::validate()                 │
   │  · ekspresi cron valid?                     │
   │  · lock_key ada & aman untuk nama file?     │
   │  · timezone seragam? (satu CRON_TZ)         │
   │  · URL hasil resolve valid?                 │
   │  · kredensial lengkap?                      │
   └─────────────────────────────────────────────┘
        │ bersih                    │ ada masalah
        ▼                           ▼
   render()                    deploy DIKUNCI
        │
        ▼
   ┌─────────────────────────────────────────────┐
   │ merge() ke isi file yang sudah ada          │
   │  · hanya blok BEGIN…END yang diganti        │
   │  · baris manual di luar blok tidak tersentuh│
   └─────────────────────────────────────────────┘
        │
        ▼
   backup file lama → tulis file baru → catat audit_logs
```

Isi blok yang dihasilkan:

```
# BEGIN OPSIFIN-CRON MANAGED BLOCK — generated 2026-08-04T…
# Generated automatically by `php artisan cron:render`. DO NOT edit by hand —
# changes are overwritten on the next deploy. Source of truth: the `schedules` table.

CRON_TZ=Asia/Jakarta
SHELL=/bin/bash
PATH=/usr/local/sbin:…

# ── gn — Golden Nusa
# schedule_id=12 client=gn task=repost tz=Asia/Jakarta lock=gn.repost
*/6 * * * * ubuntu /usr/bin/flock -n '/var/lock/opsifin/gn.repost.cron.lock' \
  /usr/bin/php '/opt/opsifin-cron/artisan' cron:run 12 >> /var/log/opsifin-cron/runner.log 2>&1
# END OPSIFIN-CRON MANAGED BLOCK
```

Dua hal penting di sini:

- **`CRON_TZ`** ditulis hanya kalau seluruh schedule aktif memakai timezone yang
  sama. Satu file `cron.d` cuma bisa punya satu, jadi timezone campuran ditolak
  saat validasi — kalau tidak, sebagian job diam-diam jalan di jam yang salah.
- **Marker BEGIN/END** membuat crontab lama boleh hidup berdampingan di file yang
  sama selama masa migrasi.

---

## 6. Alur eksekusi satu job

```
cron daemon
   │
   ▼
flock -n <lock_key>.cron.lock      ← LAPIS 1, di baris crontab
   │  gagal ambil → keluar diam-diam, tidak ada baris di runs
   ▼
php artisan cron:run <schedule_id>
   │
   ▼
JobRunner::run()
   │
   ├─ schedule nonaktif?  → catat skipped_disabled, selesai
   ├─ client nonaktif?    → catat skipped_disabled, selesai
   │
   ├─ resolveRequest()   ← template + override client digabung jadi satu request
   │
   ├─ dry run?           → catat ringkasan request, TIDAK memanggil endpoint
   │
   ▼
JobLock::acquire()                 ← LAPIS 2, di dalam runner
   │  gagal ambil → catat skipped_lock (TERLIHAT di UI), selesai
   ▼
HTTP call  (timeout, connect timeout, retries dari template/override)
   │
   ▼
catat ke runs: status, http_status, durasi, potongan body, pesan error
   │
   ├─ perbarui schedule.last_run_at & next_run_at
   └─ AlertEvaluator::evaluateRun()   ← dibungkus try/catch, tidak boleh
                                        menjatuhkan eksekusi job
```

### Kenapa lock-nya dua lapis, dan pakai file berbeda

| | Lapis 1 — `flock` di crontab | Lapis 2 — di dalam runner |
| --- | --- | --- |
| File | `<lock_key>.cron.lock` | `<lock_key>.lock` |
| Mencegah | Proses PHP menumpuk saat runner menggantung | Dua eksekusi job yang sama tumpang tindih |
| Biaya | Murah, tanpa bootstrap Laravel | Perlu Laravel jalan |
| Terlihat di UI | Tidak | Ya, sebagai `skipped_lock` di tabel `runs` |
| Berlaku untuk "Run now" | Tidak | Ya |

Kalau keduanya memakai file yang sama, runner akan bentrok dengan `flock`
induknya sendiri dan tidak pernah bisa mengambil lock. Karena itu file-nya
sengaja dipisah.

### Bagaimana request dibentuk

```
task_template  (default untuk semua client)
      +
client_task_override  (kalau ada, per client × task)
      +
client  (base_url, kredensial)
      ▼
  request final
```

Header per-client memakai placeholder yang diisi runner saat eksekusi:
`{{client.secret_key}}`, `{{client.username}}`, `{{client.code}}`.
`Authorization` disusun otomatis dari `auth_type` + kredensial client.

---

## 7. Alur alerting

Dua pemicu yang sifatnya berbeda, dan perbedaan ini penting:

```
┌─ Job GAGAL ─────────────────────────────────────────────┐
│  Ada baris di tabel `runs` yang statusnya bermasalah.    │
│  JobRunner memanggil AlertEvaluator::evaluateRun()       │
│  setiap kali sebuah run selesai.                         │
│  Kondisi: on_failure, on_timeout, consecutive_failures   │
└─────────────────────────────────────────────────────────┘

┌─ Job TIDAK JALAN SAMA SEKALI ───────────────────────────┐
│  TIDAK ADA baris apa pun di `runs` — hanya kekosongan.   │
│  Tidak mungkin terdeteksi dari data run.                 │
│  Penjadwal Laravel memanggil cron:check-missed tiap      │
│  5 menit → AlertEvaluator::evaluateMissedRuns()          │
│  Kondisi: missed_run                                     │
└─────────────────────────────────────────────────────────┘
```

Deteksi missed run bekerja begini: untuk tiap schedule yang lolos tiga gerbang,
hitung jadwal terakhir yang seharusnya sudah lewat (`Schedule::previousRun()`).
Kalau waktu itu ditambah `grace_minutes` sudah terlampaui, sementara
`last_run_at` masih lebih tua dari jadwal tersebut (atau belum pernah jalan
sama sekali), berarti eksekusinya tidak terjadi.

**Penjadwal Laravel sengaja terpisah dari crontab yang di-generate aplikasi.**
Kalau keduanya dijadwalkan di tempat yang sama, satu kegagalan akan mematikan
job sekaligus alarmnya. Itulah sebabnya perlu satu baris terpisah di crontab
server:

```
* * * * * cd /opt/opsifin-cron && php artisan schedule:run >> /dev/null 2>&1
```

### Cakupan rule dan cooldown

Rule tanpa client/task/schedule berlaku global; mengisi salah satunya
mempersempit sasaran. Beberapa rule boleh cocok untuk satu run yang sama —
masing-masing menerbitkan alert sendiri.

**Cooldown** menjaga satu masalah menghasilkan satu notifikasi. Tanpa itu, job
yang gagal tiap 6 menit akan menerbitkan alert tiap 6 menit.

Penerbitannya lewat satu pintu, `AlertDispatcher::fire()`, yang mengecek cooldown
lalu menulis baris `alerts` dan mengirim notifikasi. Saat ini alert hanya
mengendap di aplikasi (tabel + lonceng notifikasi Filament). Channel keluar —
Slack, email, Telegram — cukup ditambahkan di `AlertDispatcher::deliver()` tanpa
menyentuh evaluator maupun rule.

> **Batas yang perlu disadari:** `cron:check-missed` berjalan **di dalam** VPS
> yang sama. Kalau seluruh VPS mati, tidak ada yang melapor. Untuk itu tetap
> perlu pemantau di luar server (mis. Healthchecks.io) — belum dipasang.

---

## 8. Model data

```
clients ──┬── schedules ──── runs ──── alerts
          │       │                      │
          │       └──────────────────────┤
          ├── client_task_overrides ──┐  │
          │                           │  │
task_templates ─────────────────────────┴──┘
          │
      alert_rules  (cakupan: client / task / schedule / global)

import_runs ──── import_findings          audit_logs
```

| Tabel | Peran |
| --- | --- |
| `clients` | Satu sistem tujuan. Base URL + kredensial terenkripsi. |
| `task_templates` | Definisi request yang dipakai bersama banyak client. |
| `client_task_overrides` | Penyimpangan satu client dari template. |
| `schedules` | Kombinasi client × task + ekspresi cron + lock. Inilah yang dirender. |
| `runs` | Riwayat eksekusi. Read-only, hanya runner yang menulis. |
| `alert_rules` | Kapan alert berbunyi. |
| `alerts` | Alert yang sudah terbit, dengan status open/acknowledged/resolved. |
| `import_runs` / `import_findings` | Jejak impor legacy dan temuan yang perlu diputuskan. |
| `audit_logs` | Jejak deploy dan rollback crontab. |

Beberapa keputusan desain yang tidak terlihat dari nama kolom:

- **Kredensial** (`auth_secret`, `auth_secret_key`) di-cast `encrypted` — tidak
  pernah tersimpan plaintext, tidak pernah ditulis ke file atau ke crontab.
- **`runs` didenormalisasi** (`client_id`, `task_template_id` ikut disalin) supaya
  riwayat tetap terbaca dan bisa difilter walau schedule-nya sudah dihapus.
- **Kolom `legacy_*`** menyimpan asal-usul tiap baris — dipakai
  `cron:verify-import` dan penelusuran saat migrasi. Boleh diabaikan setelah
  migrasi tuntas.
- **`next_run_at` disimpan dalam timezone aplikasi (UTC)**, lalu dikonversi ke
  timezone schedule saat ditampilkan.

---

## 9. Peran pengguna

| Role | Boleh |
| --- | --- |
| **viewer** | Membaca semuanya. Dry run (tidak memanggil endpoint). |
| **operator** | Semua di atas, plus enable/disable schedule, jalankan job manual, acknowledge/resolve alert. |
| **admin** | Semua di atas, plus CRUD data master (client, task template, alert rule) dan deploy crontab. |

Aturannya ada di `app/Policies/`. Yang perlu diingat: **kredensial hanya terlihat
di form edit client, dan form itu hanya bisa dibuka admin.** Riwayat dry run yang
tersimpan di tabel `runs` selalu menyamarkan kredensial, karena tabel itu bisa
dibaca semua user aktif.

---

## 10. Apa yang berjalan di mana

| Proses | Dijalankan oleh | Frekuensi |
| --- | --- | --- |
| `cron:run <id>` | cron OS, dari `/etc/cron.d/opsifin` | Sesuai ekspresi tiap schedule |
| `cron:check-missed` | Penjadwal Laravel | Tiap 5 menit |
| `cron:purge-runs` | Penjadwal Laravel | Tiap hari 03:15 |
| `schedule:run` | crontab user aplikasi | Tiap menit |
| `cron:render --apply` | Manusia, lewat SSH | Setiap kali ada perubahan schedule |
| `cron:import` | Manusia | Sekali saat migrasi |

---

## Selanjutnya

- Memasang dari nol di VPS → [`installation.md`](installation.md)
- Memakai panelnya → [`user-guide.md`](user-guide.md)
- Migrasi bertahap dari crontab lama → [`cutover.md`](cutover.md)
- Prosedur harian & incident → [`operations.md`](operations.md)
- Daftar kolom, perintah, dan konfigurasi → [`reference.md`](reference.md)
