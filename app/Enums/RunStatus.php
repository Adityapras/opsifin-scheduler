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
            self::Running => 'Running',
            self::Success => 'Success',
            self::Failed => 'Failed',
            self::Timeout => 'Timeout',
            self::SkippedLock => 'Skipped (lock)',
            self::SkippedDisabled => 'Skipped (disabled)',
        };
    }

    public function isProblem(): bool
    {
        return in_array($this, [self::Failed, self::Timeout], true);
    }
}
