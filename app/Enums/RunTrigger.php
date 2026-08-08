<?php

namespace App\Enums;

enum RunTrigger: string
{
    case Cron = 'cron';
    case Manual = 'manual';
    case Shadow = 'shadow';
    case DryRun = 'dry_run';

    public function label(): string
    {
        return match ($this) {
            self::Cron => 'Cron',
            self::Manual => 'Manual',
            self::Shadow => 'Shadow',
            self::DryRun => 'Dry run',
        };
    }
}
