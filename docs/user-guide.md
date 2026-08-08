# Panduan Pengguna

Panduan memakai panel admin, layar per layar. Untuk memahami cara kerja
sistemnya lebih dulu, baca [`architecture.md`](architecture.md).

---

## 1. Yang wajib diingat sebelum apa pun

**Perubahan di panel tidak langsung berlaku di server.**

Menyalakan schedule, mengubah ekspresi cron, menambah client — semuanya hanya
mengubah baris di database. Daemon cron baru tahu setelah crontab di-deploy:

```bash
php artisan cron:render --validate
sudo php artisan cron:render --apply
```

Halaman **Deploy crontab** di panel menampilkan diff dan preview, tapi di VPS
tombol Deploy-nya sengaja tidak dipakai — penulisan dilakukan lewat SSH.
Alasannya ada di [`installation.md`](installation.md) §9.2.

**Tiga gerbang.** Sebuah job berjalan hanya kalau ketiganya aktif:

| Gerbang | Diatur di |
| --- | --- |
| Schedule aktif | Matrix atau Schedules |
| Client aktif | Clients |
| Task template aktif | Task templates |

Kalau sebuah job tidak jalan padahal schedule-nya hijau, periksa dua gerbang
lainnya.

---

## 2. Peran dan kewenangan

| | viewer | operator | admin |
| --- | :---: | :---: | :---: |
| Membaca semua halaman | ✓ | ✓ | ✓ |
| Dry run | ✓ | ✓ | ✓ |
| Enable/disable schedule | | ✓ | ✓ |
| Run now | | ✓ | ✓ |
| Acknowledge / resolve alert | | ✓ | ✓ |
| Tes koneksi client | | ✓ | ✓ |
| CRUD client, task template, alert rule | | | ✓ |
| Deploy & rollback crontab | | | ✓ |

Kredensial client hanya terlihat di form edit client, dan form itu hanya bisa
dibuka admin.

---

## 3. Dashboard

Empat panel yang menjawab "apakah semalam baik-baik saja?".

| Panel | Cara membacanya |
| --- | --- |
| **Runs in 24h** | Jumlah eksekusi nyata (dry run tidak dihitung) |
| **Success rate** | Hijau ≥ 99%, kuning ≥ 90%, merah di bawah itu |
| **Failed or timed out** | Angka merah berarti ada yang perlu ditelusuri di Runs |
| **Open alerts** | Alert yang belum dipegang siapa pun |

**Overdue schedules** — schedule aktif yang jadwalnya sudah lewat tapi belum
tereksekusi. Isinya bukan job yang gagal, melainkan job yang **tidak
meninggalkan jejak apa pun**. Penyebab tersering: crontab belum di-deploy, atau
daemon cron mati. Kalau tabel ini terisi banyak sekaligus, curigai jalur
eksekusinya, bukan endpoint-nya.

**Client health** — success rate per client, plus *lock skips* dan durasi
rata-rata. Lock skip tinggi berarti eksekusi sebelumnya sering belum selesai
saat jadwal berikutnya tiba.

**Slowest tasks** — kolom *Slowest* berwarna kuning bila eksekusi terlambatnya
lebih dari 3× rata-rata. Selisih besar berarti ada eksekusi yang sesekali
menggantung, dan itulah kandidat pertama penyebab lock contention.

---

## 4. Matrix

Layar utama untuk mengelola 40 client × 27 task sekaligus.

```
┌──────────┬─────┬─────┬─────┐
│ CLIENT   │repo…│sett…│remi…│  ← nama task dipotong; hover untuk teks penuh
├──────────┼─────┼─────┼─────┤
│ gn   6 ⋮ │ ██  │ ██  │  ·  │
│ bca  2 ⋮ │ ██  │ ░░  │ ██  │
└──────────┴─────┴─────┴─────┘
 ██ aktif   ░░ nonaktif   · belum ada schedule
```

| Elemen | Arti |
| --- | --- |
| Kotak hijau | Schedule ada dan aktif |
| Kotak abu | Schedule ada tapi dimatikan |
| Titik kecil | Belum ada schedule untuk kombinasi ini |
| Angka di baris client | Jumlah task aktif client tersebut |
| ⚠ di samping kode client | Butuh verifikasi manual |
| Kode client dicoret | Client sedang nonaktif |

**Membaca sel.** Nama task dipotong agar kolomnya tetap sempit. Arahkan kursor ke
header kolom atau ke sel mana pun — keterangannya muncul di **baris readout** di
atas tabel:

```
▸ gn / repost · */6 * * * * · next Tue, 04 Aug 21:00 · lock gn.repost · enabled
```

Baris dan kolom yang sedang di-hover ikut tersorot, jadi tidak perlu menghitung
kolom dengan jari.

**Aksi.**

| Yang dilakukan | Caranya |
| --- | --- |
| Nyalakan/matikan satu schedule | Klik selnya |
| Buat schedule baru | Klik titik kecil — form terbuka dengan client, task, dan lock key sudah terisi |
| Nyalakan/matikan seluruh task satu client | Menu ⋮ di baris client |
| Nyalakan/matikan satu task di semua client | Menu ⋮ di header kolom |
| Buka detail client atau task | Klik namanya |

**Filter.** Kotak *Search client* dan *Search task* mempersempit tampilan.
Centang **Hide empty rows and columns** untuk menyembunyikan client dan task
yang tidak punya satu pun schedule aktif — berguna saat migrasi bertahap, ketika
sebagian besar masih mati.

---

## 5. Schedules

Daftar lengkap semua schedule dengan detail yang tidak muat di matrix.

Kolom yang perlu diperhatikan:

- **Schedule** — ekspresi cron, dengan terjemahannya di bawah. Kalau muncul
  `⚠ uneven interval`, ekspresinya seperti `*/7` yang jedanya tidak seragam
  (7 menit lalu 4 menit, bukan "tiap 7 menit"). Hampir selalu bukan yang
  dimaksud.
- **Next run** / **Last run** — jadwal berikutnya dan kapan terakhir jalan.
- **Last result** — hasil eksekusi terakhir.
- **Review** — segitiga kuning berarti importer menandai baris ini untuk
  diperiksa manusia. Arahkan kursor untuk membaca catatannya.

**Aksi per baris:**

| Aksi | Efek |
| --- | --- |
| **Dry run** | Menampilkan request final — URL, header, body, timeout — **tanpa memanggil endpoint**. Aman dijalankan kapan saja. |
| **Run now** | Benar-benar memanggil endpoint, sekali. Lock tetap dihormati. |
| **Edit** | Ubah ekspresi cron, timezone, lock, status. |

**Aksi massal:** pilih beberapa baris → Enable, Disable, atau *Mark as reviewed*.

### Mengisi form schedule

| Field | Catatan |
| --- | --- |
| Client & Task | Menentukan URL yang dipanggil. Preview di bawahnya menampilkan hasilnya, termasuk bila client punya override. |
| Cron expression | `menit jam tanggal bulan hari`. Preview menampilkan 5 jadwal berikutnya. |
| Timezone | **Harus sama untuk semua schedule aktif.** Satu file `cron.d` hanya punya satu `CRON_TZ`; timezone campuran akan ditolak saat validasi. |
| Lock key | Terisi otomatis dari `<client>.<task>`. Dua schedule dengan lock key sama tidak akan pernah berjalan bersamaan. |
| Lock mode | *Skip* — lewati kalau yang lama masih jalan. *Queue* — antre sampai timeout. Skip hampir selalu yang benar. |

---

## 6. Runs

Riwayat eksekusi. Read-only — hanya runner yang menulis ke sini.

Tabel menyegarkan diri tiap 30 detik. Filter yang paling sering dipakai:

- **Problems only** — hanya yang gagal atau timeout.
- **Client** / **Task** / **Status** / **Trigger**.
- **Period** — rentang waktu tertentu.

Status yang mungkin muncul:

| Status | Arti |
| --- | --- |
| Success | HTTP 2xx |
| Failed | HTTP ≥ 400 atau error koneksi |
| Timeout | Melewati batas waktu template |
| Skipped (lock) | Eksekusi sebelumnya masih berjalan |
| Skipped (disabled) | Schedule atau client sedang dimatikan |
| Running | Sedang berjalan |

Klik satu baris untuk melihat detail: URL lengkap, HTTP status, pesan error, dan
potongan body respons. Ada tautan langsung ke schedule-nya.

---

## 7. Alerts

Alert yang sudah terbit. Filter defaultnya menampilkan yang **Open** saja.

Alur penanganannya:

```
Open  ──[Acknowledge]──►  Acknowledged  ──[Resolve]──►  Resolved
  └──────────────────[Resolve]────────────────────────────┘
```

- **Acknowledge** — "saya sedang menangani ini". Tercatat siapa dan kapan.
- **Resolve** — "sudah beres".

Alert yang masih **Open tidak pernah ikut dibersihkan** oleh purge otomatis,
sekalipun sudah tua. Yang sudah ditutup dibersihkan bersama riwayat run.

Alert juga muncul di lonceng notifikasi di pojok kanan atas.

---

## 8. Clients

Satu baris = satu sistem tujuan.

**Kolom Auth** menampilkan tipe dan username. **Test connection** memanggil base
URL client dan melaporkan:

| Hasil | Arti |
| --- | --- |
| Host reachable | Host hidup dan kredensial tidak ditolak |
| Credentials rejected | Host hidup, tapi mengembalikan 401/403 |
| Cannot connect | Base URL salah, DNS, atau jaringan |

*Host reachable* belum menjamin kredensialnya valid untuk endpoint task tertentu
— untuk itu pakai **Run now** pada satu schedule.

### Mengisi form client

| Field | Catatan |
| --- | --- |
| Code | Huruf, angka, titik, strip. Dipakai di lock key dan komentar crontab — pilih yang pendek. |
| Base URL | Tanpa trailing slash. Path diambil dari task template. |
| Timezone | Sama untuk semua client. |
| Active | Saklar induk. Mematikannya mematikan seluruh task client ini. |
| Auth type | Basic untuk hampir semua client Opsifin. |
| Password / Token | **Ditampilkan apa adanya**, supaya bisa dicocokkan dengan script legacy. |
| Secret key | Hanya untuk task BCA/remittance. Kosongkan bila tidak dipakai. |

Kredensial disimpan terenkripsi (AES-256) dan tidak pernah ditulis ke file
maupun ke crontab. Tapi karena tampil terbuka di layar, **jangan buka tab ini
saat screen-share.**

---

## 9. Task templates

Definisi request yang dipakai bersama banyak client. Buat template baru hanya
kalau endpoint-nya memang berbeda — kalau yang berbeda hanya path untuk satu
client, pakai override.

| Field | Catatan |
| --- | --- |
| Key | snake_case. Muncul di lock key dan komentar crontab. |
| Path | Diawali `/`. Prefix `{base_url}` diisi dari masing-masing client. |
| Body | Dikirim apa adanya dengan `Content-Type: application/json`. |
| Extra headers | `Authorization`, `Content-Type`, `Accept` diisi otomatis. Pakai `{{client.secret_key}}`, `{{client.username}}`, atau `{{client.code}}` untuk nilai yang berbeda tiap client. |
| Timeout / connect timeout | Wajib. Tidak satu pun script lama punya ini. |
| Retries | 0 berarti tidak diulang. **Jangan diisi untuk task yang tidak idempoten.** |

Bagian **Preview** menampilkan perintah `curl` setara untuk client yang dipilih,
lengkap dengan header sebenarnya. Cocokkan dengan script legacy sebelum dipakai.

Kolom **Overrides** di tabel menunjukkan berapa client yang menyimpang dari
template ini.

---

## 10. Alert rules

Menentukan kapan alert berbunyi. Rule tanpa client/task/schedule berlaku untuk
semua; mengisi salah satunya mempersempit sasaran.

| Kondisi | Kapan dipakai |
| --- | --- |
| **Run failed** | Task kritis yang setiap kegagalannya harus diketahui |
| **Run timed out** | Task yang lambatnya berarti masalah di sisi endpoint |
| **N consecutive failures** | Task yang wajar gagal sesekali — threshold 3 meredam noise |
| **Scheduled run never happened** | **Pasang satu rule global.** Ini satu-satunya yang mendeteksi cron mati atau crontab belum di-deploy |

**Cooldown** menentukan jarak minimum antar alert dari rule yang sama untuk
schedule yang sama. Tanpa cooldown, job yang gagal tiap 6 menit mengirim
notifikasi tiap 6 menit. Default 60 menit.

**Grace** (khusus missed run) menentukan seberapa terlambat sebuah run boleh
sebelum dianggap tidak terjadi.

Konfigurasi minimum yang disarankan untuk mulai:

1. `Missed run` — global, grace 15 menit, cooldown 120 menit
2. `Consecutive failures` — global, threshold 3, cooldown 60 menit
3. `Run failed` — dibatasi ke client besar saja, cooldown 60 menit

---

## 11. Deploy crontab

Halaman ini menampilkan apa yang **akan** terjadi sebelum terjadi.

| Bagian | Isi |
| --- | --- |
| **Target** | Path file, jumlah schedule aktif, jumlah baris yang berubah |
| **Validation** | Daftar masalah yang mengunci deploy. Setiap baris menautkan ke schedule-nya |
| **Diff** | Perbandingan isi file sekarang dengan hasil render |
| **Full file preview** | Isi lengkap file yang akan ditulis |
| **Backups** | 10 backup terakhir |

Validasi gagal **mengunci** deploy. Yang diperiksa: ekspresi cron valid, lock key
ada dan aman untuk nama file, timezone seragam, URL hasil resolve valid, dan
kredensial lengkap.

Di VPS, penulisan dilakukan lewat SSH:

```bash
php artisan cron:render --validate
sudo php artisan cron:render --apply
```

Setiap deploy membuat backup otomatis. Rollback:

```bash
php artisan cron:rollback --list
sudo php artisan cron:rollback
```

Baris manual di luar blok `# BEGIN/END OPSIFIN-CRON MANAGED BLOCK` tidak pernah
tersentuh, jadi crontab lama boleh hidup berdampingan selama masa migrasi.

---

## 12. Alur kerja yang paling sering dipakai

**Mengubah jadwal sebuah job**
Schedules → Edit → ubah cron expression → periksa preview 5 jadwal berikutnya →
Save → `sudo php artisan cron:render --apply`

**Mematikan sementara satu client**
Clients → Edit → Active off → Save → `sudo php artisan cron:render --apply`

**Menambah task ke banyak client sekaligus**
Task templates → New → isi → Save → Matrix → menu ⋮ di kolom task baru →
*Enable for all clients* → `sudo php artisan cron:render --apply`

**Memeriksa kenapa sebuah job gagal**
Runs → filter client → Problems only → buka barisnya → baca HTTP status dan body
→ kalau perlu, Schedules → Dry run untuk melihat request finalnya

**Menyiapkan client baru untuk cutover**
`php artisan cron:cutover-status <code>` → selesaikan blocker → Test connection →
Dry run → Run now sekali → baru aktifkan

---

## Selanjutnya

- Prosedur incident dan pemeliharaan rutin → [`operations.md`](operations.md)
- Migrasi bertahap dari crontab lama → [`cutover.md`](cutover.md)
- Daftar perintah dan konfigurasi → [`reference.md`](reference.md)
