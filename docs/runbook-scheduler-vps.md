# Runbook: menjalankan scheduler di VPS production

Production VPS manual, tanpa aaPanel. Untuk development WSL gunakan
[runbook aaPanel](runbook-scheduler-aapanel.md).

Dokumen ini hanya membahas **cara membuat scheduler tetap hidup** pada driver
`direct`. Instalasi server dari nol, Apache, MySQL, dan go-live ada di
[deployment-vps.md](deployment-vps.md).

Dua proses harus berjalan terus-menerus:

| Proses | Tugas | Dikelola oleh |
| --- | --- | --- |
| `jobs:work-direct` | mengeksekusi Run, mengirim HTTP | Supervisor (systemd) |
| `schedule:run` tiap menit | membuat occurrence dari Schedule due | system cron |

Identitas dan path production:

```text
project        /var/www/opsifin-scheduler
PHP CLI        /usr/bin/php8.4
service user   opsifin_admin
log            /var/log/opsifin-scheduler/
supervisor     /etc/supervisor/conf.d/
```

## 0. Prasyarat

Kerjakan pada release yang sudah ter-deploy, migration sudah dijalankan, dan
smoke test read-only sudah lulus.

```bash
cd /var/www/opsifin-scheduler

# Supervisor dan cron terpasang serta aktif
systemctl is-active supervisor cron

# PHP CLI beserta ekstensi wajib
/usr/bin/php8.4 -v
/usr/bin/php8.4 -m | grep -xE 'curl|pcntl|pdo_mysql|posix'

# direktori log ada dan dimiliki service user
sudo install -d -o opsifin_admin -g opsifin_admin /var/log/opsifin-scheduler
```

Bila Supervisor belum terpasang:

```bash
sudo apt update && sudo apt install -y supervisor cron
sudo systemctl enable --now supervisor cron
```

## 1. Aktifkan driver direct

```bash
cd /var/www/opsifin-scheduler
grep -n '^CRON_EXECUTION_DRIVER' .env
```

Nilai harus `direct`. Setelah mengubah `.env`:

```bash
/usr/bin/php8.4 artisan config:clear
/usr/bin/php8.4 artisan config:cache
```

Perubahan driver baru berlaku setelah proses di-restart. Selama compatibility
window, `queue` tetap merupakan nilai rollback yang sah; lihat
[direct-http-operations.md](direct-http-operations.md).

## 2. Pasang Supervisor direct executor

```bash
cd /var/www/opsifin-scheduler
sudo cp deploy/vps/supervisor-direct-executor.conf.template \
  /etc/supervisor/conf.d/opsifin-scheduler-direct.conf

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status opsifin-scheduler-direct
```

Isi template yang dipakai:

| Setelan | Nilai | Alasan |
| --- | --- | --- |
| `directory` | `/var/www/opsifin-scheduler` | root aplikasi |
| `command` | `/usr/bin/php8.4 artisan jobs:work-direct` | daemon executor |
| `user` | `opsifin_admin` | pemilik source dan runtime files |
| `numprocs` | `1` | daemon kedua hanya standby karena lease database |
| `autostart`, `autorestart` | `true` | pulih sendiri setelah reboot atau OOM |
| `stopsignal` | `TERM` | memicu drain: admission berhenti, request aktif diselesaikan |
| `stopwaitsecs` | `2000` | beri waktu request terlama selesai sebelum SIGKILL |

`stopwaitsecs` tidak boleh diperkecil. Bila Supervisor mem-SIGKILL daemon di
tengah request, Run yang sedang berjalan menjadi outcome ambigu dan baru
dibereskan lewat deadline recovery sebagai `failed` tanpa pengiriman ulang.

Status harus `RUNNING` dan bertahan lebih dari 10 detik.

## 3. Pasang system cron

Buat satu entry saja, memakai file di `/etc/cron.d` agar tercatat dalam
konfigurasi dan bukan di crontab personal:

```bash
sudo tee /etc/cron.d/opsifin-scheduler >/dev/null <<'EOF'
* * * * * opsifin_admin cd /var/www/opsifin-scheduler && /usr/bin/php8.4 artisan schedule:run >> /var/log/opsifin-scheduler/scheduler.log 2>&1
EOF

sudo chmod 644 /etc/cron.d/opsifin-scheduler
```

File di `/etc/cron.d` wajib memiliki kolom user (`opsifin_admin`) dan diakhiri
baris baru.

Pastikan tidak ada cron lain untuk aplikasi ini:

```bash
sudo grep -R -nE 'cron:tick|cron:watchdog|jobs:dispatch-due|schedule:run|opsifin' \
  /etc/crontab /etc/cron.d /var/spool/cron 2>/dev/null
```

Yang boleh aktif hanya satu `artisan schedule:run`. Jangan membuat cron per
Client atau per job — seluruh jadwal dibaca dari database.

Tambahkan rotasi log supaya tidak tumbuh tanpa batas:

```bash
sudo tee /etc/logrotate.d/opsifin-scheduler >/dev/null <<'EOF'
/var/log/opsifin-scheduler/*.log {
    weekly
    rotate 8
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
    su opsifin_admin opsifin_admin
}
EOF
```

## 4. Verifikasi

```bash
cd /var/www/opsifin-scheduler
/usr/bin/php8.4 artisan jobs:direct-status
```

Yang harus terlihat:

```text
"driver": "direct"
"executor_online": true      ← Supervisor bekerja
"dispatcher_online": true    ← cron bekerja
"pool_capacity": 20
"missed_start_window_24h": 0
```

`dispatcher_online` baru menyala setelah cron berjalan sekali; tunggu pergantian
menit. Periksa juga:

```bash
sudo supervisorctl status opsifin-scheduler-direct
tail -f /var/log/opsifin-scheduler/direct-executor.log
tail -f /var/log/opsifin-scheduler/scheduler.log
```

Uji ketahanan reboot sebelum menyatakan selesai:

```bash
sudo reboot
# setelah naik kembali
systemctl is-active supervisor cron
sudo supervisorctl status opsifin-scheduler-direct
/usr/bin/php8.4 artisan jobs:direct-status
```

## 5. Sizing sebelum enable

Concurrency default `CRON_DIRECT_CONCURRENCY=20` terbukti untuk peak 123 Run
dengan endpoint 5 detik pada pengujian loopback, bukan pada endpoint production.
Ukur dulu durasi endpoint sebenarnya.

Syarat agar semua Run mulai di dalam start window:

```text
(ceil(N / C) - 1) × d  ≤  start_window
```

Dengan N = 123 dan window 55 detik pada C = 20, durasi endpoint rata-rata harus
di bawah 9,1 detik. Bila p95 endpoint production lebih lambat, naikkan `C`
sebelum enable; window tidak dapat dinaikkan karena wajib di bawah 60 detik.
Penjelasan lengkap ada di
[direct-bounded-http-migration-plan.md](direct-bounded-http-migration-plan.md).

## 6. Cutover bertahap

Setelah scheduler hidup, belum ada HTTP yang terkirim selama seluruh Schedule
masih disabled. Urutan yang dipakai:

1. enable satu job yang replaceable dan harmless pada satu Client;
2. amati dua siklus penuh melalui Execution logs dan `jobs:direct-status`;
3. periksa `missed_start_window_24h`, `failed_rate_24h`, dan p95 `start_lag_ms`;
4. perluas per job group, bukan sekaligus;
5. pantau satu siklus peak lengkap sebelum dianggap stabil.

Jangan enable massal. Setiap Schedule yang aktif mengirim request nyata ke
endpoint Client pada jadwalnya.

## 7. Rollback ke queue

Selama compatibility window, jalur queue masih tersedia.

```bash
cd /var/www/opsifin-scheduler
sudo supervisorctl stop opsifin-scheduler-direct

# kembalikan driver
sed -i 's/^CRON_EXECUTION_DRIVER=direct/CRON_EXECUTION_DRIVER=queue/' .env
/usr/bin/php8.4 artisan config:clear && /usr/bin/php8.4 artisan config:cache

# hidupkan kembali worker Horizon
sudo supervisorctl start opsifin-scheduler-horizon
sudo supervisorctl status
```

Run direct yang masih `pending` tidak dipindahkan ke Redis. Biarkan kedaluwarsa
menjadi `skipped`; occurrence berikutnya dibuat ulang oleh dispatcher. Tidak ada
pengiriman ulang, sesuai kontrak delivery.

## Troubleshooting

| Gejala | Penyebab paling mungkin | Tindakan |
| --- | --- | --- |
| `FATAL`/`BACKOFF` setelah `supervisorctl update` | Path PHP, directory, atau user salah | `tail -50 /var/log/opsifin-scheduler/direct-executor.log`; cocokkan dengan tabel bagian 2 |
| `RUNNING` tetapi `executor_online: false` | Driver masih `queue`, atau config cache basi | `artisan config:clear && artisan config:cache`, lalu `supervisorctl restart opsifin-scheduler-direct` |
| `dispatcher_online: false` | Cron belum jalan, file `/etc/cron.d` tanpa kolom user atau tanpa newline akhir | `sudo systemctl status cron`; periksa `/var/log/opsifin-scheduler/scheduler.log` |
| `Permission denied` pada log | Direktori log atau `storage` bukan milik `opsifin_admin` | `sudo chown -R opsifin_admin:opsifin_admin /var/log/opsifin-scheduler storage bootstrap/cache` |
| Banyak Run `skipped` dengan `Missed start window` | Concurrency terlalu kecil untuk durasi endpoint | Ukur p95 endpoint, hitung ulang `C` dengan rumus bagian 5 |
| Run `failed` dengan pesan outcome tidak dapat dipastikan | Daemon mati di tengah request | Periksa OOM (`journalctl -k` lalu saring `oom`) dan memori; occurrence berikutnya tetap independen |
| Dua executor berjalan | Deploy ganda atau conf lama tertinggal | Aman, yang kedua standby karena lease; tetap rapikan `/etc/supervisor/conf.d` |

## Perintah harian

```bash
cd /var/www/opsifin-scheduler

# kesehatan scheduler
/usr/bin/php8.4 artisan jobs:direct-status

# jadwal Laravel
/usr/bin/php8.4 artisan schedule:list

# kontrol daemon
sudo supervisorctl status opsifin-scheduler-direct
sudo supervisorctl restart opsifin-scheduler-direct

# log
tail -f /var/log/opsifin-scheduler/direct-executor.log
tail -f /var/log/opsifin-scheduler/scheduler.log
```

Saat deploy release baru, jalankan `sudo supervisorctl restart
opsifin-scheduler-direct` setelah migration selesai agar daemon memakai kode
terbaru. SIGTERM membuat daemon drain lebih dahulu, sehingga request yang sedang
berjalan tidak terpotong.
