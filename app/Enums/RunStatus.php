<?php

namespace App\Enums;

enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isProblem(): bool
    {
        return $this === self::Failed;
    }

    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Queued, self::Running], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Queued, self::Running => 'info',
            self::Skipped => 'warning',
            self::Cancelled => 'gray',
        };
    }
}
