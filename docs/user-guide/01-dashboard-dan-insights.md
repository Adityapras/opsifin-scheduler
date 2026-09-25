# Module Dashboard dan Client Job Summary

[Kembali ke panduan utama](../user-guide.md)

## Tujuan

Dashboard menjawab apakah scheduler dan executor sehat. **Client job summary**
menjawab apakah assignment job per Client sudah lengkap dan bagaimana timing-nya.

## Dashboard

Statistik dan Server health refresh otomatis setiap 30 detik; grafik dan
tabel failure setiap 60 detik.

| Metrik | Arti | Kondisi yang perlu diperiksa |
| --- | --- | --- |
| Enabled schedules | Schedule yang aktif | Angka berubah tanpa change terencana |
| Success, 24 hours | Succeeded dibanding succeeded + failed | Turun dari baseline operasional |
| Pending/Queue | Run menunggu start | Terus bertambah atau menua |
| Running | Request aktif | Bertahan melewati timeout + margin |
| Direct executor | Lease/heartbeat executor | Offline saat driver direct |
| Active slots | Request aktif / kapasitas | Jenuh terus menerus |
| Start lag p95/p99 | Selisih occurrence dengan waktu start | p95 >45 detik atau p99 ≥60 detik |
| Dispatcher | Heartbeat materializer | Offline lebih dari dua menit |
| Missed start window | Occurrence direct yang tidak sempat mulai | Nilai di atas nol harus diinvestigasi |

Pada mode queue, kartu **Pending** menjadi **Queue** dan kartu direct tidak
ditampilkan. Horizon hanya muncul untuk Administrator ketika driver queue aktif.

### Server health

Panel **Server health** menampilkan status keseluruhan (Healthy, Warning, atau
Critical) dan satu kartu per pemeriksaan. Setiap pemeriksaan yang Warning atau
Critical muncul di bagian **Mitigation steps** beserta langkah pertama yang
harus dilakukan.

| Pemeriksaan | Warning | Critical |
| --- | --- | --- |
| Database | Ping >200 ms | Tidak terhubung |
| DB connections (MySQL) | ≥80% `max_connections` | ≥95% |
| Dispatcher | — | Heartbeat >2 menit atau belum pernah ada |
| Direct executor / Redis queue | — | Lease kedaluwarsa / Redis tidak merespons |
| Waiting backlog | Run tertua > start window | Run tertua >5 menit |
| Overdue running | — | Ada Run melewati execution deadline |
| Failure rate, 24 hours | ≥1% | ≥10% |
| CPU load | Load 1 menit ≥0,8 × jumlah core | ≥1,5 × jumlah core |
| Memory | ≥85% terpakai | ≥95% |
| Disk (storage) | ≥85% terpakai | ≥95% |

CPU, memory, dan disk diukur dari host/container tempat web panel berjalan,
bukan dari server Client. Pada Docker Desktop/WSL, memory mengikuti VM WSL.

### Grafik

- **Runs per hour**: jumlah Run 24 jam terakhir per jam, ditumpuk per status
  (succeeded, failed, skipped, cancelled). Lonjakan merah menandai jam gangguan.
- **Start lag and duration**: rata-rata dan maksimum start lag serta rata-rata
  durasi per jam dalam detik. Max start lag harus tetap di bawah 60 detik.

### Clients with failures, last 24 hours

Daftar Client yang memiliki Run failed dalam 24 jam, diurutkan dari jumlah
failure terbanyak, beserta waktu failure terakhir dan pesan error terakhir
(sudah melewati redaction). **Logs** membuka Execution logs yang sudah difilter
ke Client tersebut dengan filter Problems only.

### Tabel waiting occurrences

Tabel di bawah statistik menampilkan Run yang masih `pending` atau `queued`.
**Cancel** hanya tersedia sebelum executor/worker mengambil Run; **Open** membuka
detail. Tabel kosong berarti tidak ada Run menunggu, bukan berarti seluruh
runtime pasti sehat—tetap periksa heartbeat.

## Client Job Summary

Menu: **Insights → Client job summary**.

| Kolom | Arti |
| --- | --- |
| Client | Code dan nama Client |
| Active job coverage | Template aktif yang sudah di-assign / seluruh template aktif |
| Jobs in use | Template yang mempunyai Schedule |
| Missing active jobs | Template aktif yang belum di-assign |
| Timings | Jumlah Schedule, termasuk beberapa timing untuk job yang sama |
| Enabled | Schedule aktif |
| Review | Memerlukan verifikasi manual |
| Client active | Master state Client |

Filter: **Missing job assignments**, **Client active**, dan **Needs review**.
Klik **Schedule details** untuk melihat job, cron, timezone, state, dan template
yang belum di-assign. Coverage tidak harus 100%; pengecualian bisnis harus
didokumentasikan.

## Pemeriksaan awal shift

1. Pastikan **Server health** Healthy; ikuti Mitigation steps bila tidak.
2. Pastikan executor dan dispatcher online untuk direct.
3. Pastikan missed start window tidak bertambah.
4. Periksa pending tertua dan running lama.
5. Periksa grafik dan **Clients with failures**.
6. Buka Client job summary setelah perubahan assignment/onboarding.

