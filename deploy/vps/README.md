# Template Production VPS

Direktori ini berisi template production Opsifin Scheduler tanpa aaPanel:

- `apache-vhost.conf.template`: VirtualHost Apache2, PHP-FPM, dan routing
  Laravel/Livewire;
- `supervisor-worker.conf.template`: satu master Laravel Horizon;
- `opsifin-scheduler.cron`: satu Laravel `schedule:run` trigger melalui `/etc/cron.d`;
- `logrotate.conf.template`: rotasi log Horizon dan scheduler.

Ganti IP/hostname dan path contoh sebelum memasang file. Urutan instalasi lengkap,
permission, TLS, smoke test, backup, dan rollback ada di
[`../../docs/deployment-vps.md`](../../docs/deployment-vps.md).

Domain boleh belum tersedia pada instalasi awal. Gunakan IP server atau hostname
HTTPS forwarder sebagai `ServerName`/`APP_URL`, lalu ikuti prosedur cutover domain
di panduan deployment setelah aplikasi stabil.

Linux service user dan primary group production adalah
`opsifin_admin:opsifin_admin`. Nama path aplikasi, program Supervisor, dan user
database tetap memakai nama masing-masing dari template.

Production memakai salinan database existing yang credential Client-nya sudah
dikonversi as-is, bukan `APP_KEY` source atau import legacy ulang. Prosedur
dump/restore ada di
[`../../docs/database-migration-vps.md`](../../docs/database-migration-vps.md).
Full dump/restore boleh dilakukan melalui CLI atau manual dengan SQLyog sesuai
checklist pada dokumen tersebut.
