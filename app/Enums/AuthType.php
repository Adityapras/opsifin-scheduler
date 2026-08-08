<?php

namespace App\Enums;

enum AuthType: string
{
    case Basic = 'basic';
    case Bearer = 'bearer';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Basic => 'Basic Auth',
            self::Bearer => 'Bearer Token',
            self::None => 'No auth',
        };
    }
}
