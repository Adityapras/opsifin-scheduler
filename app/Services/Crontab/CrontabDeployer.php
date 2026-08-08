<?php

namespace App\Services\Crontab;

use App\Models\AuditLog;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Menulis hasil render ke file cron.d dengan backup + audit trail, dan
 * mengembalikannya bila perlu.
 */
class CrontabDeployer
{
    public function __construct(private readonly CrontabRenderer $renderer) {}

    public function targetPath(): string
    {
        return config('opsifin_cron.deploy.cron_d_file');
    }

    public function backupDirectory(): string
    {
        return storage_path('app/crontab-backups');
    }

    public function currentContents(?string $path = null): string
    {
        $path ??= $this->targetPath();

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Isi file setelah render diterapkan, tanpa benar-benar menulisnya.
     */
    public function preview(?string $path = null): string
    {
        return $this->renderer->merge($this->currentContents($path), $this->renderer->render());
    }

    /**
     * Diff unified sederhana antara isi sekarang dan isi hasil render.
     *
     * @return array<int, array{type: string, line: string}>
     */
    public function diff(?string $path = null): array
    {
        $before = preg_split('/\r?\n/', $this->currentContents($path)) ?: [];
        $after = preg_split('/\r?\n/', $this->preview($path)) ?: [];

        // Perbandingan berbasis himpunan baris. Cukup untuk file cron.d yang
        // urutannya deterministik, dan menghindari dependency diff eksternal.
        $before = array_values(array_filter($before, fn ($l) => trim($l) !== ''));
        $after = array_values(array_filter($after, fn ($l) => trim($l) !== ''));

        $beforeSet = array_flip($before);
        $afterSet = array_flip($after);

        $diff = [];

        foreach ($before as $line) {
            if (! isset($afterSet[$line])) {
                $diff[] = ['type' => 'removed', 'line' => $line];
            }
        }

        foreach ($after as $line) {
            if (! isset($beforeSet[$line])) {
                $diff[] = ['type' => 'added', 'line' => $line];
            }
        }

        return $diff;
    }

    /**
     * @return array{path: string, backup: ?string, bytes: int}
     */
    public function apply(?string $path = null): array
    {
        $path ??= $this->targetPath();
        $contents = $this->preview($path);
        $before = $this->currentContents($path);

        $directory = dirname($path);

        if (! is_dir($directory)) {
            throw new RuntimeException("Direktori tujuan tidak ada: {$directory}");
        }

        if (! is_writable(is_file($path) ? $path : $directory)) {
            throw new RuntimeException(
                "Tidak punya izin menulis ke {$path}. Jalankan sebagai user yang berhak, ".
                'atau pakai --output untuk menulis ke lokasi staging.'
            );
        }

        $backup = $before === '' ? null : $this->backup($path, $before);

        File::put($path, $contents);
        @chmod($path, 0644);

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'crontab_deployed',
            'entity_type' => 'crontab',
            'entity_id' => null,
            'before' => ['path' => $path, 'backup' => $backup, 'bytes' => strlen($before)],
            'after' => ['bytes' => strlen($contents), 'schedules' => $this->renderer->enabledSchedules()->count()],
            'ip' => request()->ip(),
            'created_at' => now(),
        ]);

        return ['path' => $path, 'backup' => $backup, 'bytes' => strlen($contents)];
    }

    /**
     * @return array<int, string> daftar file backup, terbaru lebih dulu
     */
    public function backups(): array
    {
        if (! is_dir($this->backupDirectory())) {
            return [];
        }

        $files = glob($this->backupDirectory().'/*.cron') ?: [];
        rsort($files);

        return $files;
    }

    /**
     * @return array{path: string, restored_from: string}
     */
    public function rollback(?string $backup = null, ?string $path = null): array
    {
        $path ??= $this->targetPath();
        $backup ??= $this->backups()[0] ?? null;

        if ($backup === null || ! is_file($backup)) {
            throw new RuntimeException('Tidak ada backup crontab yang bisa dikembalikan.');
        }

        // Baca isi backup lebih dulu: mem-backup file saat ini bisa menimpa
        // file backup yang justru sedang kita pulihkan.
        $restored = (string) file_get_contents($backup);
        $before = $this->currentContents($path);

        if ($before !== '') {
            $this->backup($path, $before);
        }

        File::put($path, $restored);

        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => 'crontab_rollback',
            'entity_type' => 'crontab',
            'entity_id' => null,
            'before' => ['path' => $path, 'bytes' => strlen($before)],
            'after' => ['restored_from' => $backup],
            'ip' => request()->ip(),
            'created_at' => now(),
        ]);

        return ['path' => $path, 'restored_from' => $backup];
    }

    private function backup(string $path, string $contents): string
    {
        File::ensureDirectoryExists($this->backupDirectory());

        // Milidetik ikut dipakai supaya dua backup dalam detik yang sama
        // (deploy lalu rollback) tidak saling menimpa.
        $file = sprintf(
            '%s/%s-%s.cron',
            $this->backupDirectory(),
            now()->format('Ymd-His-v'),
            basename($path),
        );

        $suffix = 0;

        while (is_file($file)) {
            $file = sprintf(
                '%s/%s-%d-%s.cron',
                $this->backupDirectory(),
                now()->format('Ymd-His-v'),
                ++$suffix,
                basename($path),
            );
        }

        File::put($file, $contents);

        return $file;
    }
}
