# User Guide Opsifin Scheduler

Panduan ini menjelaskan konsep, hak akses, fungsi setiap module, cara membuat dan
menjalankan job, membaca hasil eksekusi, serta prosedur operasi harian Opsifin
Scheduler.

URL panel:

- production: `https://<domain>/admin`;
- development: `http://opsifin-cron.local/admin`.

## 1. Tujuan aplikasi

Opsifin Scheduler memusatkan job HTTP yang sebelumnya tersebar di crontab dan
script per Client. Aplikasi menyimpan konfigurasi di database, menentukan waktu
eksekusi, memasukkan pekerjaan ke queue, mengirim request HTTP melalui worker,
dan mencatat hasilnya.

Manfaat utamanya:

- satu definisi job dapat dipakai banyak Client;
- perubahan jadwal dilakukan dari UI;
- job dapat dipause tanpa menghapus konfigurasi;
- overlap dapat di-skip seperti `flock -n`;
- hasil setiap request dapat ditelusuri;
- perubahan konfigurasi memiliki audit trail;
- akses user dibatasi berdasarkan role.

## 2. Model mental: Client, Template, Schedule, dan Run

```text
Client + Task Template + Timing = Schedule
                                  |
                                  | saat due / Run now / Retry
                                  v
                                  Run
                                  |
                                  v
                           Database queue
                                  |
                                  v
                              HTTP request
                                  |
                                  v
                       succeeded / failed / skipped
```

### Client

Sistem tujuan, misalnya satu instalasi Opsifin. Client menyimpan base URL,
timezone, username, password/token, dan Secret Key.

### Task Template

Definisi request HTTP reusable: method, endpoint path, body, headers, dan
timeout. Satu template tidak dimiliki oleh satu Client tertentu.

### Schedule

Assignment satu Task Template ke satu Client beserta cron expression, timezone,
state enabled/paused, dan overlap policy.

### Run / Execution log

Satu occurrence nyata dari Schedule. Run dapat berasal dari waktu cron,
`Run now`, atau `Retry`.

### Contoh

Jika job `update_balance` dipakai 20 Client:

- buat **satu** Task Template `update_balance`;
- simpan URL dan credential berbeda pada masing-masing Client;
- buat/assign Schedule pada 20 Client;
- setiap Schedule boleh memiliki cron yang sama atau berbeda.

Tidak perlu membuat 20 Task Template. Template baru hanya dibuat jika bentuk
request bisnisnya memang berbeda.

## 3. Sumber data legacy

Pada migrasi awal, katalog Task Template berasal dari file canonical
`crontab-legacy/jobs/*.sh`. Script pada folder Client hanya dipakai untuk
memetakan assignment dan mendeteksi perbedaan.

Setelah data dipindah ke production:

- database production menjadi sumber kebenaran;
- job baru dibuat melalui Task Templates;
- assignment baru dibuat melalui UI;
- tidak perlu mengubah crontab Linux per Client;
- tidak perlu menjalankan import legacy lagi.

## 4. Login, logout, dan akun

1. Buka `/admin/login`.
2. Isi email dan password.
3. Gunakan tombol mata untuk memastikan password yang diketik bila perlu.
4. Setelah masuk, gunakan menu profil untuk logout.

Akun dengan **Can sign in** nonaktif tidak dapat membuka panel. Jangan berbagi
akun karena Audit history menggunakan identitas user yang sedang login.

Jika lupa password, hubungi Administrator. Administrator dapat mengganti
password melalui User management. Pada keadaan darurat server, PIC dapat memakai
command `cron:admin-create` untuk membuat/reset Administrator.

## 5. Role dan permission

### Ringkasan role

| Role | Tujuan |
| --- | --- |
| Administrator | Mengelola master data, jadwal, user, dan seluruh aksi operasi |
| Operator | Menjalankan operasi harian tanpa mengubah definisi master |
| Viewer | Monitoring read-only dan inspect request yang sudah disamarkan |

### Permission matrix

| Aksi | Administrator | Operator | Viewer |
| --- | :---: | :---: | :---: |
| Melihat Dashboard/module | Ya | Ya | Ya |
| Membuat/mengubah Client | Ya | Tidak | Tidak |
| Activate/Deactivate Client | Ya | Ya | Tidak |
| Test connection Client | Ya | Ya | Tidak |
| Membuat/mengubah Task Template | Ya | Tidak | Tidak |
| Assign/remove template ke Client | Ya | Tidak | Tidak |
| Membuat/mengubah/menghapus Schedule | Ya | Tidak | Tidak |
| Pause/Resume Schedule | Ya | Ya | Tidak |
| Inspect request tersamarkan | Ya | Ya | Ya |
| Run now | Ya | Ya | Tidak |
| Cancel queued Run | Ya | Ya | Tidak |
| Retry failed Run | Ya | Ya | Tidak |
| Melihat Execution logs | Ya | Ya | Ya |
| User management | Ya | Tidak | Tidak |
| Audit history | Ya | Ya | Ya |
| Telescope | Ya | Tidak | Tidak |

Jika tombol tidak muncul, periksa role dan status aktif akun sebelum menganggap
fiturnya rusak.

## 6. Kontrol tabel yang berlaku umum

Sebagian besar module menggunakan tabel Filament dengan pola yang sama:

- **Search** mencari kolom utama;
- klik judul kolom untuk **sort**;
- **Filter** membatasi record berdasarkan state atau relasi;
- **Reset filters** menghapus seluruh filter aktif;
- checkbox memilih record untuk **bulk action**;
- menu **Actions** berisi aksi satu record;
- pagination mengubah halaman/jumlah data;
- beberapa filter disimpan dalam session browser.

Jika data terasa hilang:

1. periksa badge filter aktif;
2. reset filter;
3. kosongkan search;
4. periksa halaman pagination;
5. refresh browser.

## 7. Dashboard

Dashboard adalah ringkasan kesehatan scheduler dan otomatis refresh berkala.

### Enabled schedules

Jumlah Schedule dengan state enabled. Angka ini belum tentu berarti seluruhnya
dapat berjalan: Client dan Task Template juga harus aktif.

### Success, 24 hours

Persentase `succeeded` dibanding `succeeded + failed` selama 24 jam. Status
queued, running, skipped, dan cancelled tidak masuk denominator.

### Queue

Jumlah Run berstatus queued yang menunggu worker. Angka yang terus bertambah
menandakan worker berhenti, lambat, database bermasalah, atau endpoint terlalu
lama.

### Running

Jumlah request yang sedang diproses worker.

### Oldest pending occurrences

Daftar Run queued paling lama. Operator dapat:

- membuka detail Run;
- membatalkan Run yang masih queued.

Jika tabel kosong dan menampilkan **Queue is clear**, tidak ada payload yang
menunggu worker.

## 8. Module Client Job Summary

Menu: **Insights -> Client job summary**.

Module ini menjawab tiga pertanyaan tanpa pindah-pindah filter:

1. Client mana yang belum memiliki semua active Task Template?
2. Job apa saja yang sudah dipakai setiap Client?
3. Timing apa saja yang terpasang pada Client tersebut?

### Kolom

| Kolom | Arti |
| --- | --- |
| Client | Code dan nama Client |
| Active job coverage | Jumlah active job yang terpasang / seluruh active template |
| Jobs in use | Key template yang sudah memiliki Schedule |
| Missing active jobs | Active template yang belum di-assign |
| Timings | Total Schedule, termasuk lebih dari satu timing |
| Enabled | Jumlah Schedule enabled |
| Review | Penanda perlu pemeriksaan manual |
| Client active | State Client |

Jika badge job lebih dari empat, klik **Show more** untuk menampilkan sisanya
dan **Show less** untuk menutupnya.

### Filter

- **Missing job assignments**: hanya Client dengan coverage belum lengkap;
- **Client active**: aktif/nonaktif;
- **Needs review**: status verifikasi manual.

### Schedule details

Klik aksi **Schedule details** untuk melihat:

- semua job dan timing yang sudah terpasang;
- cron expression dan timezone;
- state Enabled/Paused;
- active job yang masih missing.

Coverage tidak selalu harus 100%. Bila suatu job memang tidak berlaku untuk
Client tertentu, dokumentasikan keputusan tersebut agar badge missing tidak
disalahartikan sebagai insiden.

## 9. Module Clients

Menu: **Master data -> Clients**.

Client adalah konfigurasi target HTTP. Mengubah Client dapat memengaruhi seluruh
Schedule miliknya.

### Kolom tabel

- **Code**: identifier stabil dan unik;
- **Name**: nama yang mudah dibaca;
- **Base URL**: host utama;
- **Auth**: tipe auth dan username bila ada;
- **Schedules**: jumlah seluruh assignment;
- **Enabled**: jumlah Schedule enabled;
- **Review**: perlu pemeriksaan manual;
- **Active**: apakah Client boleh menjalankan job.

### Filter

- Active;
- Needs review;
- Has enabled schedules.

### Membuat Client

Administrator memilih **New client** lalu mengisi:

#### Identity

| Field | Penjelasan |
| --- | --- |
| Code | Identifier stabil; huruf, angka, titik, underscore, dan dash |
| Name | Nama tampilan |
| Base URL | URL tanpa trailing slash; path berasal dari template |
| Timezone | Timezone default Client/informasi operasional |
| Active | Master switch untuk semua Schedule Client |

Jangan mengganti Code tanpa alasan karena Code digunakan pada pencarian,
placeholder template, dan audit.

#### Credentials

| Auth type | Field yang digunakan |
| --- | --- |
| Basic Auth | Username + Password |
| Bearer Token | Token |
| No auth | Tidak menambahkan Authorization header |

**Secret key** adalah nilai tambahan untuk header seperti `SecretKey`. Nilainya
dipanggil template melalui `{{client.secret_key}}`.

Password/token dan Secret Key:

- disimpan di database persis seperti nilai yang dimasukkan (tidak dienkripsi);
- ditampilkan masked pada form;
- dapat dilihat dengan tombol mata oleh user yang boleh mengedit;
- disamarkan dari preview, execution excerpt, log, dan audit;
- tidak bergantung pada `APP_KEY` source ketika database dipindahkan.

Karena credential tersimpan plaintext, akses database, dump, backup, dan akun
Administrator harus dibatasi hanya untuk personel berwenang.

#### Review & notes

- **Needs manual verification** menandai data yang belum dipercaya penuh;
- **Review notes** menjelaskan blocker/verifikasi yang diperlukan;
- **Free-form notes** untuk konteks operasional.

#### Legacy origin

Informasi sumber import dan bersifat read-only. Digunakan untuk tracing, bukan
untuk menjalankan job.

### Test connection

Aksi ini melakukan GET non-destruktif ke Base URL dengan Authorization Client.
Hasilnya dapat berupa:

- **Host reachable**: host merespons dan auth tidak langsung ditolak;
- **Credentials rejected**: HTTP 401/403;
- **Cannot connect**: DNS, TLS, timeout, atau network error.

Test connection tidak membuktikan endpoint job tertentu berhasil. Gunakan
Inspect request dan Run now untuk pengujian end-to-end.

### Activate / Deactivate bulk

- **Deactivate** menghentikan materialisasi occurrence baru untuk seluruh
  Schedule Client. Payload queued akan dicek ulang worker dan dapat di-skip.
- **Activate** mengaktifkan Client kembali. Schedule yang memang berstatus
  enabled akan menghitung waktu berikutnya.

Request yang sudah running tidak dihentikan oleh Deactivate.

### Menghapus Client

Client hanya dapat dihapus jika tidak memiliki Schedule. Lepaskan assignment
terlebih dahulu. Riwayat Run menggunakan foreign key nullable sehingga histori
dapat tetap tersedia sesuai struktur database.

## 10. Module Task Templates

Menu: **Master data -> Task templates**.

Task Template mendefinisikan request satu kali untuk dipakai banyak Client.

### Kolom tabel

- key dan nama;
- HTTP method;
- endpoint path;
- timeout;
- jumlah assignment;
- needs review;
- active.

### Membuat template

#### Identitas

- **Key**: identifier `snake_case`, misalnya `update_balance`;
- **Name**: nama bisnis yang mudah dipahami;
- **Description**: tujuan dan efek job;
- **Executor**: saat ini hanya HTTP request;
- **Template active**: master switch semua assignment template.

Menonaktifkan template membuat worker men-skip eksekusi terkait tanpa menghapus
Schedule atau histori.

#### HTTP request

| Field | Penjelasan |
| --- | --- |
| Method | GET/POST/method HTTP yang tersedia |
| Path | Path relatif yang digabung dengan Client Base URL |
| JSON body | Raw JSON/body request |
| Additional headers | Header selain default Accept/Content-Type/Authorization |

Authorization dibentuk otomatis dari Client. Jangan menulis username/password
hardcoded dalam template.

### Placeholder

| Placeholder | Nilai runtime |
| --- | --- |
| `{{client.code}}` | Code Client |
| `{{client.username}}` | Username Basic Auth |
| `{{client.secret}}` | Password atau bearer secret |
| `{{client.password}}` | Alias backward-compatible untuk secret |
| `{{client.secret_key}}` | Secret Key Client |
| `{{run.scheduled_for}}` | Waktu occurrence dalam ISO-8601 UTC |

Placeholder dapat dipakai pada URL/path, header, dan body. Hindari menaruh
secret pada URL karena URL lebih mudah masuk ke access log upstream.

### Timeout

- **Connect timeout**: waktu maksimum membangun koneksi;
- **Request timeout**: waktu maksimum seluruh request, maksimal 1800 detik.

Setiap Run hanya mencoba satu kali. Failure di-retry manual setelah penyebabnya
dipahami.

### Assign all active clients

1. Buka Actions pada template.
2. Pilih **Assign all active clients**.
3. Isi cron expression dan timezone.
4. Biarkan **Enable new assignments immediately** mati untuk rollout aman.
5. Konfirmasi.

Sistem hanya membuat pasangan Client-template yang belum ada.

### Assign selected clients

Gunakan untuk rollout terbatas atau job yang hanya berlaku pada Client tertentu.
Pilih Client, cron, timezone, dan state awal.

### Remove from selected clients

Menghapus Schedule/assignment untuk Client terpilih. Assignment dengan active
run tidak dihapus. Run history yang sudah ada tetap tersedia.

### Menghapus template

Template hanya dapat dihapus jika tidak memiliki Schedule. Hapus assignment
terlebih dahulu dan pastikan kebutuhan audit sudah dipertimbangkan.

## 11. Module Schedules

Menu: **Operations -> Schedules**.

Schedule menghubungkan Client, Task Template, cron, timezone, dan state runtime.
Badge menu menunjukkan jumlah Schedule enabled.

### Kolom tabel

| Kolom | Arti |
| --- | --- |
| Client | Target request |
| Job | Task Template |
| Cron | Expression dan deskripsi manusia |
| Next run | Waktu occurrence berikutnya; Paused bila tidak aktif |
| Latest | Status Run terakhir |
| No overlap | Apakah occurrence baru di-skip saat previous masih aktif |
| Review | Perlu verifikasi manual |
| Enabled | State Schedule |

Tabel refresh otomatis. Filter dapat disimpan selama session browser.

### Filter

- Client;
- Job;
- Enabled;
- Needs review.

### Membuat Schedule manual

Administrator memilih **New schedule**.

#### Assignment

Pilih satu Client dan satu Task Template. Preview menampilkan request final yang
sudah digabung, tetapi secret disamarkan.

#### Timing

Gunakan quick preset atau cron expression lima bagian:

```text
minute hour day-of-month month day-of-week
```

Contoh:

| Expression | Arti |
| --- | --- |
| `*/5 * * * *` | Setiap 5 menit |
| `*/10 * * * *` | Setiap 10 menit |
| `0 * * * *` | Setiap awal jam |
| `0 6 * * *` | Setiap hari pukul 06:00 |
| `0 6 * * 1-5` | Senin-Jumat pukul 06:00 |

Selalu pilih timezone bisnis yang benar. Form menampilkan lima occurrence
berikutnya; review tanggal tersebut sebelum menyimpan.

Satu Client dapat memiliki lebih dari satu timing untuk Task Template yang sama
selama cron expression berbeda.

#### State

- **Schedule enabled**: menentukan apakah occurrence otomatis dibuat;
- **Skip overlapping run**: default aktif;
- **Needs manual review** dan notes: untuk blocker/verifikasi.

Schedule baru sebaiknya disimpan paused, diuji dengan Run now, lalu di-resume.

### Inspect request

Menampilkan method, URL, header, body, timeout, dan connect timeout tanpa
mengirim request. Authorization, SecretKey, API key, password, dan token
disamarkan.

Semua role aktif boleh Inspect karena aksi ini read-only.

### Run now

1. Pastikan Client dan Template aktif.
2. Pilih **Run now**.
3. Konfirmasi.
4. Sistem membuat Run trigger `manual` dan memasukkannya ke database queue.
5. Pantau Execution logs.

Run now dapat dipakai ketika Schedule paused. Aksi ini tetap menghormati master
switch Client/Template dan overlap slot.

### Toggle Enabled

Klik icon Enabled untuk Pause/Resume bila role mengizinkan.

- Pause mengosongkan `next_run_at`;
- Resume menghitung occurrence **berikutnya** dari waktu sekarang;
- waktu yang terlewat selama pause tidak di-replay;
- request yang sudah running tidak dibatalkan.

### Bulk Set cron

Administrator dapat memilih banyak Schedule dan mengubah cron sekaligus.
Timezone dapat diganti bersama atau dibiarkan mengikuti nilai masing-masing.

### Bulk Pause / Resume

Administrator dan Operator dapat mem-pause/resume banyak Schedule. Periksa
jumlah pilihan sebelum konfirmasi.

### Skip overlapping run

Perilakunya setara tujuan `flock -n`:

1. Run pertama mengambil slot atomik Schedule;
2. occurrence berikutnya tetap dicatat;
3. jika slot masih dipakai, request kedua tidak dikirim;
4. Run kedua selesai dengan status `skipped` dan pesan previous run aktif.

Implementasinya database lock/slot, bukan file lock, sehingga aman untuk dua
worker.

## 12. Module Execution Logs

Menu: **Operations -> Execution logs**.

Module ini adalah sumber utama untuk melihat hasil eksekusi. Ini berbeda dari
`storage/logs/laravel.log` dan Audit history.

### Status

| Status | Arti | Tindakan umum |
| --- | --- | --- |
| queued | Menunggu worker | Periksa worker bila terlalu lama |
| running | Request sedang berjalan | Tunggu timeout; jangan duplicate manual |
| succeeded | HTTP 2xx berhasil | Tidak ada tindakan |
| failed | HTTP non-2xx, timeout, koneksi, atau exception | Baca detail lalu perbaiki |
| skipped | Tidak dikirim karena pause/overlap/config tidak runnable | Review alasan |
| cancelled | Dibatalkan saat masih queued | Tidak dieksekusi |

### Trigger

| Trigger | Arti |
| --- | --- |
| schedule | Dibuat otomatis saat cron due |
| manual | Dibuat melalui Run now |
| retry | Salinan baru dari failed Run |

### Filter

- Client;
- Task;
- Status;
- Trigger;
- Problems only;
- Period `from` dan `until`.

Gunakan filter periode untuk insiden atau laporan. Jika data tidak muncul,
reset filter yang tersimpan dalam session.

### Detail Run

Detail berisi:

- Client dan Job;
- status dan trigger;
- scheduled, queued, started, finished;
- duration dan HTTP status;
- worker process;
- response excerpt maksimal sesuai konfigurasi;
- error message;
- Schedule asal dan source Run untuk retry.

Secret Client di-redact sebelum response/error disimpan. Tetap hindari endpoint
yang mengembalikan data sensitif tidak dikenal dalam body.

### Cancel queued Run

Cancel tersedia hanya selama status masih queued. Sistem menghapus payload dari
tabel queue, menandai Run `cancelled`, dan mencatat audit. Jika worker sudah
mengambil payload, cancel ditolak karena request mungkin sudah berjalan.

Bulk **Cancel queued runs** hanya membatalkan pilihan yang masih queued;
running dan terminal diabaikan.

### Retry failed Run

1. Buka failure dan pahami penyebab.
2. Perbaiki Client, credential, Template, atau endpoint.
3. Pastikan request aman dikirim ulang/idempotent.
4. Klik Retry dan konfirmasi.
5. Pantau Run baru dengan trigger `retry`.

Run lama tidak diubah. Tidak ada automatic retry.

## 13. Module User Management

Menu: **System -> User management**. Hanya Administrator.

### Field

- Avatar image, maksimal 2 MB;
- Name;
- Email unik;
- Role;
- Can sign in;
- Password dan confirmation.

Avatar disimpan pada disk public `storage/app/public/avatars`. Saat deployment,
folder ini harus ikut dibackup/restore dan `public/storage` harus berupa symlink.

### Membuat user

1. Pilih **New user**.
2. Upload avatar opsional.
3. Isi nama/email.
4. Pilih role paling rendah yang cukup.
5. Isi password minimal 8 karakter dan confirmation.
6. Aktifkan **Can sign in**.
7. Simpan dan kirim credential melalui channel aman.

### Mengubah user

Kosongkan password dan confirmation jika password lama ingin dipertahankan.
Menonaktifkan **Can sign in** segera mencegah akses panel pada autentikasi
berikutnya.

Administrator tidak dapat menghapus akun dirinya sendiri dari aksi tabel.
Pastikan minimal satu Administrator aktif tetap tersedia.

## 14. Module Audit History

Menu: **System -> Audit history**.

Audit history menjawab **siapa mengubah konfigurasi apa dan kapan**. Module ini
read-only untuk seluruh user aktif.

Kolom:

- When;
- Actor;
- Action: created, updated, deleted, atau cancelled;
- Entity dan ID;
- Before dan After;
- IP opsional.

Password, token, Secret Key, Authorization, dan field sensitif lain disimpan
sebagai `[redacted]`.

Audit history bukan execution log. Contoh:

- perubahan cron muncul di Audit history;
- hasil HTTP 500 muncul di Execution logs;
- exception aplikasi muncul di Telescope/`laravel.log`.

## 15. Module Telescope

Menu: **System -> Telescope**, hanya Administrator dan dibuka pada tab baru.

Telescope digunakan untuk diagnosis teknis:

- request yang masuk ke aplikasi;
- HTTP client request yang keluar dari job;
- queue job lifecycle;
- exception dan error log;
- Artisan command;
- Laravel scheduled task.

Telescope bukan sumber utama hasil bisnis job. Gunakan Execution logs untuk
status Run karena data tersebut lebih stabil dan sudah dirancang untuk user.

Entry dapat terlihat setelah request/job selesai atau worker menyimpan update.
Worker yang berjalan lama perlu direstart setelah perubahan konfigurasi
Telescope.

Data Telescope dipangkas otomatis setiap hari dan tetap berpotensi berisi data
teknis. Jangan membagikan screenshot tanpa review.

## 16. Notifications

Panel dapat menampilkan database notifications melalui icon lonceng. Polling
berjalan berkala. Notification bersifat tambahan; status final tetap harus
diverifikasi pada Execution logs atau record terkait.

## 17. Prosedur aman menambahkan job baru

1. Definisikan tujuan, endpoint, side effect, dan idempotency.
2. Buat satu Task Template.
3. Gunakan placeholder Client; jangan hardcode credential.
4. Assign hanya ke satu Client uji dalam state paused.
5. Buka Schedule dan Inspect request.
6. Jalankan Test connection Client bila perlu.
7. Gunakan Run now pada endpoint aman.
8. Verifikasi HTTP status, response excerpt, dan efek bisnis.
9. Assign ke selected Clients.
10. Review Client Job Summary.
11. Resume bertahap.
12. Pantau minimal dua siklus.

## 18. Prosedur mengganti credential Client

1. Pause Schedule Client bila pergantian berisiko.
2. Pastikan tidak ada Run running.
3. Edit Client.
4. Reveal dan pastikan field yang akan diganti benar.
5. Simpan password/token/Secret Key baru.
6. Jalankan Test connection.
7. Inspect satu request.
8. Run now satu job aman.
9. Resume Schedule bertahap.

Perubahan credential memengaruhi seluruh template Client tersebut.

## 19. Prosedur endpoint bermasalah

1. Filter Execution logs berdasarkan Client/job/status failed.
2. Baca HTTP status dan error.
3. Pause Schedule terkait agar backlog tidak bertambah.
4. Biarkan request running selesai/timeout.
5. Periksa Client connection dan Template request.
6. Perbaiki akar masalah.
7. Run now satu kali.
8. Retry hanya failure yang aman diulang.
9. Resume bertahap.

## 20. Queue menumpuk

Indikasi:

- angka Queue Dashboard terus naik;
- Oldest pending semakin lama;
- Run tidak berubah dari queued.

Operator:

1. jangan mass-Run now;
2. batalkan queued Run yang tidak diperlukan;
3. pause Schedule penyumbang backlog bila endpoint bermasalah;
4. hubungi PIC server untuk memeriksa Supervisor worker.

PIC server memeriksa `supervisorctl`, worker log, database, dan
`storage/logs/laravel.log`.

## 21. Run stuck di running

Jangan langsung mengirim duplicate. Tunggu request timeout ditambah execution
margin. Dispatcher berikutnya dapat menandai stale execution gagal dan melepas
overlap slot.

Hubungi PIC bila durasi melewati timeout. Jangan mengubah `running_run_id` atau
status langsung di database selama worker mungkin masih hidup.

## 22. Retention

Execution logs terminal dipertahankan sesuai `CRON_RUNS_RETENTION_DAYS`, default
90 hari. Purge harian tidak menghapus queued/running. Telescope memiliki
retention terpisah, default panduan deployment tujuh hari.

Export/report yang wajib disimpan lebih lama harus dibuat sebelum retention
berakhir atau dipindahkan ke sistem audit eksternal.

## 23. Hal yang sengaja tidak dilakukan aplikasi

- tidak replay seluruh occurrence yang terlewat saat downtime;
- tidak automatic HTTP retry;
- tidak menghentikan request yang sudah running saat Schedule dipause;
- tidak menjalankan lebih dari satu attempt dalam satu Run;
- tidak membuat cron Linux per job;
- tidak menggunakan Redis/Horizon;
- tidak menjadi external uptime monitor VPS;
- tidak otomatis menentukan bahwa semua active template wajib untuk semua
  Client;
- tidak mengimpor ulang legacy setelah production memakai database existing.

## 24. Checklist operasi harian

- [ ] Dashboard success rate wajar.
- [ ] Queue tidak menumpuk.
- [ ] Running tidak melewati timeout.
- [ ] Failed terbaru sudah ditriage.
- [ ] Schedule enabled memiliki Next run.
- [ ] Client/Template paused memang disengaja.
- [ ] Client Job Summary missing sudah dipahami.
- [ ] Audit perubahan penting direview.
- [ ] Tidak ada duplicate runtime dari cron lama.

## 25. Checklist sebelum mass Resume

- [ ] Client aktif dan connection test masuk akal.
- [ ] Credential sudah diverifikasi.
- [ ] Task Template aktif dan payload benar.
- [ ] Inspect request sudah direview.
- [ ] Cron/timezone dan next occurrences benar.
- [ ] Skip overlapping run sesuai kebutuhan.
- [ ] Run now satu Client berhasil.
- [ ] Side effect job aman.
- [ ] Rollback berupa Pause sudah dipahami.
- [ ] PIC monitoring siap minimal dua siklus.

## 26. Glosarium

| Istilah | Arti |
| --- | --- |
| Assignment | Hubungan Task Template ke Client; direpresentasikan Schedule |
| Base URL | Host/root URL milik Client |
| Canonical job | Definisi job acuan, dahulu dari `jobs/*.sh` |
| Cron expression | Lima bagian waktu eksekusi |
| Due | `next_run_at` sudah mencapai waktu sekarang |
| Execution log | Record Run dan hasil request |
| Flock/overlap guard | Pencegah dua occurrence Schedule berjalan bersamaan |
| Materialize | Membuat Run dari Schedule yang due |
| Next run | Waktu occurrence otomatis berikutnya |
| Queue | Antrian database sebelum worker mengambil Run |
| Redaction | Penyembunyian secret dari preview/log/audit |
| Run now | Membuat Run manual melalui queue |
| Worker | Process background yang mengirim HTTP request |
