# Module Execution Logs

[Kembali ke panduan utama](../user-guide.md)

Menu: **Operations → Execution logs**. Ini adalah sumber utama status bisnis
setiap HTTP attempt.

## Kolom dan filter

Kolom: Occurrence, Client, Task, Status, Trigger, HTTP, Duration, Start lag, dan
Message. Tabel refresh setiap 15 detik. Filter tersedia untuk Client, Task,
Status, Trigger, Problems only, serta periode From/Until.

## Membaca detail Run

Detail berisi:

- ID, Schedule, Client, Task, execution driver, dan trigger;
- scheduled/prepared/queued/started/finished;
- start lag, duration, worker/executor metadata;
- HTTP status, response excerpt, dan error message;
- source Run untuk retry queue.

Response/error melewati redaction, tetapi endpoint dapat mengembalikan data
sensitif yang tidak dikenali sebagai credential. Review sebelum membagikannya.

## Diagnosis status

### Pending terlalu lama

Periksa Direct executor, dispatcher, active slots, start lag, durasi endpoint,
dan start window. Pending yang melewati window menjadi skipped tanpa replay.

### Queued terlalu lama

Khusus queue compatibility: periksa Horizon, Redis, Supervisor, queue
connection, dan reconciler.

### Running terlalu lama

Bandingkan umur dengan request timeout + execution margin. Jangan membuat Run
manual duplikat selama outcome lama belum jelas.

### Failed atau Skipped

HTTP 4xx menunjukkan request/auth/data ditolak; 5xx menunjukkan endpoint gagal;
timeout/koneksi menunjukkan transport. Untuk skipped, baca `error_message`:
penyebab umum adalah state nonaktif, overlap, invalid config, missed window, atau
deadline recovery.

## Cancel waiting Run

Cancel hanya tersedia ketika status `pending` atau `queued`. Jika executor atau
worker telah claim, cancel ditolak. Bulk action hanya memproses record yang masih
menunggu.

## Retry

Retry hanya muncul ketika driver global queue, Run berasal dari queue, status
failed, dan masih mempunyai Schedule. Retry membuat Run baru.

Pada direct, Retry tidak tersedia. Setelah akar masalah dipahami dan endpoint
aman dipanggil lagi, gunakan **Run now** untuk occurrence manual baru.

## Menghapus log

Penghapusan riwayat eksekusi hanya tersedia untuk **Administrator**. Operator dan
Viewer tidak melihat aksi ini sama sekali.

Tiga cara, semuanya permanen dan tanpa undo:

| Aksi | Letak | Cakupan |
| --- | --- | --- |
| **Delete log** | menu Actions per baris, dan halaman detail Run | satu occurrence |
| **Delete selected logs** | Bulk actions | seluruh record terpilih |
| **Delete old logs** | tombol di kanan atas halaman | semua yang lebih tua dari N hari |

Run yang belum selesai — `pending`, `queued`, dan `running` — **tidak dapat
dihapus**. Aksi per baris disembunyikan, bulk action melewatinya dan melaporkan
jumlah yang dilewati, dan pembersihan berdasarkan umur tidak pernah menyentuhnya.
Batasan ini disengaja: `schedules.running_run_id` menunjuk Run yang sedang
berjalan, sehingga menghapusnya akan meninggalkan slot overlap menggantung dan
memblokir seluruh occurrence berikutnya pada Schedule tersebut.

Setiap penghapusan dicatat di **Audit history** dengan action `deleted`, beserta
status, Client, Task, occurrence, HTTP status, dan durasi Run yang dihapus.
Response body dan error message sengaja tidak disalin ke audit karena dapat
memuat pesan endpoint.

**Delete old logs** adalah padanan manual dari `cron:purge-runs` yang berjalan
otomatis tiap pukul 03:00 memakai `CRON_RUNS_RETENTION_DAYS` (default 90 hari).
Nilai pada form hanya berlaku untuk pembersihan saat itu dan tidak mengubah
konfigurasi retensi.

## Data minimal untuk tiket

Sertakan Run ID, Client code, Task key, occurrence, status, trigger, execution
driver, HTTP status, duration, start lag, dan error yang sudah direview. Jangan
menyertakan Authorization, credential, `.env`, atau dump database.

