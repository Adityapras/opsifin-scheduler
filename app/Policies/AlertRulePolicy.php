<?php

namespace App\Policies;

use App\Models\AlertRule;
use App\Models\User;

class AlertRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, AlertRule $alertRule): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->canManage();
    }

    public function update(User $user, AlertRule $alertRule): bool
    {
        return $user->canManage();
    }

    public function delete(User $user, AlertRule $alertRule): bool
    {
        return $user->canManage();
    }
}
