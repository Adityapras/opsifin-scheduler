# Template Development aaPanel

Seluruh file dalam direktori ini hanya untuk development lokal WSL + aaPanel.
File ini bukan konfigurasi production.

Production Opsifin Scheduler menggunakan VPS tanpa aaPanel. Gunakan panduan
[`../../docs/deployment-vps.md`](../../docs/deployment-vps.md) dan template di
[`../vps/`](../vps/).

Jika aaPanel menampilkan `Supervisor daemon abnormal`, jalankan startup script
ini sebagai root. Script hanya menghapus socket/PID Supervisor yang stale saat
proses daemon benar-benar tidak hidup, lalu menjalankan binary Supervisor milik
aaPanel memakai `/etc/supervisor/supervisord.conf`.
