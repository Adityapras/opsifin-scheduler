<?php

namespace App\Policies;

use App\Models\Schedule;
use App\Models\User;

class SchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Schedule $schedule): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->canManage();
    }

    /** Mengubah ekspresi cron, lock, atau target adalah kewenangan admin. */
    public function update(User $user, Schedule $schedule): bool
    {
        return $user->canManage();
    }

    public function delete(User $user, Schedule $schedule): bool
    {
        return $user->canManage();
    }

    /** Operator boleh menyalakan/mematikan jadwal tanpa mengubah isinya. */
    public function toggle(User $user, Schedule $schedule): bool
    {
        return $user->canOperate();
    }

    /** Jalankan sekarang — tetap menghormati lock. */
    public function run(User $user, Schedule $schedule): bool
    {
        return $user->canOperate();
    }

    /** Dry run tidak memanggil endpoint, jadi aman untuk siapa pun yang login. */
    public function dryRun(User $user, Schedule $schedule): bool
    {
        return $user->is_active;
    }

    /** Render & deploy crontab. */
    public function deploy(User $user): bool
    {
        return $user->canManage();
    }
}
