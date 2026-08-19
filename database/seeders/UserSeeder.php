<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (blank($email) || blank($password)) {
            $this->command?->warn('ADMIN_EMAIL/ADMIN_PASSWORD are empty; no demo account was created. Run cron:admin-create instead.');

            return;
        }

        $user = User::firstOrNew(['email' => $email]);

        $user->forceFill([
            'name' => 'Scheduler Administrator',
            'role' => UserRole::Admin,
            'is_active' => true,
            'password' => $password,
            'email_verified_at' => now(),
        ])->save();
    }
}
