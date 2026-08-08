<?php

namespace App\Enums;

enum FindingSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Warning => 'Needs review',
            self::Error => 'Parse failed',
        };
    }
}
