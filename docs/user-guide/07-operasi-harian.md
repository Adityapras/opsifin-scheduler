# Operasi Harian dan Playbook Pengguna

[Kembali ke panduan utama](../user-guide.md)

Command server dan deployment lengkap berada di
[Operations Runbook](../operations.md) dan
[Direct HTTP Operations](../direct-http-operations.md).

## Checklist harian

1. Periksa Enabled schedules serta executor/dispatcher direct.
2. Pastikan missed start window tidak bertambah.
3. Periksa pending/queued tertua dan running lama.
4. Filter failed 24 jam terakhir.
5. Review perubahan besar pada Audit history.
6. Catat anomali dengan Run ID, bukan credential.

## Menambahkan job baru

1. Definisikan endpoint, side effect, owner, dan idempotency.
2. Buat satu Template canonical.
3. Assign ke satu Client pilot dan biarkan paused.
4. Inspect request dan Run now satu kali.
5. Verifikasi HTTP result serta efek bisnis.
6. Assign/Resume subset kecil dan monitor beberapa siklus.

## Endpoint bermasalah

1. Filter Execution logs berdasarkan Client/Task.
2. Pause Schedule terkait bila failure terus bertambah.
3. Biarkan Run running selesai/timeout.
4. Perbaiki Client, Template, network, atau endpoint.
5. Inspect request, Run now satu kali, lalu Resume bertahap setelah sukses.

## Executor atau dispatcher offline

Jangan Resume Schedule tambahan. Catat heartbeat, pending tertua, dan jumlah
enabled. Eskalasi ke PIC server untuk Supervisor, system cron, DB, dan log.
Jangan menjalankan Run now berulang sebagai pengganti daemon.

## Pending mendekati start window

Jangan menambah beban. Periksa slot/durasi, Pause prioritas rendah bila perlu,
dan biarkan occurrence lewat menjadi skipped. Jangan mengubahnya kembali ke
pending; setelah pulih gunakan Run now baru yang disengaja.

## Sebelum bulk Resume

- Client/Template aktif dan terverifikasi.
- Inspect request serta smoke test sukses.
- Executor/dispatcher online.
- Kapasitas cukup untuk volume dan latency aktual.
- Legacy cron pada scope sama sudah dikoordinasikan.
- Filter, jumlah record, PIC monitoring, dan rollback sudah jelas.

## Batas aplikasi

Tidak ada automatic retry, full catch-up, pembatalan paksa HTTP yang sudah
dikirim, per-Client driver, incident engine, atau mass-enable otomatis setelah
import.

