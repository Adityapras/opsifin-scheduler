<?php

namespace App\Policies;

use App\Models\Run;
use App\Models\User;

/**
 * Riwayat eksekusi hanya boleh dibaca — tidak ada yang boleh mengarangnya.
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
        return false;
    }

    public function retry(User $user, Run $run): bool
    {
        return $user->canOperate();
    }

    public function cancel(User $user, Run $run): bool
    {
        return $user->canOperate();
    }
}
