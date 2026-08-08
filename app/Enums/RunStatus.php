<?php

namespace App\Enums;

enum RunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
    case Timeout = 'timeout';
    case SkippedLock = 'skipped_lock';
    case SkippedDisabled = 'skipped_disabled';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Berjalan',
            self::Success => 'Sukses',
            self::Failed => 'Gagal',
            self::Timeout => 'Timeout',
            self::SkippedLock => 'Dilewati (lock)',
            self::SkippedDisabled => 'Dilewati (nonaktif)',
        };
    }

    public function isProblem(): bool
    {
        return in_array($this, [self::Failed, self::Timeout], true);
    }
}
