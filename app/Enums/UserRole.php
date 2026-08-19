<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Operator => 'Operator',
            self::Viewer => 'Viewer',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Operator => 'primary',
            self::Viewer => 'gray',
        };
    }

    /**
     * Boleh membuat/mengubah data master dan kebijakan scheduler.
     */
    public function canManage(): bool
    {
        return $this === self::Admin;
    }

    /**
     * Boleh menjalankan job manual & enable/disable schedule.
     */
    public function canOperate(): bool
    {
        return in_array($this, [self::Admin, self::Operator], true);
    }
}
