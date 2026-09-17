<?php

namespace App\Policies;

use App\Models\Run;
use App\Models\User;

/**
 * Riwayat eksekusi tidak boleh dikarang: create dan update selalu ditolak.
 * Penghapusan hanya untuk Administrator dan hanya pada Run yang sudah terminal —
 * menghapus Run yang masih berjalan akan meninggalkan slot overlap menggantung.
 */
class RunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Run $run): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Run $run): bool
    {
        return false;
    }

    public function delete(User $user, Run $run): bool
    {
        return $user->canManage() && $run->status->isTerminal();
    }

    public function deleteAny(User $user): bool
    {
        return $user->canManage();
    }

    public function retry(User $user, Run $run): bool
    {
        return $user->canOperate() && config('opsifin_cron.execution_driver') === 'queue' && $run->execution_driver !== 'direct';
    }

    public function cancel(User $user, Run $run): bool
    {
        return $user->canOperate();
    }
}
