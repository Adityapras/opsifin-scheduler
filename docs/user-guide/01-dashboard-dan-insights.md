# Module Dashboard dan Client Job Summary

[Kembali ke panduan utama](../user-guide.md)

## Tujuan

Dashboard menjawab apakah scheduler dan executor sehat. **Client job summary**
menjawab apakah assignment job per Client sudah lengkap dan bagaimana timing-nya.

## Dashboard

Widget refresh otomatis setiap 30 detik.

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

1. Pastikan executor dan dispatcher online untuk direct.
2. Pastikan missed start window tidak bertambah.
3. Periksa pending tertua dan running lama.
4. Periksa success rate dan failed terbaru.
5. Buka Client job summary setelah perubahan assignment/onboarding.

