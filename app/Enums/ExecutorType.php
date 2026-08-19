<?php

namespace App\Enums;

enum ExecutorType: string
{
    case Http = 'http';

    public function label(): string
    {
        return match ($this) {
            self::Http => 'HTTP request',
        };
    }
}
