# Module System dan Observability

[Kembali ke panduan utama](../user-guide.md)

## User Management

Menu: **System → User management**, hanya Administrator. Field: Avatar, Name,
Email unik, Role, Can sign in, Password, dan Confirm password. Password kosong
saat edit mempertahankan password lama.

- Terapkan least privilege dan jangan memakai akun bersama.
- Pastikan minimal satu Administrator aktif.
- Administrator tidak dapat menghapus dirinya sendiri dari aksi tabel.
- Avatar pada disk public harus masuk backup/restore.

## Audit History

Menu: **System → Audit history**, read-only untuk user aktif. Data: waktu, actor,
action, entity, ID, before, after, dan IP opsional. Field sensitif di-redact.

Gunakan Audit history untuk **siapa mengubah apa dan kapan**; gunakan Execution
logs untuk **apa hasil request**.

## Telescope

Menu: **System → Telescope**, hanya Administrator. Digunakan untuk diagnosis
request web, exception, Artisan command, log, dan scheduled task. Direct
transport sengaja tidak menerbitkan event HTTP Telescope yang dapat menyimpan
credential. Status bisnis authoritative tetap di Execution logs.

## Horizon

Horizon hanya terlihat untuk Administrator ketika driver `queue` aktif. Gunakan
untuk worker, throughput, wait time, recent jobs, dan failed queue jobs. Job
completed di Horizon tidak selalu berarti endpoint sukses; lihat Run terkait.

## Notifications, profile, dan appearance

Ikon lonceng memakai database notifications. Selalu verifikasi status final
pada record. Menu profil digunakan untuk akun/logout. Appearance switcher tidak
memengaruhi scheduler.

## Peta observability

| Pertanyaan | Sumber |
| --- | --- |
| Executor/dispatcher hidup? | Dashboard atau `jobs:direct-status --json` |
| Hasil occurrence? | Execution logs |
| Siapa mengubah konfigurasi? | Audit history |
| Exception Laravel? | Telescope atau `storage/logs/laravel.log` |
| Worker queue? | Horizon saat queue mode |
| Process dijaga? | Supervisor |
| Entry point per menit hidup? | system cron dan scheduler log |

