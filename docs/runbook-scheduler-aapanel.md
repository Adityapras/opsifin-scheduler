# Runbook: menjalankan scheduler di WSL + aaPanel

Development. Untuk production VPS gunakan
[runbook VPS](runbook-scheduler-vps.md).

Dokumen ini hanya membahas **cara membuat scheduler tetap hidup** pada driver
`direct`. Instalasi aplikasi dari nol ada di [installation.md](installation.md).

Dua proses harus berjalan terus-menerus:

| Proses | Tugas | Dikelola oleh |
| --- | --- | --- |
| `jobs:work-direct` | mengeksekusi Run, mengirim HTTP | Supervisor |
| `schedule:run` tiap menit | membuat occurrence dari Schedule due | aaPanel Cron |

Executor saja tidak cukup: tanpa cron tidak ada occurrence yang dibuat. Cron saja
juga tidak cukup: occurrence akan menumpuk sebagai `pending` lalu kedaluwarsa
menjadi `skipped` setelah 55 detik.

## 0. Prasyarat — bebaskan memori WSL

Ini bukan formalitas. Daemon pernah dua kali dimatikan sistem karena WSL
kehabisan memori; Supervisor pun akan restart-crash bila dijalankan pada kondisi
yang sama.

Pemakan memori bukan aplikasi — daemon PHP CLI hanya puluhan MB:

```text
VS Code extension host   5,5 GB
DEVSENSE PHP LS          1,1 GB   (2 instance)
Claude Code              0,6 GB
-----------------------------------
total RAM WSL            9,7 GB
sisa bebas                157 MB  ← terlalu sempit
```

Perbaikan cepat: tutup window VS Code yang tidak dipakai, atau
`Ctrl+Shift+P` → **PHP: Restart Language Server**.

Perbaikan permanen: buat atau edit `%USERPROFILE%\.wslconfig` di Windows.

```ini
[wsl2]
memory=12GB
swap=4GB
```

Lalu dari PowerShell jalankan `wsl --shutdown` dan buka kembali terminal WSL.

```bash
free -h
```

Kolom `available` sebaiknya di atas 1,5 GB sebelum melanjutkan.

## 1. Masuk ke panel

Panel berjalan pada port `8888`. Bila URL, user, atau password lupa:

```bash
sudo bt default
# bila bt tidak ditemukan:
sudo /etc/init.d/bt default
```

Alamatnya berbentuk `http://<ip-wsl>:8888/<path-rahasia>`. Path rahasia itu wajib;
tanpa path tersebut panel menolak akses.

## 2. Install Supervisor Manager

Pada mesin development ini plugin Supervisor **belum terpasang**, sehingga menu
yang disebut dokumen lain memang belum ada. Pasang dahulu:

1. Menu kiri → **App Store**.
2. Cari **Supervisor**.
3. Pilih **Supervisor Manager** → **Install**.
4. Setelah terpasang, buka lewat tombol **Setting**.

Bila tidak muncul pada hasil pencarian, refresh daftar aplikasi di pojok App
Store atau pindah ke tab **Deployment**/**All**; daftar plugin kadang perlu
ditarik ulang sekali.

Verifikasi dari terminal:

```bash
which supervisord
```

Harus menghasilkan sebuah path, misalnya `/usr/local/bin/supervisord`.

## 3. Tambahkan program direct executor

**Supervisor Manager** → **Add Daemon**. Nilai berikut sudah sesuai path mesin
development ini:

| Field | Isi |
| --- | --- |
| Name | `opsifin-scheduler-direct` |
| Run User | `www` |
| Run Directory | `/home/aditya_prasetyo/project/opsifin-crontab` |
| Start Command | `/www/server/php/84/bin/php artisan jobs:work-direct` |
| Process Count | `1` |

Referensi lengkap ada di
`deploy/aapanel/supervisor-direct-executor.conf.template`; template tersebut
dapat dipakai apa adanya karena path dan user-nya sudah benar untuk mesin ini.

Setelah **Confirm**, status harus `RUNNING` dan bertahan lebih dari 10 detik
tanpa berubah menjadi `BACKOFF` atau `FATAL`.

`Process Count` tetap `1`. Daemon kedua tidak berbahaya — lease database
membuatnya standby — tetapi hanya memakan memori tanpa menambah kapasitas.
Kapasitas diatur `CRON_DIRECT_CONCURRENCY`, bukan jumlah process.

## 4. Tambahkan cron tiap menit

Menu kiri → **Cron** → **Add Task**.

| Field | Isi |
| --- | --- |
| Type of Task | `Shell Script` |
| Name of Task | `opsifin schedule run` |
| Period | `N Minutes` → `1` |
| Run User | `www` |
| Script content | `cd /home/aditya_prasetyo/project/opsifin-crontab && /www/server/php/84/bin/php artisan schedule:run` |

Dua kesalahan yang paling sering terjadi:

1. Menulis `* * * * *` di dalam kotak script. Jangan. Jadwal diatur lewat field
   **Period**; kotak script hanya berisi perintah.
2. Membuat satu cron untuk tiap job. Jangan. **Cukup satu task ini saja** —
   seluruh jadwal dibaca dari database oleh `jobs:dispatch-due`.

Setelah satu menit, buka **Logs** pada task tersebut dan pastikan tidak ada error.

## 5. Verifikasi

Status `RUNNING` pada panel belum membuktikan aplikasi sehat. Pembuktiannya:

```bash
cd /home/aditya_prasetyo/project/opsifin-crontab
/www/server/php/84/bin/php artisan jobs:direct-status
```

Yang harus terlihat:

```text
"driver": "direct"
"executor_online": true      ← Supervisor bekerja
"dispatcher_online": true    ← cron bekerja
"pool_capacity": 20
"missed_start_window_24h": 0
```

`dispatcher_online` baru menyala setelah cron berjalan sekali, jadi tunggu
pergantian menit. Pada Dashboard, kartu **Direct executor** dan **Dispatcher**
harus berubah dari `Offline`.

## 6. Kondisi setelah terpasang

Seluruh Schedule masih disabled, sehingga `jobs:dispatch-due` melaporkan
`Scanned 0` setiap menit. Scheduler hidup tetapi menganggur, dan itu kondisi yang
diinginkan sampai ada keputusan job mana yang dinyalakan.

Begitu sebuah Schedule di-enable, request sungguhan dikirim ke endpoint Client
pada jadwalnya. Mulai dari satu job harmless, amati dua siklus penuh, baru
perluas. Jangan enable massal.

## Troubleshooting

| Gejala | Penyebab paling mungkin | Tindakan |
| --- | --- | --- |
| Program langsung `FATAL`/`BACKOFF` | Perintah atau path salah ketik | `tail -50 storage/logs/supervisor-direct.log`, cocokkan dengan tabel bagian 3 |
| `RUNNING` tetapi `executor_online: false` | Driver bukan `direct` | Pastikan `CRON_EXECUTION_DRIVER=direct`, jalankan `artisan config:clear`, restart program |
| Program restart terus-menerus | Memori habis, proses dibunuh OOM | Kembali ke bagian 0; cek `free -h` |
| `dispatcher_online: false` padahal cron ada | Cron belum sempat jalan, atau `* * * * *` tertulis di kotak script | Tunggu pergantian menit; buka Logs task; hapus baris jadwal dari kotak script |
| `Permission denied` pada log | Run User bukan `www` | Ubah Run User menjadi `www`; user inilah yang berhak membaca `.env` |
| Run berstatus `skipped` terus | Occurrence melewati start window 55 detik | Endpoint terlalu lambat untuk concurrency saat ini; ukur durasi endpoint sebelum menaikkan `CRON_DIRECT_CONCURRENCY` |

## Perintah harian

```bash
cd /home/aditya_prasetyo/project/opsifin-crontab

# kesehatan scheduler
/www/server/php/84/bin/php artisan jobs:direct-status

# apa yang akan jalan menit ini
/www/server/php/84/bin/php artisan schedule:list

# jalankan dispatcher sekali, manual
/www/server/php/84/bin/php artisan jobs:dispatch-due

# log daemon
tail -f storage/logs/supervisor-direct.log

# memori
free -h
```

Start, stop, dan restart daemon dilakukan dari tombol **Supervisor Manager**,
bukan `kill`. Proses yang dibunuh manual akan dihidupkan lagi oleh Supervisor.

## Alternatif tanpa Supervisor

Hanya untuk percobaan sesaat; tidak tahan restart dan tidak pulih sendiri.

```bash
cd /home/aditya_prasetyo/project/opsifin-crontab
nohup /www/server/php/84/bin/php artisan jobs:work-direct \
  >> storage/logs/direct-executor.log 2>&1 &
nohup /www/server/php/84/bin/php artisan schedule:work \
  >> storage/logs/scheduler.log 2>&1 &
```

`schedule:work` menggantikan cron dengan memanggil `schedule:run` tiap menit.
Hentikan dengan `pkill -f 'artisan jobs:work-direct'` dan
`pkill -f 'artisan schedule:work'`.
