# Runbook Operasional — Opsifin Cron

Prosedur harian setelah aplikasi berjalan. Untuk pemasangan awal dan cutover
bertahap, lihat `docs/cutover.md`.

---

## Aturan yang berlaku di semua prosedur

**Perubahan di database tidak berlaku sampai crontab di-deploy.** Menyalakan
schedule di panel hanya mengubah baris di tabel; daemon cron baru tahu setelah
`cron:render --apply`. Setiap prosedur di bawah yang menyentuh schedule selalu
diakhiri deploy.

**Tiga gerbang.** Sebuah schedule berjalan hanya kalau `schedules.is_enabled`,
`clients.is_active`, dan `task_templates.is_active` ketiganya aktif.

---

## 1. Menambah client baru

1. **Clients → New client.**

| Field | Isi |
| --- | --- |
| Code | Huruf, angka, titik, strip. Dipakai di lock key dan komentar crontab — pilih yang pendek. |
| Base URL | Tanpa trailing slash. Path diambil dari task template. |
| Timezone | Harus sama dengan seluruh client lain. Satu file `cron.d` hanya punya satu `CRON_TZ`. |
| Auth type | Basic untuk hampir semua client Opsifin. |
| Secret key | Hanya untuk task BCA/remittance. Kosongkan bila tidak dipakai. |
| Active | **Biarkan mati** sampai langkah 4 selesai. |

2. **Uji kredensial** — tombol **Test connection** di baris client tersebut.
   Yang dicari status `Host reachable`. Kalau `Credentials rejected`, username
   atau password salah; kalau `Cannot connect`, base URL atau jaringannya.

3. **Buat schedule-nya.** Dua cara:
   - **Matrix** → cari baris client, klik sel kosong pada task yang diinginkan.
     Form terbuka dengan client, task, dan lock key sudah terisi.
   - **Schedules → New schedule** lalu pilih manual.

   Isi ekspresi cron; preview di bawahnya menampilkan 5 jadwal berikutnya. Kalau
   muncul peringatan *uneven interval*, ekspresinya seperti `*/7` yang jedanya
   tidak seragam — hampir selalu bukan yang dimaksud.

4. **Aktifkan dan deploy.**

```bash
php artisan cron:cutover-status <code>   # harus "No blockers"
php artisan cron:render --validate
sudo php artisan cron:render --apply
```

5. **Verifikasi** baris client itu muncul di `/etc/cron.d/opsifin`, lalu tunggu
   eksekusi terjadwal pertama dan cek di menu **Runs**.

---

## 2. Menambah task template baru

Task template adalah definisi request yang dipakai bersama banyak client. Buat
template baru hanya kalau endpoint-nya memang berbeda — kalau yang berbeda cuma
path untuk satu client, pakai override.

1. **Task templates → New task template.**

| Field | Catatan |
| --- | --- |
| Key | snake_case. Muncul di lock key dan komentar crontab. |
| Path | Diawali `/`. Prefix `{base_url}` diisi dari masing-masing client. |
| Body | Dikirim apa adanya dengan `Content-Type: application/json`. |
| Extra headers | `Authorization`, `Content-Type`, `Accept` diisi otomatis. Pakai `{{client.secret_key}}`, `{{client.username}}`, atau `{{client.code}}` untuk nilai yang berbeda tiap client. |
| Timeout / connect timeout | Wajib. Tidak satu pun script lama punya ini. |
| Retries | 0 berarti tidak diulang. Jangan diisi untuk task yang tidak idempoten. |

2. **Periksa preview.** Pilih satu client di bagian Preview; yang muncul adalah
   perintah `curl` setara lengkap dengan header sebenarnya. Cocokkan dengan
   script legacy sebelum dipakai.

3. **Pasang ke client** lewat Matrix: kolom task baru itu → menu ⋮ →
   *Enable for all clients* kalau memang berlaku untuk semua, atau klik sel
   satu per satu.

4. `cron:render --validate` lalu `--apply`.

---

## 3. Membuat alert rule

Rule tanpa client/task/schedule berlaku untuk semua schedule. Mengisi salah satu
field mempersempit sasarannya.

| Kondisi | Kapan dipakai |
| --- | --- |
| **Run failed** | Task kritis yang setiap kegagalannya harus diketahui. |
| **Run timed out** | Task yang lambatnya berarti masalah di sisi endpoint. |
| **N consecutive failures** | Task yang wajar gagal sesekali. Threshold 3 meredam noise. |
| **Scheduled run never happened** | **Pasang satu rule global.** Ini satu-satunya yang mendeteksi cron mati atau crontab belum di-deploy. |

**Cooldown** menentukan jarak minimum antar alert dari rule yang sama untuk
schedule yang sama. Tanpa cooldown, job yang gagal tiap 6 menit akan mengirim
notifikasi tiap 6 menit. Default 60 menit.

Rekomendasi minimum untuk mulai:

1. `Missed run` — global, grace 15 menit, cooldown 120 menit.
2. `Consecutive failures` — global, threshold 3, cooldown 60 menit.
3. `Run failed` — dibatasi ke client besar saja (`gn`, `aladin`, `anta`), cooldown 60 menit.

Alert mendarat di lonceng notifikasi dan di menu **Alerts**. Belum ada channel
keluar; kalau nanti dipasang, cukup ditambahkan di
`app/Services/Alerting/AlertDispatcher.php` tanpa menyentuh rule.

---

## 4. Prosedur incident

### 4.1 Satu job gagal terus

```bash
php artisan cron:run <schedule_id> --dry-run   # lihat request finalnya
```

Urutan pemeriksaan:

1. **Runs → filter client → Problems only.** Lihat HTTP status dan potongan body.
2. **HTTP 401/403** → kredensial. Buka Clients, pakai **Test connection**.
   Kalau ditolak, kredensialnya memang berubah di sisi endpoint.
3. **HTTP 404** → path template atau override salah. Bandingkan dengan preview
   `curl` di Task templates.
4. **Timeout** → endpoint lambat. Naikkan `default_timeout_sec` template, atau
   pasang override khusus client itu kalau hanya dia yang lambat.
5. **Connection error** → jaringan atau DNS dari VPS ke host client.

Meredam sementara tanpa mengubah crontab: matikan schedule-nya di Matrix, lalu
`sudo php artisan cron:render --apply`.

### 4.2 Job tidak jalan sama sekali

Gejalanya alert *missed run*, atau widget **Overdue schedules** di dashboard
terisi. Yang gagal biasanya bukan job-nya, tapi jalur eksekusinya.

```bash
# 1. Apakah barisnya benar-benar ada di crontab?
grep <client_code> /etc/cron.d/opsifin

# 2. Apakah cron memicunya?
grep CRON /var/log/syslog | tail -20

# 3. Apakah runner-nya error?
tail -50 /var/log/opsifin-cron/runner.log

# 4. Apakah database dan aplikasi sehat?
php artisan cron:render --validate
```

| Temuan | Artinya |
| --- | --- |
| Baris tidak ada di `cron.d` | Perubahan belum di-deploy. Jalankan `--apply`. |
| Baris ada, syslog kosong | Daemon cron mati, atau file tidak dimiliki root / mode-nya salah. Cek `ls -l /etc/cron.d/opsifin` — harus `root:root` dan `644`. |
| Syslog ada, `runner.log` kosong | PHP binary atau path artisan di `.env` salah. |
| `runner.log` berisi error | Baca pesannya; biasanya database atau permission direktori lock. |

### 4.3 Lock tersangkut

Gejalanya banyak run berstatus `Skipped (lock)` sementara tidak ada eksekusi yang
benar-benar berjalan.

```bash
ls -l /var/lock/opsifin/            # lihat lock file
fuser -v /var/lock/opsifin/<lock_key>.lock   # ada proses yang memegangnya?
```

Kalau tidak ada proses yang memegangnya, lock file boleh dihapus. **Jangan
menghapus lock yang masih dipegang proses** — itu justru mengizinkan eksekusi
ganda, hal yang lock ini cegah.

Penyebab paling sering: satu eksekusi menggantung karena timeout terlalu besar.
Widget **Slowest tasks** di dashboard menandai task yang eksekusi terlambatnya
lebih dari 3× rata-rata — itu kandidat pertamanya.

### 4.4 Deploy crontab bermasalah

```bash
php artisan cron:rollback --list
sudo php artisan cron:rollback         # ke backup terakhir
```

Rollback ikut di-backup lebih dulu, jadi bisa dibatalkan lagi. Baris manual di
luar blok `# BEGIN/END OPSIFIN-CRON MANAGED BLOCK` tidak pernah tersentuh.

Kalau `--apply` menolak dengan `No permission to write to /etc/cron.d/opsifin`,
itu memang perilaku yang diharapkan dari tombol Deploy di UI — penulisan harus
lewat SSH dengan `sudo`. Lihat `docs/cutover.md` §6.

### 4.5 Aplikasi tidak menampilkan perubahan

```bash
npm run build                        # kalau ada perubahan blade/CSS di Filament
sudo systemctl reload php8.3-fpm     # bersihkan OPcache
```

`config:cache` dan `route:cache` belum dipakai di project ini, jadi tidak ada
yang perlu di-clear untuk keduanya.

---

## 5. Pemeliharaan rutin

| Kapan | Apa |
| --- | --- |
| Harian | Lihat dashboard. Success rate < 99% atau ada Overdue schedule berarti perlu ditelusuri. |
| Harian | Bersihkan menu **Alerts** — acknowledge yang sedang ditangani, resolve yang sudah beres. |
| Mingguan | `php artisan cron:cutover-status` selama masa migrasi masih berjalan. |
| Otomatis | `cron:check-missed` tiap 5 menit, `cron:purge-runs` tiap 03:15. Keduanya lewat `schedule:run`. |

Penjadwal Laravel butuh satu baris di crontab server, terpisah dari file yang
di-generate aplikasi:

```
* * * * * cd /opt/opsifin-cron && php artisan schedule:run >> /dev/null 2>&1
```

Pemisahan ini disengaja. Pemeriksaan missed run harus tetap hidup justru ketika
crontab hasil render bermasalah — kalau keduanya dijadwalkan di tempat yang
sama, satu kegagalan akan mematikan job sekaligus alarmnya.

---

## 6. Rujukan perintah

| Perintah | Fungsi |
| --- | --- |
| `cron:cutover-status [client]` | Kesiapan cutover per client, plus daftar blocker |
| `cron:check-missed` | Cari schedule yang seharusnya jalan tapi tidak |
| `cron:purge-runs --dry-run` | Lihat berapa baris yang akan dibersihkan |
| `cron:run <id> --dry-run` | Tampilkan request tanpa memanggil endpoint |
| `cron:run <id>` | Jalankan satu schedule sekarang |
| `cron:render --validate` | Cek semua schedule aktif, tidak menulis apa pun |
| `sudo cron:render --apply` | Deploy ke `cron.d` + backup otomatis |
| `sudo cron:rollback` | Kembalikan ke backup terakhir |
| `cron:verify-import` | Bandingkan hasil impor dengan script `.sh` asli |
