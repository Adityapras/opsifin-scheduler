# Template Production VPS

Direktori ini berisi template production Opsifin Scheduler tanpa aaPanel:

- `apache-vhost.conf.template`: VirtualHost Apache2, PHP-FPM, dan routing
  Laravel/Livewire;
- `supervisor-worker.conf.template`: dua database queue worker;
- `opsifin-scheduler.cron`: satu Laravel `schedule:run` trigger melalui `/etc/cron.d`;
- `logrotate.conf.template`: rotasi log worker dan scheduler.

Ganti IP/hostname dan path contoh sebelum memasang file. Urutan instalasi lengkap,
permission, TLS, smoke test, backup, dan rollback ada di
[`../../docs/deployment-vps.md`](../../docs/deployment-vps.md).

Domain boleh belum tersedia pada instalasi awal. Gunakan IP server atau hostname
HTTPS forwarder sebagai `ServerName`/`APP_URL`, lalu ikuti prosedur cutover domain
di panduan deployment setelah aplikasi stabil.

Production memakai salinan database existing yang credential Client-nya sudah
dikonversi as-is, bukan `APP_KEY` source atau import legacy ulang. Prosedur
dump/restore ada di
[`../../docs/database-migration-vps.md`](../../docs/database-migration-vps.md).
