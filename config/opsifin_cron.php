<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sumber repo cron legacy
    |--------------------------------------------------------------------------
    |
    | Folder yang berisi opsifin_crontab, gateway.sh, configs/, jobs/, dan
    | folder-folder client. Dibaca read-only oleh `php artisan cron:import`.
    |
    */
    'source_path' => env('CRON_SOURCE_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Target deploy (dipakai cron:render pada Fase 2)
    |--------------------------------------------------------------------------
    */
    'deploy' => [
        'base_dir' => env('CRON_DEPLOY_BASE_DIR', '/opt/opsifin-cron'),
        'user' => env('CRON_DEPLOY_USER', 'ubuntu'),
        'cron_d_file' => env('CRON_D_FILE', '/etc/cron.d/opsifin'),
        'php_binary' => env('CRON_PHP_BINARY', '/usr/bin/php'),
        'flock_binary' => env('CRON_FLOCK_BINARY', '/usr/bin/flock'),
    ],

    // /var/lock, bukan /tmp — systemd-tmpfiles bisa menghapus lock aktif di /tmp.
    'lock_dir' => env('CRON_LOCK_DIR', '/var/lock/opsifin'),
    'log_dir' => env('CRON_LOG_DIR', '/var/log/opsifin-cron'),

    'default_timezone' => env('CRON_DEFAULT_TIMEZONE', 'Asia/Jakarta'),

    /*
    |--------------------------------------------------------------------------
    | Default eksekusi HTTP
    |--------------------------------------------------------------------------
    |
    | Tidak satu pun dari 478 script lama memakai --max-time / --connect-timeout.
    | Nilai di bawah menjadi default wajib untuk seluruh task.
    |
    */
    'defaults' => [
        'timeout_sec' => 60,
        'connect_timeout_sec' => 10,
        'retries' => 0,
        'lock_mode' => 'skip',
    ],

    // Potong body respons sebelum disimpan ke tabel `runs`.
    'response_excerpt_length' => 2000,

    // Retensi tabel `runs` (hari).
    'runs_retention_days' => env('CRON_RUNS_RETENTION_DAYS', 90),

];
