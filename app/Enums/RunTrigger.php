<?php

namespace App\Enums;

enum RunTrigger: string
{
    case Cron = 'cron';
    case Manual = 'manual';
    case Shadow = 'shadow';
    case DryRun = 'dry_run';
}
