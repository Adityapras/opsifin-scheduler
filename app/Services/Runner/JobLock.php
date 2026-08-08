<?php

namespace App\Services\Runner;

use App\Enums\LockMode;
use App\Models\Schedule;
use RuntimeException;

/**
 * Lock eksklusif per schedule.
 *
 * Lock dipegang di dalam runner (bukan lewat `flock` di baris crontab) karena
 * hanya dengan begitu dua hal ini terpenuhi sekaligus:
 *
 *  1. Bentrokan tetap tercatat sebagai run berstatus `skipped_lock` dan terlihat
 *     di UI — kalau `flock` di crontab yang menolak, prosesnya mati sebelum
 *     sempat menulis apa pun (§3.4 rencana meminta status ini terlihat).
 *  2. Tombol "Run now" dari UI ikut menghormati lock yang sama, karena keduanya
 *     melewati jalur kode yang sama.
 */
class JobLock
{
    private mixed $handle = null;

    private function __construct(private readonly string $path) {}

    /**
     * Coba ambil lock. Mengembalikan null bila lock sedang dipegang proses lain.
     */
    public static function acquire(Schedule $schedule): ?self
    {
        $lock = new self($schedule->lockFilePath());
        $lock->ensureDirectoryExists();

        $handle = @fopen($lock->path, 'c');

        if ($handle === false) {
            throw new RuntimeException("Cannot open lock file: {$lock->path}");
        }

        $lock->handle = $handle;

        $deadline = $schedule->lock_mode === LockMode::Wait
            ? microtime(true) + max(1, (int) $schedule->lock_wait_sec)
            : 0.0;

        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                fwrite($handle, (string) getmypid());

                return $lock;
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(200_000);
        } while (true);

        fclose($handle);

        return null;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function path(): string
    {
        return $this->path;
    }

    private function ensureDirectoryExists(): void
    {
        $directory = dirname($this->path);

        if (is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(
                "Cannot create the lock directory: {$directory}. Adjust CRON_LOCK_DIR in .env."
            );
        }
    }
}
