# Current Handoff — Opsifin Scheduler

Last updated: **25 September 2026, Asia/Jakarta**

Ini adalah memory utama lintas sesi. Baca file ini,
[`../CLAUDE.md`](../CLAUDE.md), dan [`architecture.md`](architecture.md)
sebelum melanjutkan.

## Status terbaru — Direct Bounded HTTP

### Sesi 25 September 2026 (lanjutan) — delete, konfirmasi, dashboard, favicon

Perubahan (belum di-commit):

- **Favicon baru**: `public/images/brand/favicon.svg` (pin lokasi Opsifin + jam,
  warna brand `#005cc5`/`#ef2d2e`/`#ffd51e`), fallback `favicon.png` 64 px,
  `apple-touch-icon.png` 180 px, dan `public/favicon.ico` (16/32/48; sebelumnya
  file 0 byte). Dipasang di `AdminPanelProvider` (favicon + hook `HEAD_END`).
- **Delete Client**: `ClientDeleter` + `DeleteClientAction` (baris, header Edit)
  dan bulk `deleteClients`. Ditolak bila Client masih punya Schedule; modal
  menampilkan jumlah Schedule dan link ke Schedules terfilter Client itu.
  Alasannya FK `schedules.client_id` cascade, jadi tanpa guard Schedule ikut
  terhapus diam-diam.
- **Delete Schedule**: `ScheduleDeleter` + `DeleteScheduleAction` (baris, header
  Edit, bulk `deleteSchedules`). Ditolak/di-skip bila `running_run_id` terisi.
  Riwayat Run tetap ada (`schedule_id` null); audit lewat `DomainAuditObserver`.
- **Konfirmasi**: trait `App\Filament\Concerns\ConfirmsSave` pada semua halaman
  Edit (Client, Schedule, Task Template, User); konfirmasi juga ditambahkan ke
  toggle ikon Enabled di Schedules, `assignSelected` Task Template, dan
  `purgeOldLogs` Runs. Delete bawaan Filament sudah berkonfirmasi.
- **Dashboard**: `ServerHealth` (database ping, DB connections, dispatcher,
  direct executor/Redis, backlog, overdue running, failure rate, CPU, memory,
  disk; tiap check punya langkah mitigasi) → widget `ServerHealthPanel`;
  `RunActivity` → grafik `RunVolumeChart` (runs/jam per status) dan
  `RunLatencyChart` (start lag avg/max + durasi avg per jam); tabel
  `FailingClientsTable` (Client dengan failure 24 jam).
- Dokumen: `docs/user-guide.md`, `docs/user-guide/01-dashboard-dan-insights.md`,
  `02-clients.md`, `04-schedules.md`.

Verifikasi 25 Sep 2026:

- Test suite: **143 passed, 1 skipped (573 assertions)**. Dijalankan di image
  sekali pakai `opsifin-scheduler-test:local` (`php:8.4-cli` + intl, pcntl,
  bcmath, python3) dengan `--network none`, karena PHP host dan image app tidak
  punya `pdo_sqlite`. Perintah:
  `docker run --rm --network none -v "$PWD":/app -w /app opsifin-scheduler-test:local php artisan test`.
- `vendor/bin/pint --test` pass, `npm run build` sukses, `git diff --check` bersih.
- Rebuild + recreate container (persetujuan user) selesai ±11:27 WIB, lalu
  `optimize:clear` + `optimize`. Dicek di Chrome: dashboard (stats, Server
  health + Mitigation steps, dua grafik, tabel failure) tampil dengan data
  nyata; modal Delete Client untuk `agi` (15 Schedule) hanya menampilkan
  Cancel + "Open schedules of this client" dan link-nya memfilter Schedules;
  modal Delete Schedule tampil lalu di-Cancel (tidak ada data terhapus, total
  tetap 470). Favicon SVG/ICO ter-serve 200. Konfirmasi Save belum dicek
  visual (hanya test).
- Insiden saat rebuild:
  1. **Executor tidak berhenti saat `docker compose up` (SUDAH DIPERBAIKI).**
     Compose menunggu `stop_grace_period: 2000s`; executor lama di-`docker kill`
     atas persetujuan user setelah dicek tidak ada Run aktif. Akar masalah:
     image turunan `php:8.4-apache` mewarisi `STOPSIGNAL SIGWINCH`, jadi Docker
     tidak pernah mengirim SIGTERM (handler SIGTERM memang terpasang, tetapi
     tidak dipanggil). Direproduksi dengan image asli + MySQL sekali pakai di
     network internal: SIGWINCH → kill paksa setelah grace (exit 137); SIGTERM →
     exit 0 < 1 detik. Perbaikan: `stop_signal: SIGTERM` pada `scheduler`,
     `direct-executor`, `horizon` di `docker-compose.yml` + catatan di
     `docs/docker.md`. Terverifikasi nyata: tiga kali recreate berikutnya
     selesai 10–12 detik tanpa intervensi. Production (Supervisor
     `stopsignal=TERM`, lihat `runbook-scheduler-vps.md`) tidak terdampak.
  2. Container baru ditolak MySQL (1045) karena `.env.docker` diubah 10:40
     setelah container lama dibuat 10:34; diperbaiki user. Scheduler/QA2 down
     ±11:14–11:27 WIB; Run #271 (Schedule 2959, 11:21) `skipped` karena
     missed start window, tanpa replay.
- Minor: label chip filter di Schedules tampil "Client id: agi" (belum diubah).

Putaran kedua (25 Sep 2026, belum di-commit):

- Task Template: kartu Migration trace (legacy_job_file, gateway, needs_review,
  review_notes) disembunyikan; form jadi 3 kolom (Job template + HTTP request
  lebar, Default schedule + Timeouts di samping).
- Clients: kartu Review & notes disembunyikan. Schedules: kartu Migration trace
  disembunyikan. Data kolomnya tetap ada; filter/kolom Review di tabel tetap.
  Konsekuensi: `needs_review` tidak bisa lagi diubah dari form Client/Template.
- User management: Profile (avatar kiri, identitas kanan) lebar 2/3, Security
  1/3.
- Audit history: `AuditLogPresenter` + infolist slide-over **Details** (klik
  baris), tabel perubahan per field dengan highlight, label record dari snapshot,
  dan filter Entity.
- Audit detail juga menyamakan format nilai: string JSON di-decode seperti
  array, dan timestamp ISO UTC di snapshot `before` ditampilkan dalam waktu lokal.
- Verifikasi: 148 passed, 1 skipped (595 assertions) di image test; Pint, build,
  diff-check bersih. Sudah di-deploy ke container (rebuild ±14:00–14:20 WIB) dan
  dicek di Chrome: form Task Template dua kolom tanpa Migration trace, Profile
  user, dan panel Details audit (Entry + Changes, record `qa2 / repost · cron`).
  Form Client/Schedule yang disembunyikan hanya dicek lewat test.

### Sesi 25 September 2026 — setup Docker

- Ditambahkan `docker/` (Dockerfile PHP 8.4 + Apache, entrypoint, vhost, ini),
  `docker-compose.yml`, `.dockerignore`, `.env.docker.example`, dan
  [`docker.md`](docker.md). `.env.docker` masuk `.gitignore`.
- Container memakai network external `opsifin-main-networks` (bukan
  `opsifin-main-network`): `DB_HOST=mysql` (MySQL 5.7.31), `REDIS_HOST=opsifin-redis`.
- Terverifikasi 25 Sep: image build sukses; `web` healthy di port 8060; `/up`
  200; aset Vite 200; container menjangkau `mysql:3306` (ditolak 1045 karena
  credential DB belum diisi). Login panel masih 500 sampai DB diimpor dan
  `DB_USERNAME`/`DB_PASSWORD` diisi di `.env.docker`.
- 25 Sep: user mematikan cron aaPanel dan meng-uninstall aaPanel. Terverifikasi
  `/www` tidak ada, crontab user kosong, tidak ada proses artisan worker di host
  (crontab root tidak dicek). Path `$PHP=/www/server/php/84/bin/php` di
  `CLAUDE.md` sudah tidak valid; PHP host sekarang `/bin/php` 8.4.
- Belum dijalankan: profile `workers` (scheduler + direct executor), import DB,
  migration, test suite terhadap MySQL 5.7.
- Setup Docker di-commit sebagai `5286229`, lalu `master` di-fast-forward ke
  branch `feat/direct-bounded-http-migration` dan di-push ke `origin/master`.
  Branch feature dihapus di lokal dan origin. Mulai sesi ini kerja di `master`;
  catatan "working tree belum di-commit" di bagian arsip handoff sudah basi.

### Sesi 14 September 2026 — QA2 Schedule 2959 diaktifkan user

User mengaktifkan sendiri Schedule **ID 2959** dan memasang aaPanel Cron
`schedule:run`, sehingga scheduler kini benar-benar mengirim trafik.

```text
Client    qa2   https://qa2.fin-svc-barto.net
Task      repost   POST /apiv_g/api_repost
Cron      1-59/3 * * * *  (Asia/Jakarta) — tiap 3 menit
Timeout   60 s, connect 10 s, overlap guard aktif
```

Ini satu-satunya Schedule enabled dari 470. Hasil ±40 menit pertama: 20 Run,
19 `succeeded` HTTP 200, 1 `failed` dengan `cURL error 28: Resolving timed out`
(DNS sesaat, bukan aplikasi). Start lag berkisar 99–957 ms, jauh di dalam start
window 55 detik.

Catatan di arsip 11 September yang menyatakan ID 2959 harus tetap disabled
**sudah tidak berlaku**. Environment development ini harus diperlakukan sebagai
sudah mengirim request nyata ke QA2. Untuk menghentikan pengiriman tanpa
mematikan service, pause Schedule 2959 dari menu Schedules.

Runtime yang dipakai user: aaPanel Cron untuk `schedule:run` (menu Cron bawaan,
tidak memerlukan plugin), dan `jobs:work-direct` masih dijalankan manual.
Supervisor Manager belum terpasang, sehingga executor belum pulih sendiri setelah
reboot atau OOM. Langkah pemasangannya ada di
[runbook aaPanel](runbook-scheduler-aapanel.md).

### Sesi 14 September 2026 — fitur hapus execution log

Ditambahkan atas permintaan user. Penghapusan riwayat eksekusi kini tersedia dari
UI, **hanya untuk Administrator**, dan hanya pada Run yang sudah terminal.

Tiga entry point:

| Aksi | Letak | Cakupan |
| --- | --- | --- |
| `delete` | menu Actions per baris dan header halaman detail Run | satu Run |
| `deleteLogs` | Bulk actions | record terpilih |
| `purgeOldLogs` | header halaman Execution logs | lebih tua dari N hari |

Perubahan file:

- `app/Services/Maintenance/RunLogDeleter.php` baru. Menghapus di dalam
  transaction dengan `lockForUpdate`, menolak Run non-terminal, melepas slot
  overlap basi, dan mencatat `AuditLog` action `deleted` sebelum baris hilang.
  Snapshot audit sengaja tidak menyalin `response_excerpt` dan `error_message`
  karena keduanya dapat memuat pesan endpoint.
- `RunPolicy::delete()` menjadi `canManage() && status->isTerminal()`, dan
  `deleteAny()` baru untuk bulk action. Sebelumnya `delete()` selalu `false`.
- `RunsTable` mendapat record action `delete` dan bulk action `deleteLogs`.
- `ListRuns::getHeaderActions()` mendapat `purgeOldLogs` dengan input jumlah hari,
  default dari `CRON_RUNS_RETENTION_DAYS`. Ditempatkan sebagai header action,
  bukan toolbar tabel, agar konsisten sebagai aksi level halaman.
- `ViewRun` mendapat header action `delete` yang redirect ke index setelah sukses.

Alasan guard non-terminal: `schedules.running_run_id` **tidak memiliki foreign
key** (diverifikasi lewat `SHOW CREATE TABLE`). Menghapus Run yang masih
`running` akan meninggalkan slot menggantung, dan overlap guard akan menganggap
Run lama masih aktif selamanya sehingga seluruh occurrence berikutnya pada
Schedule tersebut menjadi `skipped`. Pelepasan slot basi tetap dilakukan sebagai
jaring pengaman.

Perbaikan terkait: `RetentionService` sebelumnya hanya mengecualikan `queued` dan
`running`, sehingga Run `pending` — status hidup pada driver direct — ikut masuk
kandidat purge. Sekarang ketiganya dikecualikan lewat konstanta `LIVE_STATUSES`.
Ini juga memperbaiki `cron:purge-runs` yang berjalan tiap pukul 03:00.

Verifikasi:

```text
RunLogDeletionTest      11 passed, 44 assertions
artisan test            131 passed, 492 assertions, 1 skipped
Laravel Pint            passed
npm run build           passed
git diff --check        passed
```

Test mencakup audit tercatat, penolakan `pending`/`queued`/`running` lewat data
provider, pelepasan slot basi, bulk yang melewati Run hidup, matriks role
Admin/Operator/Viewer, ketiga aksi melalui Livewire, serta purge yang menyisakan
occurrence hidup dan yang masih baru.

Verifikasi browser sebagai Administrator: tombol **Delete old logs** tampil di
header dan **Delete selected logs** tampil pada menu Bulk actions. Aksi per baris
tidak sempat diperiksa secara visual karena kolom Actions berada di luar layar dan
dropdown-nya tidak merespons klik via script; pembuktiannya memakai Livewire test
`callTableAction('delete', ...)` yang benar-benar menghapus record. Tidak ada log
QA2 yang dihapus selama pemeriksaan.

Dokumentasi pengguna diperbarui di `docs/user-guide/05-execution-logs.md`.

### Sesi 14 September 2026 — full stack dinyalakan di local

Scheduler dan direct executor sudah berjalan di WSL dan terverifikasi end-to-end.
Keduanya dijalankan sebagai proses background milik sesi agent, **bukan service**,
jadi akan berhenti ketika sesi berakhir. Supervisor/cron aaPanel masih perlu
dipasang user untuk membuatnya permanen.

Yang dijalankan:

```bash
php artisan jobs:work-direct   # daemon direct executor
php artisan schedule:work      # pengganti cron; menjalankan schedule:run tiap menit
```

`schedule:work` sengaja dipakai sebagai pengganti system cron karena menambah
entry cron memerlukan sudo. Redis/Horizon tidak dinyalakan dan memang tidak
diperlukan pada driver `direct`.

Hasil verifikasi 14 September 2026 pukul 11:16–11:18 WIB:

```text
executor_online            true   (heartbeat tiap 15 detik, lease 45 detik)
dispatcher_online          true   (heartbeat tiap menit dari jobs:dispatch-due)
pool_capacity              20
missed_start_window_24h    0
expired_pending/running    0 / 0
```

Smoke test end-to-end memakai endpoint loopback `127.0.0.1:8099`, bukan endpoint
Client asli. Fixture sementara (Client `smoke-local`, Task Template
`smoke_local_ping`, satu Schedule disabled, satu Run manual) dibuat, dieksekusi,
lalu dihapus seluruhnya. Hasil Run:

```text
status        succeeded
http_status   200
duration_ms   8
start_lag_ms  489      (window 55 detik, jauh di dalam batas)
response      tersimpan dan melewati redaction
```

Setelah cleanup, jumlah data kembali persis seperti sebelumnya: 41 Client,
20 Task Template, 470 Schedule (0 enabled), 5 Run. Endpoint loopback dihentikan.

Penting: seluruh Schedule masih disabled, sehingga `jobs:dispatch-due` memang
tidak memproduksi occurrence apa pun (`Scanned 0`). Tidak akan ada HTTP ke
endpoint Client sampai user mengaktifkan Schedule. Mengaktifkan Schedule tetap
memerlukan persetujuan user sesuai aturan di `CLAUDE.md`.

Kendala memori WSL — 14 September 2026 pukul 11:38 WIB. Kedua daemon berjalan
20 menit lalu dimatikan sistem karena WSL kehabisan memori. Shutdown berlangsung
**bersih**: lease `executor_states.direct` dilepas (`owner` dan `expires_at`
menjadi null), tidak ada Run yang nyangkut di `pending`/`running`, dan metrics
terakhir tercatat `settled 1, started 1, max_active 1`. Ini sekaligus bukti jalur
SIGTERM dan blok `finally` pada `DirectPoolExecutor` bekerja seperti desain.

Penyebabnya bukan aplikasi. Daemon PHP CLI hanya puluhan MB; tekanan datang dari
IDE pada WSL yang sama:

```text
total RAM WSL          9,7 GB
MainThread (VS Code)   5,5 GB
devsense.php.ls        1,1 GB
claude                 0,6 GB
available saat mati    920 MB, turun ke 253 MB setelah restart
```

Percobaan restart kedua pukul 11:38 dimatikan lagi dalam hitungan menit, dengan
shutdown yang sama bersihnya (lease dilepas, 0 Run nyangkut). Setelah keduanya
mati, memori bebas justru turun ke 157 MB, yang menunjukkan konsumennya memang
bukan aplikasi. Restart ketiga tidak dilakukan: memulai proses PHP baru pada sisa
157 MB berisiko membuat kernel OOM killer memilih korban lain seperti MySQL atau
php-fpm.

Prasyarat Supervisor sudah diverifikasi dan tidak ada yang menghalangi:

```text
user www                 ada (uid 1001), sama dengan pool php-fpm
/home/aditya_prasetyo    drwxr-x--x  www dapat menembus (bit x untuk others)
project root             drwxr-xr-x  www dapat membaca
storage, bootstrap/cache drwxrwsr-x group www, setgid  www dapat menulis
.env                     -rw-r----- aditya_prasetyo:www  www dapat membaca
```

Artinya `user=www` pada `deploy/aapanel/supervisor-direct-executor.conf.template`
sudah benar dan template dapat dipakai apa adanya tanpa penyesuaian path.

Karena itu menjalankan daemon sebagai proses sesi tidak dapat diandalkan di
environment ini. Opsi perbaikan: turunkan pemakaian IDE (tutup window VS Code
berlebih atau restart PHP language server), naikkan batas memori WSL lewat
`%USERPROFILE%\.wslconfig` (`[wsl2]` `memory=12GB`), dan yang paling penting
jalankan daemon lewat Supervisor dengan `autorestart=true` agar pembunuhan oleh
OOM pulih sendiri tanpa intervensi.

Untuk membuat runtime ini permanen, user perlu memasang melalui aaPanel:

1. **Supervisor Manager** → tambah program dari
   `deploy/aapanel/supervisor-direct-executor.conf.template`
   (`jobs:work-direct`, user `www`, 1 process, autostart, autorestart,
   `stopwaitsecs=2000`).
2. **aaPanel Cron** → task Shell Script, setiap 1 menit, user `www`:
   `cd /home/aditya_prasetyo/project/opsifin-crontab && /www/server/php/84/bin/php artisan schedule:run`
   Jangan menulis `* * * * *` di dalam textarea dan jangan membuat cron per job.
3. Setelah keduanya hidup, hentikan proses sementara milik sesi agent agar tidak
   ada dua executor; daemon kedua sebenarnya aman karena lease membuatnya standby,
   tetapi lebih bersih dijalankan satu saja.

### Sesi 14 September 2026 — panel dikembalikan ke tampilan bawaan Filament

Atas instruksi user, seluruh tema kustom panel dibuang dan diganti tampilan
bawaan Filament. Appearance switcher dipertahankan tetapi dibentuk ulang
mengikuti panel "Filament themes" milik Filament sendiri.

Keputusan user pada sesi ini:

1. Cakupan = **Filament 100% default**. `->colors()` dihapus, sehingga warna
   semantik dan primary kembali ke bawaan (primary amber). `->sidebarWidth('18rem')`
   dan `->maxContentWidth(Width::Full)` juga dihapus; content kembali ke lebar
   default Filament. Logo, `brandName`, `brandLogoHeight`, dan favicon Opsifin
   tetap dipertahankan.
2. Palette switcher (Opsifin/Ocean/Forest/Sunset) **dipertahankan**, bersama
   mode Light/Dark/System.

Perubahan file:

- `resources/css/filament/admin/theme.css` ditulis ulang. Seluruh restyle chrome
  dibuang: import Montserrat, gradient sidebar/topbar/login, grid overlay login,
  stat card berwarna per posisi, hover translate section, restyle tabel, dan
  token `--surface-*`/`--navbar-*`/`--icon-accent`. Yang dipertahankan adalah
  blok viewer diagram User Guide (`.fi-user-guide-diagram`, `.fi-guide-*`) persis
  seperti sebelumnya, karena ia fungsional dan punya regression browser.
- Palette sekarang hanya menukar ramp `--primary-*`. Keempatnya ditulis eksplisit
  sebagai oklch memakai pasangan lightness/chroma milik generator Filament dengan
  hue yang dihitung dari hex brand: opsifin 248,814 (#2196f3), ocean 210,817
  (#00bcd4), forest 144,208 (#4caf50), sunset 64,054 (#ff9800). Sempat dicoba
  meminjam `--info-*`/`--success-*`/`--warning-*`, tetapi ramp semantik bawaan
  Filament punya hue sendiri sehingga warna brand bergeser (sunset menjadi 70,08).
- `appearance-switcher.blade.php` dibentuk ulang: header Appearance + subteks +
  tombol close, section "Color scheme" berupa segmented control Light/Dark/System,
  dan section "Color palette" berupa grid 2×2 kartu dengan swatch dan radio.
  Label mode `Auto` berganti menjadi `System`. Popover tetap diposisikan absolut
  terhadap tombol, bukan `x-filament::dropdown`, agar overflow kanan tidak kembali.
- Trigger bukan lagi icon button di topbar melainkan floating action button
  bundar 3,25rem di kanan bawah viewport, dan popover membuka ke atas dari
  tombol itu. Render hook `TOPBAR_END` + `SIMPLE_LAYOUT_START` diganti satu
  `BODY_END`, yang mencakup panel maupun halaman login. Warna FAB memakai
  `--primary-600`, jadi ikut berubah mengikuti palette yang dipilih.
  `z-index` FAB adalah 20: di bawah sidebar mobile (30) dan modal (40) agar
  tidak mengambang di atas overlay, tetapi tetap di atas konten halaman.
- `appearance-init.blade.php` tidak berubah; factory `window.opsifinAppearance`
  tetap berada di file terpisah sesuai keputusan 20 Agustus.
- Warna popover memakai token gray Filament lewat variabel lokal `--op-*`,
  dengan override `:where(.dark)`, sehingga menyatu dengan tema bawaan.
- `AdminPanelTest` disesuaikan: assertion `Auto` menjadi `System`, ditambah
  assertion `Color scheme`.

Jebakan yang ditemukan dan sudah diperbaiki: komentar CSS yang memuat
`--info-*/--success-*` mengandung urutan `*/` sehingga menutup blok komentar
lebih awal dan menelan rule palette `opsifin` tanpa error build. Gejalanya hanya
terlihat di browser (palette Opsifin jatuh ke amber bawaan), bukan di test.

Verifikasi 14 September 2026:

```text
artisan test                     120 passed, 448 assertions, 1 skipped
AdminPanelTest                   29 passed, 123 assertions
Laravel Pint --test              passed
npm run build                    passed
git diff --check                 passed
```

Verifikasi browser pada `opsifin-cron.local/admin` memakai sesi login yang sudah
ada di browser user: sidebar/topbar/stat card tampil bawaan Filament tanpa
gradient, popover Appearance tampil sesuai referensi pada light dan dark, keempat
ramp palette terbaca benar melalui computed style, `window.opsifinAppearance`
bertipe function, dan console tidak memuat error. FAB terverifikasi
`position: fixed`, `z-index: 20`, 52×52 px, jarak 24 px dari tepi kanan-bawah,
anak langsung `body`, sudah tidak ada di topbar, dan latarnya mengikuti palette. Preferensi localStorage user
dikembalikan ke nilai semula (`opsifin-palette=sunset`, `theme=light`) setelah
pengujian. Tidak ada perubahan data, status schedule, atau service.

Catatan untuk user: karena `->colors()` dihapus, warna primary sekarang
sepenuhnya ditentukan palette. Bila diinginkan amber bawaan Filament tersedia
sebagai pilihan eksplisit, tinggal ditambah satu opsi "Default" yang tidak
meng-override `--primary-*`.

### Sesi 14 September 2026 — orientasi ulang dan perbaikan test environment

Sesi ini hanya melakukan orientasi repo, satu perbaikan konfigurasi test, dan
pembuatan `CLAUDE.md`. Tidak ada perubahan pada `.env`, database aplikasi,
service, status schedule, maupun commit/deploy.

- `CLAUDE.md` dibuat di root repo sebagai entry point agent: aturan keras
  (larangan `migrate:fresh`, enable schedule, ubah cron legacy, commit/deploy,
  dan request ke endpoint Client asli), daftar perintah beserta binary PHP 8.4,
  ringkasan arsitektur dan file kunci, status migrasi direct, knob konfigurasi,
  konvensi kode, dan peta dokumentasi.
- Ditemukan bahwa `artisan test` gagal 5 test bila `.env` developer memakai
  `CRON_EXECUTION_DRIVER=direct`, karena `phpunit.xml` tidak mem-pin driver
  sehingga test jalur queue mewarisi nilai `direct`. Yang gagal adalah
  `DueScheduleDispatcherTest` (2), `QueuedRunCancellerTest` (2), dan
  `WorkDirectRunsCommandTest::test_queue_mode_stands_by_without_fetching_work`.
  Ini bukan regresi kode: dengan `CRON_EXECUTION_DRIVER=queue` suite lulus penuh.
- Perbaikan: `phpunit.xml` sekarang mem-pin `CRON_EXECUTION_DRIVER=queue`.
  Test jalur direct tetap meng-override sendiri lewat `config([...])`
  (`DirectPoolExecutorTest`, `DirectHttpIntegrationTest`,
  `WorkDirectRunsCommandTest`) atau env child process
  (`DirectExecutorProcessTest`), sehingga tidak terpengaruh pin ini.

Verifikasi 14 September 2026 pada WSL dengan `/www/server/php/84/bin/php`:

```text
artisan test (dengan .env driver=direct)   120 passed, 447 assertions, 1 skipped
Laravel Pint --test                        passed
git diff --check                           passed
artisan jobs:direct-status                 jalan; alert executor + dispatcher offline
artisan jobs:work-direct --once            started 0, settled 0 (tidak ada pending Run)
artisan jobs:dispatch-due                  Scanned 0; queued 0; pending 0; skipped 0
```

Kedua no-op di atas aman karena seluruh schedule masih disabled; tidak ada HTTP
yang dikirim ke endpoint mana pun. Satu skipped adalah capacity test opt-in.

State runtime yang dikonfirmasi ulang: migration `2026_09_09_000001` sudah Ran
(batch 9); database development berisi 41 Client, 20 Task Template, 470 Schedule
(0 enabled), 5 Run, tanpa Run pending/running; `.env` development memakai
`CRON_EXECUTION_DRIVER=direct` sedangkan default `config/opsifin_cron.php` tetap
`queue`. Tidak ada `supervisord`, `queue:work`, `jobs:work-direct`, atau cron
`schedule:run` yang berjalan. Heartbeat `executor_states` terakhir 11 September
14:04, sudah basi.

Belum dikerjakan dan masih menunggu keputusan user: menyalakan Supervisor dan
cron development (butuh akses sudo/aaPanel), smoke test Run Now ke endpoint yang
user nyatakan harmless, pengukuran endpoint/resource pada VPS target, serta
commit working tree.


### Recovery database development — 11 September 2026

User tidak sengaja menjalankan `migrate:fresh` pada database development
`opsifin_cron` sekitar 10:38 WIB. MySQL binary log sedang OFF, sehingga tidak
tersedia point-in-time recovery. Recovery dilakukan tanpa memakai database
production dan tanpa mengaktifkan Schedule:

- kondisi sesudah insiden diamankan ke
  `storage/app/backups/pre-recovery-after-migrate-fresh-20260911-1045.sql`
  (SHA-256 `ba0975848a854a370615cfc3f78e6f543ed3203a5cdeccdb9000eda7a2df774d`);
- baseline dipulihkan dari dump SQLyog 19 Agustus 2026 di
  `C:/Users/Aditya/Downloads/db-opsifin-scheduler/opsifin-scheduler.sql` pada
  instance MySQL sementara di `/tmp`, bukan langsung pada database aktif;
- empat migration pending dijalankan pada salinan sementara. Satu Client
  `Firman Travel` dan 20 Schedule yang hanya tersisa dalam snapshot pascainsiden
  digabungkan setelah pemeriksaan FK dan kesamaan record;
- QA2 `repost` Schedule ID 2959 direkonstruksi dengan cron
  `1-59/3 * * * *`, tetapi tetap disabled dan `next_run_at=null`;
- dump recovery tervalidasi tersimpan di
  `storage/app/backups/recovered-opsifin-cron-20260911.sql`
  (SHA-256 `75ab8c3b6859de46461d9417728820f0e815bddbf7ac8ab8f4b118e04a2b0e3c`);
- round-trip import, `CHECK TABLE`, migration status, relasi, dan duplicate
  matrix lulus sebelum dump diterapkan ke database aktif;
- hasil aktif: 41 Client, 20 Task Template, 470 Schedule (0 enabled), 5 Run,
  1 User, dan 11 Audit log; orphan Client/Template = 0;
- aplikasi sudah keluar dari maintenance mode, `/admin/login` HTTP 200, dan
  sinyal restart queue worker sudah dikirim. MySQL recovery sementara dihentikan
  bersih; data directory-nya sementara masih ada di
  `/tmp/opsifin-mysql-recovery-20260911`.

Riwayat Run dan perubahan lain setelah dump 19 Agustus yang tidak tersisa pada
snapshot pascainsiden tidak dapat direkonstruksi secara lengkap. Jangan memakai
`migrate:fresh`, `migrate:refresh`, atau `db:wipe` pada database berisi data.
Gunakan PHP 8.4 `artisan migrate` untuk hanya menjalankan migration pending.
Scheduler cron dan direct executor masih offline; selesaikan service setup
sebelum mengaktifkan kembali QA2 ID 2959.
**Sudah tidak berlaku sejak 14 September 2026** — lihat bagian sesi 14 September
di atas; Schedule 2959 kini enabled dan aktif mengirim.

User meminta melanjutkan eksekusi yang terhenti karena token limit. Working tree
awal sudah berisi implementasi compatibility direct HTTP, tetapi laporan validasi
belum tersedia dan rencana masih menyatakan implementasi belum dimulai.

Implementasi dan validasi lokal sekarang selesai:

- Shared `RunExecutionLifecycle` dipakai queue dan direct: claim, validasi,
  overlap slot, completion, redaction, serta deadline recovery tanpa resend.
- `jobs:work-direct` menjalankan rolling cURL pool dengan DB lease, heartbeat,
  start window 55 detik, bounded response, SIGTERM drain, dan SIGKILL recovery.
- Manual Run direct masuk `pending`; Retry direct ditolak; cancellation pending
  tersedia. Schedule harus tetap enabled saat direct executor mengambil Run.
- Health command/dashboard menampilkan pending/running, heartbeat, slot, start
  lag dan missed window. Skipped akibat window tetap memberi alert selama 24 jam.
- Credential JSON/URL-encoded, Authorization token, nilai `0`, dan credential
  yang melintasi batas excerpt ditangani oleh redaction dan regression tests.
- Migration compatibility dan template Supervisor VPS/aaPanel tersedia.
- Full suite: **120 passed, 447 assertions, 1 opt-in capacity test skipped**.
  Test opt-in dijalankan terpisah. Pint, Vite build, dan `git diff --check` lulus.
- Peak 123 × 5 detik dengan C=20: 123 berhasil; p99 internal 31,570 detik,
  p99 endpoint 31,571 detik. Projected 246 × 5 detik dengan C=40: 246 berhasil;
  p99 internal 31,251 detik, p99 endpoint 31,253 detik. Tidak ada missed window.
- Process drill menguji SIGTERM, SIGKILL, dan daemon kedua standby. SQLite dan
  endpoint loopback terisolasi dipakai; tidak mengirim request ke Client asli.
- Dokumentasi aplikasi sudah dimodularisasi: `docs/user-guide.md` menjadi
  landing end-to-end, detail module berada di `docs/user-guide/*.md`, dan
  `artifact-teknis-opsifin-scheduler.md` versi 2.0 mendokumentasikan Direct HTTP,
  compatibility queue, data model, sequence/state diagram, security, capacity,
  observability, deployment, dan rollback.
- Menu **Help → User guide** merender fenced block Mermaid menjadi diagram SVG
  responsif melalui entry Vite yang dimuat khusus pada halaman panduan. Renderer
  memakai paket lokal, mengikuti light/dark mode, dan tidak bergantung pada CDN.
- Renderer diagram diperbaiki memakai SVG-as-image dengan font lokal dan ukuran
  intrinsik; CSS panel tidak memengaruhi label/geometri. Viewer menyediakan zoom,
  Lihat semua, 100%, dan Layar penuh. Regression Chromium terisolasi tersedia di
  `tests/Browser/` untuk semua 14 diagram pada desktop/tablet/mobile serta light/dark.
- Perbaikan clipping lanjutan: ukur ulang viewBox dari konten SVG final + padding,
  fullscreen otomatis fit seluruh alur, dan panduan end-to-end disusun dalam
  tiga tahap. Regression meliputi containment setiap node/label, fullscreen
  setiap diagram desktop, serta device scale 125%.
- Validasi lanjutan 11 September selesai: 24 skenario Chromium / 112 render,
  ditambah 6 skenario Chrome Windows / 28 render pada device scale 125%
  (zoom browser 100%). Seluruh label/node terlihat berada dalam viewBox;
  zoom, fit-all, fullscreen, light/dark, dan Escape lulus. Screenshot diagram
  overview, ER, dan sequence turut diperiksa secara visual.
- ResizeObserver kini hanya merespons perubahan lebar dan menjadwalkan write
  pada frame berikutnya, sehingga tidak memicu loop akibat perubahan tinggi
  gambar sendiri. Transisi ukuran gambar dimatikan agar zoom/fit langsung akurat,
  termasuk saat stylesheet global reduced-motion aktif. Alur end-to-end memakai
  tiga kotak tahap dengan konektor langsung, tanpa nested cluster.
- Build Vite, 29 AdminPanel tests / 122 assertions, dan `git diff --check` lulus.
  Aset JS/CSS di `opsifin-cron.local` HTTP 200 dan hash-nya sama dengan build lokal.
  Browser memakai HTML Laravel/Filament dari fixture terisolasi, bukan login
  menggunakan akun aplikasi nyata. Tidak ada perubahan status schedule.

Dokumen acuan saat ini:

- [Index dokumentasi](README.md)
- [Panduan pengguna dan module UI](user-guide.md)
- [Artifact teknis end-to-end](artifact-teknis-opsifin-scheduler.md)
- [Rencana dan checklist migrasi](direct-bounded-http-migration-plan.md)
- [Laporan validasi dan batas pengujian](direct-http-validation.md)
- [Deployment, operasi, dan rollback direct](direct-http-operations.md)
- [Arsitektur aplikasi](architecture.md)

### Runtime dan pekerjaan berikutnya

Default tetap `CRON_EXECUTION_DRIVER=queue`; Redis/Horizon dipertahankan selama
compatibility/rollback window. Jalur Run direct tidak memublikasikan queue.
Sesi lanjutan ini tidak mengubah `.env`, menjalankan migration pada database
aplikasi, menyalakan service, mengaktifkan schedule, atau deploy production.
Working tree belum di-commit/push.

1. Ukur durasi endpoint dan resource pada VPS/MySQL yang dituju. Hasil SQLite
   loopback tidak membuktikan kapasitas production. Semua request berdurasi
   timeout 60 detik akan melampaui kapasitas 20 slot untuk peak 123.
2. Siapkan pilot dan production cutover sesuai runbook, lalu monitor satu peak
   lengkap dan soak. Belum ada hasil cutover/rollback infrastruktur production.
3. Setelah soak dan instruksi penghapusan, audit pemakaian Redis lain lalu
   decommission Horizon/queue melalui release terpisah.

Instruksi user untuk melanjutkan implementasi sudah berlaku; jangan kembali
meminta finalisasi planning hanya karena membaca gate lama. Untuk production,
ikuti scope dan keputusan deployment yang dikonfirmasi user pada sesi tersebut.

## Arsip keputusan 19–20 Agustus 2026

Bagian di bawah menyimpan konteks historis. Status database, angka test, queue
database, dan daftar pekerjaan lamanya tidak menggantikan update September di atas.

### Keputusan yang berlaku saat itu

- Arsitektur aktif adalah Lean Laravel Scheduler.
- Production memakai VPS manual tanpa aaPanel.
- WSL + aaPanel hanya untuk development.
- Job dibuat sekali lalu di-assign ke client dari UI; tidak perlu menambah code
  atau crontab pada setiap client.
- Tidak ada automatic retry, full catch-up, runtime override, incident engine,
  atau internal watchdog.
- Jangan enable schedule atau mengubah legacy cron tanpa persetujuan user.

## Status implementasi

Sudah selesai:

- `schedule:run` → `jobs:dispatch-due` → database queue → HTTP worker;
- one-running-slot per schedule dan deadline recovery;
- success/failed/skipped history, Run now, dan Retry manual;
- seluruh row action tabel dikelompokkan dalam ActionGroup;
- overlap guard per schedule aktif secara default untuk skip occurrence ketika
  run sebelumnya masih aktif;
- menu Runs diperjelas menjadi Execution logs dan Audit history sudah dapat
  merender perubahan JSON yang berisi boolean/nested value;
- Assign all active clients;
- Assign selected clients;
- Remove from selected clients;
- Set cron in bulk;
- Pause dan Resume in bulk;
- password/credential input hidden, revealable, dan tidak mengirim secret lama
  ke browser saat edit;
- redaction response/error/request preview;
- importer memakai `crontab-legacy/jobs/*.sh` sebagai satu-satunya katalog
  canonical dan tidak membuat varian per client;
- schema unique client × task × cron expression dan due index;
- VPS serta aaPanel development templates.

Automated UI tests benar-benar memanggil seluruh bulk action melalui Filament
Livewire, bukan hanya menguji service di bawahnya.

## Dokumentasi kanonik

Hanya enam file berikut yang dipertahankan:

```text
docs/architecture.md       arsitektur dan flow developer
docs/user-guide.md         cara memakai UI
docs/installation.md       development WSL + aaPanel
docs/deployment-vps.md     production VPS manual
docs/operations.md         runbook, troubleshooting, import, cutover
docs/handoff.md            state pekerjaan terbaru
```

Dokumen V2/historis/duplikat sudah dihapus agar tidak menjadi sumber instruksi
yang bertentangan.

## Database development saat ini

Fresh import canonical jobs **sudah diterapkan** pada 19 August 2026:

```text
clients                    40
task templates             20
canonical files in jobs/   20
schedules                 450
runs with overlap guard   450
runs                        0
enabled schedules           0
next_run_at terisi           0
database queue payloads      0
duplicate client/task/cron   0
multi-timing client/tasks   30
```

Safety boundary aman: seluruh schedule paused dan legacy cron tidak diubah.

Migration yang sudah Ran:

```text
2026_08_18_000001_prepare_lean_scheduler
2026_08_18_000002_enforce_lean_schedule_uniqueness
2026_08_19_000001_allow_multiple_timings_per_client_job
2026_08_19_000002_add_overlap_guard_to_schedules
```

Database development mungkin masih memiliki tabel/kolom V2 lama secara fisik;
runtime lean tidak membacanya. Fresh production database hanya membuat schema
aktif.

## Backup sebelum fresh import

```text
File   storage/app/backups/before-lean-fresh-import-20260818-102937.sql
Mode   600
Size   269721 bytes
Tables 23
SHA256 1633adf6d4dd3766ea52e8b4525f47461e8801d079791dd6518b8eb175d519b8
```

Dump selesai normal dan memiliki end marker. Jangan commit atau membagikan file
backup karena berisi data database terenkripsi dan metadata internal.

## Import report dan verifikasi

Apply report terbaru:

```text
storage/app/import-reports/canonical-jobs-apply-20260819.md
```

File bermode `600` dan di-ignore Git.

Verifikasi katalog template terhadap `crontab-legacy/jobs/`:

```text
20 exact match
 0 different
 0 skipped
```

Finding aktif:

| Severity/category | Jumlah | Status |
| --- | ---: | --- |
| Error: active task tidak ada di `jobs/` | 19 | Tidak dibuatkan template; tambah file canonical atau hapus cron legacy |
| Error: credential drift | 5 | Harus konfirmasi credential yang benar |
| Error: unresolved URL variable | 2 | QA env variable tidak tersedia pada source |
| Error: dangling URL | 1 | Script legacy memang malformed |
| Warning: commented task tidak ada di `jobs/` | 24 | Kandidat cleanup; tidak dibuatkan schedule |
| Warning lainnya | 16 | Folder/script/client legacy tidak tersedia atau base URL conflict |
| Info: script berbeda dari canonical | 227 | Expected; definisi `jobs/` tetap dipakai |

Tidak ada perbaikan otomatis yang aman untuk 27 error tersebut; source atau
keputusan bisnis tambahan diperlukan. Importer sengaja tidak menebak task yang
tidak mempunyai file canonical.

`cron:cutover-status` saat ini melaporkan 27 dari 40 client configuration-ready.
13 client blocked/review: aladin, anta, demo, globalwisata, gns, kia,
kiaxxxharmoni, pij, psa, psa-gw, qa1, qa2, dan qaAladin.

## Import safety guard

`cron:import` sekarang menolak database domain yang sudah berisi data kecuali
`--fresh` diberikan. Ini mencegah re-import ambigu.

```bash
# Selalu backup lebih dahulu
php artisan cron:import --fresh --dry-run --report=/tmp/lean-import.md
php artisan cron:import --fresh --report=storage/app/import-reports/lean-apply.md
```

Initial import pada database domain kosong tetap dapat dijalankan tanpa
`--fresh`.

## Verifikasi terakhir

```text
Pint                         passed
PHPUnit                      52 tests, 201 assertions, all passed
Vite production build        passed
Laravel schedule:list        dispatch tiap menit + purge pukul 03:00
GET /admin/login              HTTP 200
Login password               hidden by default, Livewire loaded
Importer apply/verify         passed
Enabled/run/queue             0 / 0 / 0
```

## Runtime development yang masih perlu dinyalakan

Audit process terakhir tidak menemukan `supervisord`, `queue:work`, atau daemon
cron. Nginx/login tetap hidup. Akses `/etc/supervisor` memerlukan sudo password,
jadi agent tidak dapat menyalakannya dari sesi ini.

Melalui aaPanel:

1. Buka **App Store**, install/buka **Supervisor Manager**.
2. Hentikan konfigurasi Opsifin lama yang memakai queue `high,default,slow`
   bila masih ada.
3. Tambahkan program dari `deploy/aapanel/supervisor-worker.conf.template`:

   ```text
   Name       opsifin-scheduler-worker
   Directory  /home/aditya_prasetyo/project/opsifin-crontab
   User       www
   Processes  2
   Autostart  yes
   Restart    yes
   ```

4. Start command harus satu baris:

   ```bash
   /www/server/php/84/bin/php artisan queue:work database --queue=default --sleep=1 --tries=1 --timeout=1900 --max-time=3600
   ```

5. Pastikan dua process worker berstatus **RUNNING**.
6. Buka **aaPanel Cron**, buat task type **Shell Script**, period **setiap 1
   menit**, execute user `www`, dengan script satu baris:

   ```bash
   cd /home/aditya_prasetyo/project/opsifin-crontab && /www/server/php/84/bin/php artisan schedule:run
   ```

7. Jangan menulis `* * * * *` di textarea script dan jangan membuat cron per
   job/client. Hanya satu task `schedule:run`.

Verifikasi setelah dinyalakan:

```bash
ps -ef | rg 'queue:work|supervisord'
CACHE_STORE=array /www/server/php/84/bin/php artisan schedule:list
```

Setelah worker hidup, lakukan Run now hanya pada endpoint harmless dan pastikan
status berubah `queued → running → succeeded/failed`. Seluruh schedule tetap
paused sampai user menyetujui cutover.

## Pekerjaan berikutnya

1. Nyalakan Supervisor worker dan cron development melalui aaPanel/root.
2. Review 52 import errors bersama pemilik source legacy.
3. Dapatkan `gateway.sh`, folder `jobs/`, dan QA env definition bila memang masih
   digunakan.
4. Putuskan credential yang benar untuk `qa1/update_balance_trx.sh` dan empat
   credential drift lain.
5. Manual browser walkthrough, lalu Run now hanya pada endpoint harmless.
6. Jangan enable massal; cutover per client/job group minimal dua siklus.
7. Production deployment dan Git commit belum dilakukan.

## Environment dan working tree

```text
Project       /home/aditya_prasetyo/project/opsifin-crontab
Legacy source /home/aditya_prasetyo/project/crontab-legacy
PHP           /www/server/php/84/bin/php
Panel URL     http://opsifin-cron.local/admin
Queue         database/default
```

Working tree berisi rewrite besar dan belum di-commit. Jangan melakukan hard
reset atau mass checkout.

## Prompt sesi berikutnya

> Baca `docs/handoff.md` dan `docs/architecture.md`. Task pertama adalah
> menyalakan dua Supervisor worker dan satu aaPanel Cron `schedule:run` sesuai
> bagian "Runtime development yang masih perlu dinyalakan", lalu lakukan smoke
> test Run now pada endpoint harmless. Jangan enable schedule, mengubah legacy
> cron, deploy production, atau commit tanpa persetujuan saya.

## Memory update — preparation deployment VPS (19 Agustus 2026)

Keputusan deployment terbaru:

1. Production tetap memakai database queue dan dua Supervisor worker. Horizon,
   Predis, dan Redis tidak digunakan.
2. Web server production memakai Apache2 + PHP 8.4-FPM, bukan Nginx. Template
   resminya `deploy/vps/apache-vhost.conf.template`.
3. Linux service user dan primary group production adalah
   `opsifin_admin:opsifin_admin`.
4. Domain bukan prasyarat instalasi. Fase awal boleh memakai IP LAN/public atau
   URL HTTPS forwarder; setelah stabil baru ubah `ServerName`, `APP_URL`, session
   secure cookie, dan TLS ke domain final.
   Untuk HTTPS forwarder, samakan `ASSET_URL` dengan origin HTTPS, percaya hanya
   IP/CIDR forwarder melalui `TRUSTED_PROXIES`, dan pastikan header
   `X-Forwarded-Proto: https` diteruskan agar asset tidak terkena mixed-content.
5. Data production berasal dari dump database environment sekarang. Jangan
   menjalankan `cron:import` atau import ulang `crontab-legacy` pada VPS baru.
6. Credential Client disimpan plaintext/as-is. Jalankan migration konversi pada
   source dengan `APP_KEY` lama sebelum final dump; key source tidak perlu
   dipindahkan ke VPS setelah konversi berhasil.
7. Full database dump/restore boleh dilakukan manual melalui SQLyog. Gunakan
   **Backup Database As SQL Dump** (structure + data), target database kosong,
   koneksi SSH tunnel/VPN, dan rekonsiliasi jumlah data setelah import.
8. `storage/app/public/avatars` wajib ikut dipindah bersama database.
9. Runtime sementara seperti queue payload, session, cache, failed jobs, dan
   entry Telescope source dibersihkan setelah restore di target.
10. Dokumen resmi:
   - `docs/deployment-vps.md`: instalasi dan go-live VPS end-to-end;
   - `docs/database-migration-vps.md`: dump/restore database existing;
   - `docs/user-guide.md`: konsep, role, dan seluruh module UI.

Prompt deployment berikutnya:

> Baca tiga dokumen resmi di atas. Deploy release ke VPS manual memakai Apache2
> dan PHP 8.4-FPM sebagai user `opsifin_admin`, tanpa aaPanel/Redis/Horizon.
> Jalankan fase awal melalui IP atau HTTPS forwarder jika domain belum siap.
> Migrasikan database existing beserta avatar setelah konversi satu kali
> credential lama. SQLyog boleh dipakai untuk full dump/restore manual ke
> database target kosong. Generate `APP_KEY` baru di target;
> jangan re-import legacy. Jangan menyalakan cron/worker target sebelum restore,
> migration, count reconciliation, credential check, dan smoke test read-only
> lulus.

## Memory update — default schedules and admin UI release (20 Agustus 2026)

Scope release yang masih berada di working tree lokal:

1. Client baru dapat dibuat bersama default schedule untuk seluruh Task Template
   aktif yang mengaktifkan `auto_assign_to_new_clients`. Provisioning bersifat
   idempotent dan dapat dijalankan ulang melalui bulk action Client.
2. Task Template menyimpan default cron, status enabled, dan kebijakan overlap.
   Migration release adalah
   `2026_08_20_000001_add_default_schedule_policy_to_task_templates.php`.
3. Queue tetap `database/default`; jangan menggantinya menjadi file, Redis,
   Horizon, atau Predis pada release ini.
4. Filament memakai logo lokal `public/images/brand/opsifin-logo.png`. File
   tersebut disalin dari
   `https://qa2.fin-svc-barto.net/assets/image/rsp_inv_new.png` dengan SHA-256
   `5b0f73d9379fed537241789dc232a7c10641380cca197d24b9c670ed6a6a9b3c`.
5. Appearance memakai satu custom switcher pada topbar/login. Mode
   `Light/Dark/System` (dulu `Auto`) disimpan pada localStorage key `theme`; palette
   `Opsifin/Ocean/Forest/Sunset` disimpan pada key `opsifin-palette`. Theme
   switcher bawaan di user menu dimatikan untuk menghindari kontrol duplikat.
6. **Sudah tidak berlaku sejak 14 September 2026** — panel memakai tampilan
   bawaan Filament; lihat bagian sesi 14 September di atas. Keputusan visual di
   bawah disimpan sebagai riwayat saja dan jangan diterapkan ulang.
   Keputusan visual setelah review screenshot `jelek.jpg` dan `jelek2.jpg`:
   sidebar `18rem`, dark-blue gradient yang restrained, satu bahasa warna ikon,
   topbar terang dengan aksen Opsifin, logo compact, dan appearance popover
   collision-safe serta responsif. Jangan mengembalikan sidebar rainbow,
   gradient cokelat, topbar oranye solid, atau mode selector icon-only.
7. Password reset Filament aktif. Notifikasi reset hanya dikirim untuk User
   aktif dan menggunakan mail/queue yang dikonfigurasi environment.
8. Follow-up screenshot `jelek3.jpg` menemukan error Alpine
   `Unexpected identifier 'setPalette'` dan overflow dari floating dropdown
   Filament. Final implementation tidak memakai `x-filament::dropdown` atau
   multi-statement `x-init`; popover diposisikan absolut terhadap tombol dengan
   batas lebar/tinggi viewport. Pertahankan struktur ini agar overflow kanan
   tidak kembali.
9. Follow-up screenshot `jelek4.jpg` membuktikan object literal Alpine di atribut
   `x-data` tetap rapuh karena browser menormalisasi newline menjadi spasi.
   Final state factory berada di `appearance-init.blade.php` sebagai
   `window.opsifinAppearance(defaultTheme)`; atribut Blade hanya memanggil
   factory tersebut. Jangan memindahkan method/state kembali ke atribut HTML.
   Render final yang wajib dipertahankan adalah
   `x-data="opsifinAppearance('system')"`; `window.opsifinAppearance` harus
   bertipe `function` di browser dan Console tidak boleh memuat
   `palette is not defined`. Setiap update Blade ini wajib diikuti
   `artisan optimize:clear` dan `artisan optimize` di server agar compiled view
   lama tidak tetap digunakan.

Verifikasi release lokal terakhir:

```text
PHPUnit full suite   82 passed, 287 assertions
Laravel Pint        passed
Blade view cache    passed
Vite build          passed
git diff --check    passed
Branch              master
Remote              git@github.com:Adityapras/opsifin-crontab.git
```

Target update development server yang disebutkan user:

```text
URL          https://opsigolite-dev-backend.fin-svc-barto.net
Project path /var/www/html/php84/opsifin-scheduler
SSH user     opsifin_admin
Web server   Apache2 behind Google Load Balancer
```

Sebelum update server: buat commit release dan push `master`, backup database,
pastikan working tree server bersih, lalu gunakan maintenance mode karena kode
baru membaca kolom dari migration baru. Pertahankan `/up` untuk health check dan
jangan mengubah Google Load Balancer. Sesudah pull, jalankan Composer install,
Vite production build, migration, Laravel optimize, graceful queue restart,
kemudian smoke test `/up`, `/admin/login`, login, appearance switcher, create
Client default schedules, dan forgot-password.
