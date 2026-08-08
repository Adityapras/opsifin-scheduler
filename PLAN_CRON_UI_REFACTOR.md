# Plan: UI & Refactor Cron Job Opsifin

**Tanggal:** 3 Agustus 2026
**Penyusun:** Aditya Prasetyo
**Status:** Draft untuk review

---

## 1. Ringkasan Eksekutif

Cron job produksi saat ini terdiri dari **236 entry aktif** di crontab yang mengeksekusi **478 file shell script** tersebar di 39 folder client. Setelah audit, terbukti bahwa 478 script tersebut sebenarnya hanya merupakan **~25 pola task yang identik**, dibedakan hanya oleh URL dan token per client.

Rekomendasi: bangun aplikasi Laravel + Filament sebagai *source of truth*, dengan database menggantikan seluruh file `.sh` dan `.conf`. Crontab tetap menjadi executor (di-generate otomatis dari DB), sehingga risiko migrasi rendah dan rollback mudah.

**Dampak yang diharapkan:**


| Metrik                      | Sekarang                   | Target                       |
| ----------------------------- | ---------------------------- | ------------------------------ |
| File shell script           | 478                        | 0                            |
| File config                 | 26`.conf` + 1 `env.sh`     | 0 (pindah ke DB terenkripsi) |
| Sumber kebenaran kredensial | 3 tempat, saling drift     | 1 tempat                     |
| Coverage flock              | 41 dari 236 (17%)          | 236 dari 236 (100%)          |
| Tambah client baru          | Copy 15+ file, edit manual | 1 form di UI                 |
| Riwayat eksekusi            | Log file, retensi 2 hari   | Tabel`runs`, queryable       |

---

## 2. Temuan Audit

### 2.1 Kondisi struktur saat ini

Ada dua pola eksekusi yang berjalan berdampingan:

**Pola A — Direct script per folder** (198 entry crontab aktif — 84%)

```
Api/gn/repost.sh
  → curl -H 'Authorization:Basic <token-hardcoded>' https://goldennusa.opsifin.com/apiv_g/api_repost
```

Satu file `.sh` berisi satu baris `curl`. Token ter-hardcode di dalam file. Ada 478 file seperti ini.

**Pola B — Gateway + jobs + configs** (38 entry crontab aktif — 16%)

```
crontab → gateway.sh <client> <task>
            → baca configs/<client>.conf (URL, user, password)
            → routing case → jobs/<task>.sh
            → curl dengan token digenerate dari config
```

Pola B jelas lebih baik: konfigurasi terpisah dari logika, ada logging terstruktur per client per tanggal, ada rotasi log. Tapi **baru 16% job aktif yang memakainya** — hanya 14 client, dan 4 di antaranya (`bravo`, `altorina`, `kiable`, `privela`) sudah sepenuhnya gateway tanpa folder script sama sekali. Migrasi ke pola B jelas terhenti di tengah jalan.

### 2.2 Duplikasi masif

Frekuensi nama file yang sama di seluruh folder client:


| Script                            | Jumlah salinan |
| ----------------------------------- | ---------------- |
| `repost.sh`                       | 35             |
| `updateStatusAutoPrintBilling.sh` | 32             |
| `update_balance_trx.sh`           | 31             |
| `updateTokenOpsigo.sh`            | 30             |
| `updateExpiredUser.sh`            | 30             |
| `postInvoice.sh`                  | 30             |
| `billingFile.sh`                  | 30             |
| `kill_process_timeout.sh`         | 28             |
| ...19 nama lainnya                | ~230           |

Verifikasi isi: `gn/repost.sh` dan `aladin/repost.sh` identik setelah URL dan token dinormalisasi — perbedaan hanya kutip tunggal vs ganda dan payload `{}` vs `{"person":{"name":"bob"}}`.

Artinya: **478 file = 25 template × 39 client**. Ini yang membuat maintenance mahal — mengubah satu perilaku (misal menambah `--max-time`) butuh sentuh 30+ file.

### 2.3 Bug aktif yang ditemukan

**BUG-1 — Routing gateway mengarah ke file yang tidak ada.**

`gateway.sh` baris routing:

```bash
"update_status_auto_print_billing")
    TARGET_SCRIPT="$BASE_DIR/jobs/update_status_auto_print_billing.sh"
```

Tapi file yang ada di `jobs/` bernama `update_status_print_billing.sh` (tanpa `auto_`). Akibatnya gateway jatuh ke branch:

```
CRITICAL: Script inti tidak ditemukan di ...
```

Saat ini kelima entry crontab yang memanggil task ini kebetulan sedang di-comment, jadi belum ada dampak produksi. Tapi begitu salah satunya diaktifkan, job akan diam-diam gagal — dan karena `gateway.sh` tetap keluar dengan exit code 0, tidak akan ada alert sama sekali. Ini bug laten yang harus diperbaiki sebelum ada yang meng-uncomment.

**BUG-2 — Job orphan.** `jobs/auto_mail_sp.sh` ada tapi tidak pernah dirouting di `gateway.sh`. Dead code, atau task yang lupa didaftarkan.

**BUG-3 — Drift kredensial.** Token untuk client `aladin` berbeda di dua tempat:

```
aladin/repost.sh   : Basic bXJhLW9wczE4OTokMnkkMTAk...  (user: mra-ops189)
opsifin_env.sh     : Basic cmVzdF9taXN0ZXJhbGFkaW46...   (user: rest_misteraladin)
```

Tidak jelas mana yang benar. `opsifin_env.sh` sendiri hampir tidak dipakai — hanya direferensikan oleh 2 file (`qa1/` dan `qa2/updateStatusAutoPrintBilling.sh`), sisanya masih hardcode.

**BUG-4 — Coverage flock hanya 17%.** Dari 236 job aktif, hanya **41** yang memakai `flock`. 195 sisanya berpotensi overlap. Yang paling berisiko adalah job berfrekuensi tinggi tanpa lock:

```cron
*/2 * * * * /home/ubuntu/cron/gn/updatePrintNoHardCopy.sh      # tanpa flock
*/6 * * * * /home/ubuntu/cron/gn/repost.sh                     # tanpa flock
*/7 * * * * /home/ubuntu/cron/gn/update_balance_trx.sh         # tanpa flock
```

Lebih buruk lagi: dari 478 script client, **tidak satu pun** memakai `--max-time` atau `--connect-timeout`. Satu endpoint yang menggantung akan menahan proses curl selamanya, dan tanpa flock, proses baru terus bertambah tiap interval sampai OOM. Kombinasi "tanpa flock + tanpa timeout" inilah akar masalah OOT yang Anda sebutkan.

**BUG-5 — Interval cron yang salah tafsir.** `*/50 * * * *` dan `*/59 * * * *` tidak berarti "setiap 50/59 menit". `*/50` berjalan di menit 0 dan 50 (jeda 50 menit lalu 10 menit). `*/59` berjalan di menit 0 dan 59 (jeda 59 menit lalu 1 menit). Kemungkinan besar bukan yang diinginkan.

### 2.4 Risiko keamanan

- Token Basic Auth plaintext di **538 file yang ter-track git** (`.sh` dan `.conf`).
- Password plaintext di `configs/*.conf`, contoh: `API_PASSWORD='<32 karakter acak, disunting>'`.
- Seluruhnya sudah masuk history git (`commit 8af5b46 push code`). Menghapus file saja tidak cukup — history perlu dibersihkan dan **semua token wajib dirotasi**.
- Tidak ada audit trail siapa mengubah crontab kapan.

### 2.5 Variasi yang harus tetap didukung

Refactor tidak boleh berasumsi semua client identik. Task `repost` saja punya 5 path berbeda:


| Path                      | Jumlah client |
| --------------------------- | --------------- |
| `/apiv_g/api_repost`      | 29            |
| `/Qa2/apiv_g/api_repost`  | 2             |
| `/apiv1/api_all`          | 1 (aladin)    |
| `/TX/apiv_g/api_repost`   | 1             |
| `/Dev5/apiv_g/api_repost` | 1             |

Kesimpulan desain: **template task menyediakan default, client boleh override** path, method, body, dan timeout.

---

## 3. Arsitektur Target

### 3.1 Prinsip

1. **Database adalah source of truth.** Crontab dan file config menjadi artefak turunan, bukan sumber.
2. **Cron OS tetap executor.** Tidak ada daemon scheduler baru — timing tetap ditangani cron yang sudah terbukti stabil bertahun-tahun.
3. **Satu binary runner.** Semua job dieksekusi lewat satu entry point `php artisan cron:run <client> <task>`, bukan 478 script.
4. **Flock wajib, tanpa pengecualian.** Di-generate otomatis, tidak bisa lupa.
5. **Migrasi bertahap.** Pola lama dan baru boleh jalan berdampingan sampai migrasi selesai.

### 3.2 Diagram alur

```
┌─────────────────────────────────────────────────────────┐
│  Filament UI (web)                                      │
│  Clients · Task Templates · Schedules · Runs · Secrets  │
└───────────────────────┬─────────────────────────────────┘
                        │ CRUD + approval
                        ▼
┌─────────────────────────────────────────────────────────┐
│  MySQL                                                  │
│  clients · task_templates · schedules · runs · alerts   │
└───────────────────────┬─────────────────────────────────┘
                        │ php artisan cron:render --apply
                        ▼
┌─────────────────────────────────────────────────────────┐
│  /etc/cron.d/opsifin  (generated, managed block)        │
│  */5 * * * * ubuntu flock -n /var/lock/opsifin/... \    │
│     php /opt/opsifin-cron/artisan cron:run gn repost    │
└───────────────────────┬─────────────────────────────────┘
                        │ cron daemon
                        ▼
┌─────────────────────────────────────────────────────────┐
│  Runner (artisan cron:run)                              │
│  1. Ambil schedule + client + template dari DB          │
│  2. Decrypt kredensial                                  │
│  3. HTTP call (Guzzle) dengan timeout & retry           │
│  4. Tulis baris ke tabel `runs` (status, durasi, body)  │
│  5. Trigger alert bila gagal / timeout                  │
└─────────────────────────────────────────────────────────┘
```

### 3.3 Data model

```
clients
  id, code, name, base_url, timezone, is_active,
  auth_type (basic|bearer|none), auth_username,
  auth_secret_encrypted, notes, created_at, updated_at

task_templates
  id, key, name, description,
  http_method, path_template, body_template (json),
  headers (json), default_timeout_sec, default_retries,
  is_active

client_task_overrides           -- menangani variasi §2.5
  id, client_id, task_template_id,
  path_override, body_override, timeout_override, headers_override

schedules
  id, client_id, task_template_id,
  cron_expression, timezone,
  lock_key, lock_timeout_sec,
  is_enabled, catchup_policy,
  last_run_at, next_run_at, created_by, updated_by

runs
  id, schedule_id, started_at, finished_at, duration_ms,
  status (success|failed|timeout|skipped_lock),
  http_status, response_excerpt, error_message, host

alert_rules
  id, scope (global|client|schedule), target_id,
  condition (on_failure|on_timeout|consecutive_failures|missed_run),
  threshold, channel (slack|email|telegram), destination, is_active

audit_logs
  id, user_id, action, entity_type, entity_id,
  before (json), after (json), created_at
```

Estimasi volume: 39 client, ~25 template, ~236 schedule (bisa tumbuh ke ~700 kalau semua kombinasi diaktifkan), `runs` ~500 ribu baris/bulan (perlu partisi bulanan atau purge 90 hari).

### 3.4 Format baris crontab yang di-generate

```cron
# BEGIN OPSIFIN-CRON MANAGED BLOCK — generated 2026-08-03T10:00:00+07:00
# schedule_id=142 client=gn task=repost
*/6 * * * * ubuntu /usr/bin/flock -n /var/lock/opsifin/gn.repost.lock \
  /usr/bin/php /opt/opsifin-cron/artisan cron:run 142 \
  >> /var/log/opsifin-cron/runner.log 2>&1
# END OPSIFIN-CRON MANAGED BLOCK
```

Poin penting:

- `flock -n` (non-blocking) → job kedua langsung keluar kalau yang pertama masih jalan. Runner mencatat `skipped_lock` supaya tetap terlihat di UI.
- Lock file di `/var/lock/opsifin/`, bukan `/tmp` — `/tmp` bisa dibersihkan systemd-tmpfiles saat lock sedang aktif.
- Untuk job yang tidak boleh di-skip melainkan diantre, pakai `flock -w <detik>`. Ini menjadi opsi per-schedule di UI.
- Deploy ke `/etc/cron.d/opsifin` (bukan `crontab -e`) → bisa version-controlled, punya kolom user, dan tidak menimpa crontab manual yang mungkin masih ada.
- Guard tambahan di runner: `timeout` di level Guzzle **dan** `max_execution_time` PHP, supaya proses tidak menggantung meski lock lolos.

### 3.5 Alur deploy crontab yang aman

```
php artisan cron:render            → tulis ke file staging + tampilkan diff
php artisan cron:render --validate → cek sintaks tiap baris via cron-expression parser
php artisan cron:render --apply    → backup file lama, tulis baru, reload cron
php artisan cron:rollback          → kembalikan backup terakhir
```

Setiap `--apply` mencatat siapa, kapan, dan diff-nya ke `audit_logs`. UI menampilkan tombol "Preview crontab" dan "Deploy" dengan konfirmasi diff.

---

## 4. Referensi Tools Open Source

### 4.1 Untuk dipakai membangun (stack Laravel — rekomendasi)


| Kebutuhan                  | Tool                                      | Lisensi  | Catatan                                                                                                                                          |
| ---------------------------- | ------------------------------------------- | ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| Admin UI                   | **Filament v5**                           | MIT      | CRUD, tabel, form, filter, action, multi-tenant. Memangkas ~70% waktu bikin UI. Sudah v5 per 2026.                                               |
| Parse & validasi cron      | **dragonmantank/cron-expression**         | MIT      | Sudah jadi dependency Laravel. Untuk hitung`next_run_at` dan validasi input UI.                                                                  |
| Editor cron di UI          | **cron-expression-input** / **cronstrue** | MIT      | Builder visual + terjemahan "setiap 6 menit" agar non-teknis bisa baca.                                                                          |
| HTTP client                | **Guzzle** (via `Http::` facade)          | MIT      | Timeout, retry, connect_timeout, logging.                                                                                                        |
| Enkripsi kredensial        | **Laravel Crypt** (AES-256-GCM)           | MIT      | Cukup untuk fase 1. Fase lanjut: lihat §4.2.                                                                                                    |
| Monitoring runtime         | **Laravel Pulse**                         | MIT      | Slow query, exception, throughput.                                                                                                               |
| Dead man's switch          | **Healthchecks.io** (self-host)           | BSD-3    | Job ping saat selesai; kalau tidak ada ping dalam grace period → alert. Menangkap kasus "cron mati total" yang tidak bisa dideteksi dari dalam. |
| Uptime & alert channel     | **Uptime Kuma**                           | MIT      | Push monitor, integrasi Slack/Telegram/email out-of-the-box.                                                                                     |
| Log aggregation (opsional) | **Grafana Loki + Promtail**               | AGPL-3   | Kalau ingin log searchable lintas server tanpa simpan body di MySQL.                                                                             |
| Metrik & dashboard         | **Prometheus + Grafana**                  | Apache-2 | Runner expose`/metrics`: durasi, success rate, lock contention.                                                                                  |

### 4.2 Untuk secrets management

Tiga opsi, urut dari paling ringan:


| Opsi                                                           | Effort | Kelebihan                                                        | Kekurangan                                     |
| ---------------------------------------------------------------- | -------- | ------------------------------------------------------------------ | ------------------------------------------------ |
| **Laravel Crypt + APP_KEY di systemd**                         | Rendah | Tidak ada infra baru, langsung jalan                             | Rotasi manual,`APP_KEY` tetap satu titik lemah |
| **SOPS + age**                                                 | Sedang | File config terenkripsi tetap bisa masuk git, cocok untuk GitOps | Perlu manajemen kunci, tidak ada UI            |
| **Infisical** (self-host, MIT) atau **HashiCorp Vault** (BUSL) | Tinggi | Rotasi otomatis, audit log, dynamic secrets, RBAC                | Satu servis lagi yang harus dijaga uptime-nya  |

**Rekomendasi:** mulai dari Laravel Crypt di fase 1 (sudah jauh lebih baik dari plaintext), evaluasi Infisical di fase 5 kalau jumlah client terus bertambah.

### 4.3 Alternatif full-product (kalau nanti berubah pikiran)

Dipertimbangkan tapi tidak dipilih. Dicatat di sini supaya keputusannya terdokumentasi:


| Tool         | Bahasa             | Kenapa menarik                                                  | Kenapa tidak dipilih                                                                                                 |
| -------------- | -------------------- | ----------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| **Cronicle** | Node.js, MIT       | Web UI matang, multi-server, live log viewer, retry, catch-up   | Tidak ada konsep matrix client × task — ~700 job harus di-provision satu per satu via API. Model data tidak cocok. |
| **Dkron**    | Go, LGPL-3         | Distributed, leader failover, HTTP executor, UI, job dependency | Sama seperti Cronicle soal matrix. Overkill untuk single server. Bagus kalau nanti scale ke cluster.                 |
| **Windmill** | Rust/TS, AGPL-3    | Script + flow + schedule + UI generator, sangat modern          | AGPL, kurva belajar tinggi, stack terpisah dari tim PHP.                                                             |
| **Rundeck**  | Java, Apache-2     | RBAC matang, node inventory, audit lengkap                      | Berat (JVM), UI dated, overkill.                                                                                     |
| **n8n**      | Node.js, fair-code | UI paling ramah non-teknis, HTTP node + cron trigger            | Lisensi fair-code (restriksi komersial), workflow-oriented bukan job-oriented.                                       |
| **Temporal** | Go, MIT            | Durable execution, retry, versioning kelas enterprise           | Jauh berlebihan untuk 236 HTTP call sederhana.                                                                       |

**Kesimpulan:** kebutuhan intinya adalah *matrix* — 39 client × 25 task, dengan template yang di-override per client. Tidak ada produk siap pakai yang memodelkan ini secara native, sedangkan logic eksekusinya sendiri sepele (satu HTTP call). Membangun sendiri dengan Filament lebih hemat daripada memaksa tool eksternal, sekaligus menyelesaikan masalah secrets dan audit sekalian.

---

## 5. Roadmap

### Fase 0 — Stabilisasi darurat (2–3 hari)

Dikerjakan lebih dulu, terpisah dari pembangunan aplikasi. Tanpa menunggu UI.

- [ ]  Perbaiki BUG-1: rename `jobs/update_status_print_billing.sh` → `update_status_auto_print_billing.sh`. 5 job langsung pulih.
- [ ]  Tambahkan `set -euo pipefail` dan propagasi exit code di `gateway.sh` — sekarang selalu keluar 0 sehingga kegagalan tak terlihat.
- [ ]  Tambahkan `flock -n` ke 195 job yang belum punya. Bisa lewat script sed satu kali. Prioritaskan yang `*/1`–`*/10`.
- [ ]  Tambahkan `--max-time 60 --connect-timeout 10` ke semua `curl` yang belum punya.
- [ ]  Pindahkan lock dari `/tmp` ke `/var/lock/opsifin/`.
- [ ]  Backup crontab produksi ke git (tanpa secret) sebagai baseline.
- [ ]  Perbaiki `*/50` dan `*/59` sesuai maksud sebenarnya (kemungkinan `0 */1 * * *` — perlu konfirmasi masa berlaku token).

**Kenapa duluan:** ini menghilangkan risiko OOM dan silent failure dalam hitungan hari, tanpa bergantung pada selesainya proyek UI.

### Fase 1 — Fondasi aplikasi (1–1.5 minggu)

- [ ]  Scaffold Laravel 12 + Filament v5, auth + role (admin / operator / viewer).
- [ ]  Migrasi & model: `clients`, `task_templates`, `client_task_overrides`, `schedules`, `runs`, `audit_logs`.
- [ ]  Enkripsi kolom kredensial via `Crypt` cast.
- [ ]  Seeder importer: parsing `opsifin_crontab` + `configs/*.conf` + 478 `.sh` → isi DB otomatis. Ini kritikal — jangan input manual 236 schedule.
- [ ]  Laporan rekonsiliasi importer: mana yang berhasil diparse, mana yang butuh review manual (misal drift token aladin).

### Fase 2 — Runner & render crontab (1 minggu)

- [ ]  `artisan cron:run {schedule}` — eksekusi HTTP, tulis `runs`, hormati timeout & retry.
- [ ]  `artisan cron:render` dengan mode `--validate`, `--apply`, dan `cron:rollback`.
- [ ]  Generator flock otomatis per schedule, mode `-n` (skip) atau `-w` (antre).
- [ ]  Dry-run mode: jalankan job dari UI tanpa efek, tampilkan request yang akan dikirim.
- [ ]  **Shadow run:** jalankan runner baru paralel dengan cron lama dalam mode read-only selama 3–5 hari, bandingkan hasilnya. Belum ganti apa pun di produksi.

### Fase 3 — UI operasional (1–1.5 minggu)

- [ ]  Halaman Clients: CRUD, test koneksi, toggle aktif.
- [ ]  Halaman Task Templates: editor path/method/body, preview curl yang dihasilkan.
- [ ]  Halaman Schedules: **tampilan matrix** client × task dengan toggle per sel — ini fitur utama yang membuat pengelolaan 700 kombinasi jadi mungkin.
- [ ]  Cron expression builder + preview "5 jadwal berikutnya" dalam timezone Asia/Jakarta.
- [ ]  Halaman Runs: filter per client/task/status/rentang waktu, detail request-response.
- [ ]  Tombol "Run now" dengan tetap menghormati lock.
- [ ]  Bulk action: enable/disable per client atau per task lintas semua client.
- [ ]  Preview diff crontab sebelum deploy + tombol rollback.

### Fase 4 — Alerting & observability (3–5 hari)

- [ ]  Alert rule engine: on_failure, on_timeout, N kegagalan berturut-turut, missed run.
- [ ]  Channel Slack + email (Telegram opsional).
- [ ]  Integrasi Healthchecks.io self-host sebagai dead man's switch di luar aplikasi.
- [ ]  Dashboard: success rate per client, job terlambat, top slowest, lock contention.
- [ ]  Retensi `runs`: purge otomatis > 90 hari, arsip ke object storage bila perlu.

### Fase 5 — Migrasi & pembersihan (1–2 minggu, bertahap)

- [ ]  Migrasi per gelombang: mulai dari client non-produksi (`demo`, `qa1`, `qa2`, `dev5`), lalu client kecil, terakhir client besar (`gn`, `aladin`, `anta`).
- [ ]  Setiap gelombang: aktifkan di sistem baru → nonaktifkan (comment) di crontab lama → monitor 48 jam → hapus.
- [ ]  **Rotasi seluruh token** setelah semua client migrasi. Wajib, karena semua token sudah bocor di git history.
- [ ]  Bersihkan git history (`git filter-repo` atau BFG) untuk menghapus jejak secret.
- [ ]  Arsipkan 478 `.sh` dan 26 `.conf` ke branch `legacy/`, hapus dari `main`.
- [ ]  Dokumentasi runbook: cara menambah client baru, cara menambah task baru, prosedur incident.

**Total estimasi:** 5–7 minggu untuk satu developer, atau 3–4 minggu untuk dua orang (UI dan runner bisa paralel setelah Fase 1).

---

## 6. Strategi Migrasi & Rollback

Prinsip: **sistem lama tidak dimatikan sampai sistem baru terbukti**.

```
Minggu 1-2   [lama aktif]  [baru: belum ada]
Minggu 3-4   [lama aktif]  [baru: shadow run, read-only, dibandingkan]
Minggu 5     [lama aktif]  [baru: gelombang 1 — client QA/demo saja]
Minggu 6     [lama sebagian] [baru: gelombang 2-3 — client kecil]
Minggu 7     [lama mati]   [baru: semua client]  → rotasi token
```

Rollback di titik mana pun: `php artisan cron:rollback` mengembalikan `/etc/cron.d/opsifin` ke versi sebelumnya, dan crontab lama masih ada (hanya di-comment, tidak dihapus) sampai Fase 5 selesai.

---

## 7. Risiko & Mitigasi


| Risiko                                          | Dampak                              | Mitigasi                                                                                                            |
| ------------------------------------------------- | ------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| Importer salah parse salah satu dari 478 script | Job hilang / salah target           | Laporan rekonsiliasi wajib direview manual; shadow run 3–5 hari sebelum cutover                                    |
| Drift token (BUG-3) menyebar ke DB              | Job gagal auth setelah migrasi      | Verifikasi setiap kredensial dengan test-connection sebelum diaktifkan                                              |
| PHP bootstrap ~200ms per invocation × 236 job  | Beban CPU naik                      | Terukur kecil (~50 detik CPU/jam total). Kalau jadi masalah, ganti runner ke binary Go tipis yang baca DB yang sama |
| Aplikasi UI down                                | Tidak bisa ubah jadwal              | Crontab tetap jalan independen — UI down tidak menghentikan job. Ini keuntungan memilih cron sebagai executor      |
| MySQL down saat runner jalan                    | Job gagal & tidak tercatat          | Runner fallback: tulis ke file lokal, sinkron ke DB saat pulih                                                      |
| Token bocor di git history                      | Akses tidak sah ke 39 sistem client | Rotasi total wajib di Fase 5 — perlakukan semua token saat ini sebagai sudah terkompromi                           |
| Tabel`runs` membengkak                          | Disk penuh, query lambat            | Partisi bulanan + purge 90 hari sejak awal, jangan ditunda                                                          |

---

## 8. Keputusan yang Perlu Dikonfirmasi

1. **Server target** — apakah aplikasi UI ditaruh di server cron yang sama, atau server terpisah? Kalau terpisah, `cron:render --apply` butuh SSH/agent.
2. **Multi-server** — apakah ke depan cron akan tersebar di lebih dari satu server? Kalau ya, desain `lock_key` perlu naik ke distributed lock (Redis/DB) sejak Fase 2.
3. **Otoritas deploy** — apakah perubahan jadwal langsung apply, atau perlu approval dua tahap (maker–checker)?
4. **Tipe task selain HTTP** — hasil audit: semua 478 script client murni `curl`, tidak ada yang menjalankan perintah shell lain. Jadi model "http task" saja sudah cukup untuk Fase 1. Perlu konfirmasi apakah ke depan akan ada job non-HTTP (misal backup DB, sync file lokal), supaya tipe "shell command" bisa disiapkan sejak awal atau ditunda.
5. **Nasib `etc/cron/`** — banyak file `.bak` dan `.save` di sana. Masih dipakai atau bisa diarsipkan?
6. **Interval `*/50` dan `*/59`** — dipakai untuk `update_token_bca` dan `updateTokenOpsigo`. Karena ini refresh token, jeda tidak konsisten (59 menit lalu 1 menit) berpotensi bikin token expired di celah tertentu. Perlu konfirmasi masa berlaku token sebenarnya.

---

## Lampiran A — Inventaris Task Type

Task yang sudah dirouting di `gateway.sh` (19):

`repost`, `auto_mail_credit_limit`, `billing_file`, `clear_inactive_temp_issued`, `clear_shared_folder_opsigo`, `e_invoice`, `kill_process_timeout`, `post_invoice_to_opsigo`, `recurring`, `request_bca_api`, `repost_bca_api`, `update_balance_trx`, `update_expired_user`, `update_print_no_hardcopy`, `update_status_auto_print_billing`, `kcic_email`, `repost_error`, `auto_billing`, `auto_summary`

Task yang ada di folder client tapi **belum** dirouting gateway (perlu ditambahkan sebagai template):

`updateTokenOpsigo` (30 salinan), `update_token_bca` (10), `draftBilling` (10), `generateBillingFile` (2), `postInvoiceVoid` (2), `auto_mail_sp` (22 — file ada di `jobs/` tapi tidak dirouting, lihat BUG-2), `syncFileToOpsifinKai` (1), `downloadFtpToS3Kai` (1), `update_balance` (1), `kill_process` (2)

Semuanya adalah HTTP POST/GET biasa, jadi cukup ditambahkan sebagai baris di `task_templates`.

## Lampiran B — Distribusi Job Gateway yang Aktif

Hanya baris crontab yang benar-benar aktif (tidak di-comment). Total 38 entry.


| Task type                  | Entry aktif |
| ---------------------------- | ------------- |
| auto_billing               | 7           |
| update_print_no_hardcopy   | 4           |
| update_balance_trx         | 4           |
| repost                     | 4           |
| e_invoice                  | 4           |
| update_expired_user        | 3           |
| recurring                  | 3           |
| clear_shared_folder_opsigo | 3           |
| clear_inactive_temp_issued | 3           |
| repost_error               | 2           |
| kcic_email                 | 1           |


| Client                                              | Entry aktif     |
| ----------------------------------------------------- | ----------------- |
| bravo                                               | 8               |
| privela                                             | 7               |
| altorina                                            | 7               |
| kiable                                              | 4               |
| jontru, anta                                        | 2 masing-masing |
| rsp, mytours, mtt, mnc, gns, gnj, globalwisata, agi | 1 masing-masing |

**Catatan penting untuk migrasi:** `bravo`, `altorina`, `kiable`, dan `privela` tidak punya folder script sama sekali — mereka murni gateway + config. Keempatnya adalah bukti bahwa pola gateway sudah terbukti jalan di produksi, dan **paling mudah dimigrasi ke sistem baru** karena datanya sudah terstruktur di `configs/*.conf`. Jadikan mereka gelombang pertama setelah client QA/demo.

Sebaliknya, 26 task type yang tersisa (`recurring` 28 kali, dll. di Lampiran A) mayoritas masih berjalan lewat pola direct script — di situlah 198 entry aktif dan 478 file berada, dan di situ pula sebagian besar pekerjaan migrasi terletak.
