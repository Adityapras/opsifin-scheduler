<?php

namespace App\Services\Maintenance;

use App\Models\Schedule;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Penghapusan Schedule dari UI.
 *
 * Schedule yang slot overlap-nya masih terisi sedang mengirim HTTP; menghapusnya
 * membuat completion kehilangan baris untuk melepas slot dan hasil Run tidak
 * lagi tertaut ke jadwalnya. Riwayat Run tetap disimpan (`schedule_id` menjadi
 * null) dan occurrence yang masih menunggu otomatis di-skip oleh lifecycle.
 */
class ScheduleDeleter
{
    public function delete(Schedule $schedule): void
    {
        DB::transaction(function () use ($schedule): void {
            $locked = Schedule::query()->lockForUpdate()->find($schedule->id);

            if ($locked === null) {
                return;
            }

            if ($locked->running_run_id !== null) {
                throw new InvalidArgumentException('A schedule with a running occurrence cannot be deleted.');
            }

            // Audit dicatat oleh DomainAuditObserver pada event deleted.
            $locked->delete();
        });
    }

    /**
     * @param  iterable<Schedule>  $schedules
     * @return array{deleted: int, skipped: int}
     */
    public function deleteMany(iterable $schedules): array
    {
        $deleted = 0;
        $skipped = 0;

        foreach ($schedules as $schedule) {
            try {
                $this->delete($schedule);
                $deleted++;
            } catch (InvalidArgumentException) {
                $skipped++;
            }
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }
}
