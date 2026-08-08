<?php

namespace App\Policies;

use App\Models\Alert;
use App\Models\User;

/**
 * Alert adalah catatan kejadian — tidak boleh dikarang atau diubah isinya.
 * Yang bisa berubah hanya statusnya, lewat aksi acknowledge dan resolve.
 */
class AlertPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Alert $alert): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Alert $alert): bool
    {
        return false;
    }

    public function delete(User $user, Alert $alert): bool
    {
        return false;
    }

    /** Menyatakan "sedang ditangani" — kewenangan operasional. */
    public function acknowledge(User $user, Alert $alert): bool
    {
        return $user->canOperate();
    }

    public function resolve(User $user, Alert $alert): bool
    {
        return $user->canOperate();
    }
}
