<?php

namespace App\Enums;

enum LockMode: string
{
    /** flock -n : job berikutnya langsung keluar bila yang lama masih jalan. */
    case Skip = 'skip';

    /** flock -w <detik> : job berikutnya mengantre sampai timeout. */
    case Wait = 'wait';

    public function label(): string
    {
        return match ($this) {
            self::Skip => 'Skip bila masih berjalan (flock -n)',
            self::Wait => 'Antre sampai timeout (flock -w)',
        };
    }
}
