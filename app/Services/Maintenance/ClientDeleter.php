<?php

namespace App\Services\Maintenance;

use App\Models\Client;
use App\Models\Schedule;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Penghapusan Client dari UI.
 *
 * FK `schedules.client_id` bersifat cascade, jadi menghapus Client langsung akan
 * ikut menghapus seluruh jadwalnya tanpa jejak per Schedule. Operator wajib
 * menghapus Schedule lebih dulu secara eksplisit; riwayat Run tetap disimpan.
 */
class ClientDeleter
{
    public function delete(Client $client): void
    {
        DB::transaction(function () use ($client): void {
            $locked = Client::query()->lockForUpdate()->find($client->id);

            if ($locked === null) {
                return;
            }

            // Kunci baris schedule agar tidak ada assignment baru di tengah pengecekan.
            if (Schedule::query()->where('client_id', $locked->id)->lockForUpdate()->exists()) {
                throw new InvalidArgumentException('Delete every schedule of this client first.');
            }

            $locked->delete();
        });
    }

    /**
     * @param  iterable<Client>  $clients
     * @return array{deleted: int, skipped: int}
     */
    public function deleteMany(iterable $clients): array
    {
        $deleted = 0;
        $skipped = 0;

        foreach ($clients as $client) {
            try {
                $this->delete($client);
                $deleted++;
            } catch (InvalidArgumentException) {
                $skipped++;
            }
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }
}
