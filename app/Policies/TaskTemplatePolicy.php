<?php

namespace App\Policies;

use App\Models\TaskTemplate;
use App\Models\User;

class TaskTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, TaskTemplate $taskTemplate): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->canManage();
    }

    public function update(User $user, TaskTemplate $taskTemplate): bool
    {
        return $user->canManage();
    }

    public function delete(User $user, TaskTemplate $taskTemplate): bool
    {
        return $user->canManage() && $taskTemplate->schedules()->doesntExist();
    }
}
