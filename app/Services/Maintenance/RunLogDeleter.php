<?php

namespace App\Services\Maintenance;

use App\Models\AuditLog;
use App\Models\Run;
use App\Models\Schedule;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Penghapusan riwayat eksekusi dari UI.
 *
 * Run yang belum terminal tidak boleh dihapus. `schedules.running_run_id` tidak
 * memiliki foreign key, sehingga menghapus Run yang masih berjalan meninggalkan
 * slot overlap menggantung dan memblokir seluruh occurrence berikutnya pada
 * schedule tersebut. Pelepasan slot tetap dilakukan sebagai jaring pengaman
 * untuk slot basi yang menunjuk Run yang sudah selesai.
 */
class RunLogDeleter
{
    public function delete(Run $run): void
    {
        DB::transaction(function () use ($run): void {
            $locked = Run::query()->lockForUpdate()->find($run->id);

            if ($locked === null) {
                return;
            }

            if (! $locked->status->isTerminal()) {
                throw new InvalidArgumentException('Only a finished run can be deleted.');
            }

            $this->releaseStaleSlot($locked);

            // Catat sebelum baris hilang; jejak penghapusan itu sendiri harus ada.
            AuditLog::record('deleted', $locked, $this->snapshot($locked));

            $locked->delete();
        });
    }

    /**
     * @param  iterable<Run>  $runs
     * @return array{deleted: int, skipped: int}
     */
    public function deleteMany(iterable $runs): array
    {
        $deleted = 0;
        $skipped = 0;

        foreach ($runs as $run) {
            try {
                $this->delete($run);
                $deleted++;
            } catch (InvalidArgumentException) {
                // Run sempat diambil executor setelah tabel dirender.
                $skipped++;
            }
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    private function releaseStaleSlot(Run $run): void
    {
        if ($run->schedule_id === null) {
            return;
        }

        Schedule::query()
            ->whereKey($run->schedule_id)
            ->where('running_run_id', $run->id)
            ->update(['running_run_id' => null, 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function snapshot(Run $run): array
    {
        // Tanpa response_excerpt dan error_message: keduanya dapat memuat
        // pesan endpoint, dan audit log bukan tempat menyalinnya kembali.
        return [
            'status' => $run->status->value,
            'schedule_id' => $run->schedule_id,
            'client_id' => $run->client_id,
            'task_template_id' => $run->task_template_id,
            'scheduled_for' => $run->scheduled_for?->toDateTimeString(),
            'http_status' => $run->http_status,
            'duration_ms' => $run->duration_ms,
        ];
    }
}
