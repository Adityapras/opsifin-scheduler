#!/bin/sh
set -e

cd /var/www/html

# Volume storage bisa kosong saat pertama dibuat; siapkan struktur yang diharapkan Laravel.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

as_www() {
    setpriv --reuid=www-data --regid=www-data --init-groups "$@"
}

[ -L public/storage ] || as_www php artisan storage:link --no-interaction >/dev/null 2>&1 || true

# Cache config/route/view dibangun ulang tiap start agar mengikuti env container terbaru.
# Bukan optimize:clear: itu ikut mengosongkan cache store database dan gagal saat DB belum siap.
as_www php artisan optimize --no-interaction >/dev/null

# Apache harus start sebagai root lalu turun ke www-data sendiri; proses artisan langsung sebagai www-data.
if [ "$1" = "apache2-foreground" ]; then
    exec "$@"
fi

exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"
