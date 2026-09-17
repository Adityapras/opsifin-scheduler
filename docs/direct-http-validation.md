# Direct HTTP — hasil validasi lokal

Tanggal: **10 September 2026**. Release compatibility di working tree, belum
diaktifkan di production. Acuan perilaku: [rencana migrasi](direct-bounded-http-migration-plan.md).
Instruksi deployment: [runbook direct](direct-http-operations.md).

## Lingkungan dan metode

- PHP CLI `/www/server/php/84/bin/php`, dependency Composer yang sudah terpasang.
- PHPUnit menggunakan SQLite `:memory:`; process drill memakai file SQLite
  sementara dengan konfigurasi child process terisolasi.
- HTTP sungguhan melalui Guzzle/cURL multi ke Python `ThreadingHTTPServer`
  pada `127.0.0.1` dan port acak. Tidak ada request ke endpoint Client asli.
- Load memakai satu occurrence timestamp setelah pembuatan fixture selesai.
  `start_lag_ms` dari aplikasi dibandingkan waktu request diterima server
  loopback. Keduanya wajib di bawah 60 detik; jumlah request endpoint harus sama
  dengan jumlah Run, dan endpoint max active tidak boleh melebihi concurrency.
- CPU adalah user CPU proses PHP selama drain. Peak memory adalah alokasi PHP
  termasuk framework/test harness; bukan RSS seluruh host. Byte count adalah
  response body, bukan seluruh trafik TCP/TLS.

## Hasil checks

| Check | Hasil |
| --- | --- |
| Baseline saat sesi dilanjutkan | 108 passed, 411 assertions, 1 opt-in skipped |
| Full suite setelah module User Guide dan renderer diagram | 120 passed, 447 assertions, 1 opt-in skipped |
| Laravel Pint | Passed |
| Vite production build | Passed |
| User Guide: Chromium, 3 viewport × light/dark × 3 dokumen | 18 skenario passed; 84 render untuk 14 diagram, zoom/fit-all/fullscreen lulus |
| User Guide lanjutan, 11 September: Chromium, 3 viewport + desktop device scale 125% | 24 skenario passed; 112 render; containment node/label dan seluruh fullscreen desktop lulus |
| User Guide lanjutan, 11 September: Chrome Windows, desktop device scale 125%, browser zoom 100% | 6 skenario passed; 28 render; zoom/fit/fullscreen dan light/dark lulus |
| AdminPanel regression, 11 September | 29 passed, 122 assertions |
| `git diff --check` | Passed |
| Capacity 123 × 5 detik, C=20 | Passed, 7 assertions |
| Capacity 246 × 5 detik, C=40 | Passed, 7 assertions |
| Capacity 10 × 100 ms, 50 × 1 detik, 10 × 10 detik | Masing-masing passed, 7 assertions |

Satu skipped test pada suite normal adalah capacity scenario yang sengaja
memerlukan environment variable. Angka capacity di bawah berasal dari
invocation terpisah, bukan dari test yang skipped.

Validasi diagram lanjutan menggunakan HTML Laravel/Filament dari fixture SQLite
terisolasi. Screenshot overview, ER, dan sequence diperiksa, tidak hanya ukuran
DOM. ResizeObserver loop dan konflik transisi ukuran gambar telah diperbaiki.
Build JS/CSS yang dilayani `opsifin-cron.local` diverifikasi HTTP 200 dengan hash
identik terhadap build lokal. Ini tidak menggantikan walkthrough operasi Direct
HTTP menggunakan akun dan Client nyata.

## Capacity utama

| Metrik | Peak 123 | Projected 246 |
| --- | ---: | ---: |
| Delay endpoint | 5 s | 5 s |
| Concurrency | 20 | 40 |
| Run started / succeeded | 123 / 123 | 246 / 246 |
| HTTP request diterima endpoint | 123 | 246 |
| Max active aplikasi / endpoint | 20 / 20 | 40 / 40 |
| Missed window / expired running | 0 / 0 | 0 / 0 |
| Start lag p50 | 15,840 s | 16,126 s |
| Start lag p95 | 27,122 s | 27,132 s |
| Start lag p99 | 31,570 s | 31,251 s |
| Start lag maksimum | 31,684 s | 31,330 s |
| Endpoint start lag p99 | 31,571 s | 31,253 s |
| Endpoint start lag maksimum | 31,686 s | 31,335 s |
| Duration p50 | 5,045 s | 5,059 s |
| Duration p95 | 5,750 s | 5,724 s |
| Duration p99 | 5,764 s | 5,783 s |
| Waktu drain terukur | 36,266 s | 36,922 s |
| PHP peak memory | 69 MiB | 71 MiB |
| PHP user CPU | 0,951387 s | 1,758661 s |
| Response body bytes | 2.214 | 4.428 |

Angka memakai koma desimal. Hasil membuktikan skenario fixture ini memenuhi
same-minute start; belum menetapkan concurrency final production. Waktu drain
dapat berbeda dari duration PHPUnit karena teardown/test harness.

### Variasi latency tambahan

| Run | Delay | C | Succeeded | Endpoint max active | Endpoint p99 start lag | Drain |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 10 | 0,1 s | 20 | 10 | 10 | 0,893 s | 0,546 s |
| 50 | 1 s | 20 | 50 | 20 | 2,742 s | 3,218 s |
| 10 | 10 s | 20 | 10 | 10 | 0,405 s | 10,135 s |

Semua variasi mencatat tepat satu HTTP request per Run dan nol missed window.
Setiap skenario dijalankan berurutan. Percobaan awal tiga variasi ini ditolak
oleh sandbox saat bind socket; pengulangan di luar sandbox berhasil.
Matrix ini merupakan skenario terpilih, bukan seluruh kombinasi N × latency × C.

## Bukti perilaku dan failure drill

| Area | Bukti test |
| --- | --- |
| Materialization sekali, manual background, tidak publish Redis | `DirectPoolExecutorTest::test_materialization_is_idempotent_and_manual_work_stays_in_background` |
| Run Now pada Schedule paused | Manual Run berhasil tanpa mengaktifkan occurrence cron |
| Bounded pool dan HTTP failure isolation | `DirectPoolExecutorTest::test_concurrency_failure_isolation_and_no_retry`, real HTTP integration |
| Slot diisi ulang selama request lambat masih aktif | `test_slow_request_does_not_block_newly_arriving_manual_run` |
| Pause, cancel, overlap, invalid config mencegah send | `test_pause_cancel_overlap_and_invalid_config_prevent_send` |
| No catch-up, stale terminal, occurrence berikutnya independen | `test_expired_pending_and_crashed_running_are_terminal_without_catch_up` |
| Recheck window setelah menunggu slot | `test_admission_rechecks_window_after_waiting_for_a_slot`, overload HTTP dengan window 1 detik |
| Atomic claim dan hasil terminal tidak ditimpa callback terlambat | `test_queue_worker_cannot_claim_direct_pending_and_lifecycle_cannot_claim_twice` |
| Ownership satu daemon | Lease test dan process test `test_second_executor_stands_by_while_first_owns_the_pool` |
| SIGTERM | Request aktif selesai, pending belum dikirim, restart memproses pending yang masih valid |
| SIGKILL | Run ambigu menjadi failed melalui deadline; endpoint hanya menerima sekali; pending berikutnya sukses |
| Persistence callback gagal | Injeksi exception: callback lain tersimpan, admission berhenti, sisa running menunggu recovery tanpa resend |
| Timeout dan disconnect | HTTP integration: hanya request terkait failed, request lain succeeded |
| Non-2xx, redirect, body besar | 503 menyimpan pesan redacted; 302 tidak diikuti; response 5 MB selesai dengan excerpt maksimum 2.000 karakter |
| Credential | URL/JSON encoding, Authorization token, nilai `0`, partial secret pada bounded body, redaction sebelum queue excerpt dipotong |
| Rollback routing | Hanya occurrence baru masuk queue; direct failed tidak dapat Retry setelah driver kembali queue |
| Operator UI | HTTP/Livewire test dashboard dan detail Run; Pending/health tampil, Retry direct ditolak |
| Health | Missing heartbeat/lag memicu alert; missed-window terminal tetap alert 24 jam; duration mengabaikan Run yang belum mulai |

Test sumber berada di `tests/Feature/DirectPoolExecutorTest.php`,
`DirectHttpIntegrationTest.php`, `DirectExecutorProcessTest.php`,
`WorkDirectRunsCommandTest.php`, `HttpExecutorTest.php`, serta
`tests/Unit/ResolvedRequestTest.php`.

SIGKILL drill memajukan deadline dan expiry lease hanya pada database test,
setelah endpoint fixture berhenti aktif, agar tidak menunggu timeout recovery
production. Ini membuktikan transisi recovery dan no resend, bukan durasi
failover production secara real time. Persistence failure memakai exception
injection, bukan pemutusan service MySQL.

## Perbaikan saat melanjutkan sesi

1. Lengkapi redaction untuk JSON escaped Unicode/slash/quote dan credential `0`.
   Queue response/error di-redact sebelum truncation agar prefix secret tidak bocor.
2. Health tetap melaporkan occurrence terlewat setelah pending menjadi skipped.
   Percentile duration hanya memasukkan Run yang pernah mulai.
3. Tambah regression test untuk kasus tersebut, process test dua daemon, dan
   acceptance waktu pengiriman berdasarkan observasi endpoint.
4. Perbaiki Pint pada test yang tertinggal; lengkapi runbook, README, checklist
   migrasi, dan handoff agar status implementasi bisa dilanjutkan lintas sesi.

## Batasan dan pekerjaan production

- Belum ada bukti pilot/soak atau staged cutover/rollback di infrastructure target.
- SQLite tidak menguji row locking/deadlock, connection limit, dan query latency
  MySQL production. Dispatcher ganda diuji secara pemanggilan berulang; race
  antar host dengan MySQL tetap perlu diuji pada environment target.
- CPU/memory/body bytes fixture sudah dicatat. Jumlah koneksi MySQL, socket/file
  descriptor peak, network/TLS, disk, host RSS, dan full-server restart belum
  diukur. Manual browser walkthrough Direct HTTP belum dilakukan. User Guide
  sudah diperiksa dengan Chromium pada HTML Laravel/Filament dan aset produksi,
  memakai fixture SQLite terisolasi (`tests/Browser/`); ini tidak menggantikan
  walkthrough operasi Direct HTTP pada Client nyata.
- Uji timeout memakai deadline singkat terisolasi. Distribusi timeout/durasi
  Task production dan skenario semua request hang sampai timeout production
  belum diukur. Dengan 123 request dan 20 slot, request berdurasi 60 detik akan
  menyisakan occurrence melewati window 55 detik; hasil fixture 5 detik tidak
  menjamin SLA untuk workload tersebut.
- `.env`, database aplikasi, schedule aktif, legacy cron, serta service
  production tidak diubah dalam sesi lanjutan ini. Migration hanya dijalankan
  oleh tests pada database terisolasi. Source belum di-commit/push.
- Redis/Horizon dipertahankan untuk compatibility. Penghapusan menunggu soak
  dan audit pemakaian Redis pada subsistem lain sesuai rencana migrasi.

## Cara mengulang

```bash
/www/server/php/84/bin/php artisan test --compact
/www/server/php/84/bin/php vendor/bin/pint --test
npm run build
git diff --check

DIRECT_HTTP_LOAD_RUNS=123 DIRECT_HTTP_LOAD_DELAY=5 DIRECT_HTTP_LOAD_CONCURRENCY=20 /www/server/php/84/bin/php artisan test --compact --filter=test_opt_in_capacity_scenario
DIRECT_HTTP_LOAD_RUNS=246 DIRECT_HTTP_LOAD_DELAY=5 DIRECT_HTTP_LOAD_CONCURRENCY=40 /www/server/php/84/bin/php artisan test --compact --filter=test_opt_in_capacity_scenario
```

Python 3, extension PHP cURL/SQLite/pcntl, child process, dan bind socket loopback
diperlukan. Sandbox yang menolak socket memerlukan izin menjalankan test di luar
sandbox; kegagalan bind bukan hasil capacity aplikasi.
