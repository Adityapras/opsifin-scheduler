# Template Production VPS

Direktori ini berisi template production Opsifin Scheduler tanpa aaPanel:

- `nginx-site.conf.template`: virtual host Nginx dan routing Livewire;
- `supervisor-worker.conf.template`: dua database queue worker;
- `opsifin-scheduler.cron`: satu Laravel `schedule:run` trigger melalui `/etc/cron.d`;
- `logrotate.conf.template`: rotasi log worker dan scheduler.

Ganti domain dan path contoh sebelum memasang file. Urutan instalasi lengkap,
permission, TLS, smoke test, backup, dan rollback ada di
[`../../docs/deployment-vps.md`](../../docs/deployment-vps.md).

Production memakai salinan database existing dan `APP_KEY` source, bukan
menjalankan import legacy ulang. Prosedur dump/restore ada di
[`../../docs/database-migration-vps.md`](../../docs/database-migration-vps.md).
