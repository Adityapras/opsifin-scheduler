<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sumber repo cron legacy
    |--------------------------------------------------------------------------
    |
    | Folder yang berisi opsifin_crontab atau crontab.txt, gateway.sh, configs/, jobs/, dan
    | folder-folder client. Dibaca read-only oleh `php artisan cron:import`.
    |
    */
    'source_path' => env('CRON_SOURCE_PATH', '/home/aditya_prasetyo/project/crontab-legacy'),

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
        'queue' => 'default',
    ],

    // Potong body respons sebelum disimpan ke tabel `runs`.
    'response_excerpt_length' => 2000,

    // Retensi tabel `runs` (hari).
    'runs_retention_days' => (int) env('CRON_RUNS_RETENTION_DAYS', 90),

    'execution_margin_sec' => (int) env('CRON_EXECUTION_MARGIN_SEC', 60),

    // Kosong untuk akses langsung/NAT. Isi hanya IP/CIDR reverse proxy tepercaya.
    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', '')),
    ))),

];
