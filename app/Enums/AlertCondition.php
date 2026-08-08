<?php

namespace App\Enums;

/**
 * Kondisi yang memicu sebuah alert rule.
 */
enum AlertCondition: string
{
    /** Satu run selesai dengan status failed. */
    case OnFailure = 'on_failure';

    /** Satu run melewati batas timeout. */
    case OnTimeout = 'on_timeout';

    /** N run terakhir berturut-turut bermasalah — meredam noise dari gagal sesekali. */
    case ConsecutiveFailures = 'consecutive_failures';

    /**
     * Schedule aktif yang jadwalnya sudah lewat tapi tidak pernah dieksekusi.
     * Ini yang menangkap "cron-nya sendiri mati", bukan job-nya yang gagal.
     */
    case MissedRun = 'missed_run';

    public function label(): string
    {
        return match ($this) {
            self::OnFailure => 'Run failed',
            self::OnTimeout => 'Run timed out',
            self::ConsecutiveFailures => 'N consecutive failures',
            self::MissedRun => 'Scheduled run never happened',
        };
    }

    /** Apakah kondisi ini dinilai saat sebuah run selesai. */
    public function isRunBased(): bool
    {
        return $this !== self::MissedRun;
    }

    public function usesThreshold(): bool
    {
        return $this === self::ConsecutiveFailures;
    }

    public function usesGrace(): bool
    {
        return $this === self::MissedRun;
    }
}
