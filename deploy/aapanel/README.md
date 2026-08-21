# Template Development aaPanel

Seluruh file dalam direktori ini hanya untuk development lokal WSL + aaPanel.
File ini bukan konfigurasi production.

Production Opsifin Scheduler menggunakan VPS tanpa aaPanel. Gunakan panduan
[`../../docs/deployment-vps.md`](../../docs/deployment-vps.md) dan template di
[`../vps/`](../vps/).

Development membutuhkan Redis server terpisah. Predis yang terpasang melalui
Composer hanya client PHP dan tidak menggantikan Redis server.

Jika aaPanel menampilkan `Supervisor daemon abnormal`, jalankan startup script
ini sebagai root. Script hanya menghapus socket/PID Supervisor yang stale saat
proses daemon benar-benar tidak hidup, lalu menjalankan binary Supervisor milik
aaPanel memakai `/etc/supervisor/supervisord.conf`.
