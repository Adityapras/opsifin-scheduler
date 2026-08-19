<?php

namespace App\Enums;

enum RunTrigger: string
{
    case Schedule = 'schedule';
    case Manual = 'manual';
    case Retry = 'retry';

    public function label(): string
    {
        return match ($this) {
            self::Schedule => 'Schedule',
            self::Manual => 'Manual',
            self::Retry => 'Retry',
        };
    }
}
