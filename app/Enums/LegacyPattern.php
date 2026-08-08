<?php

namespace App\Enums;

/**
 * Asal-usul sebuah schedule saat diimpor dari crontab lama.
 */
enum LegacyPattern: string
{
    /** Pola A — crontab memanggil langsung <client>/<script>.sh */
    case DirectScript = 'direct_script';

    /** Pola B — crontab memanggil gateway.sh <client> <task> */
    case Gateway = 'gateway';

    /** Dibuat manual lewat UI, bukan hasil impor. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::DirectScript => 'Direct script',
            self::Gateway => 'Gateway',
            self::Manual => 'Manual',
        };
    }
}
