<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Client $client): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->canManage();
    }

    public function update(User $user, Client $client): bool
    {
        return $user->canManage();
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->canManage() && $client->schedules()->doesntExist();
    }

    /** Aktif/nonaktifkan client tanpa membuka form edit. */
    public function toggle(User $user, Client $client): bool
    {
        return $user->canOperate();
    }

    /** Tes reachability & kredensial. */
    public function test(User $user, Client $client): bool
    {
        return $user->canOperate();
    }
}
