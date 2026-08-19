<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $record): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $record): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, User $record): bool
    {
        return $user->isAdmin() && ! $user->is($record);
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
