<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['Admin Opsifin', 'admin@opsifin.local', UserRole::Admin],
            ['Operator Cron', 'operator@opsifin.local', UserRole::Operator],
            ['Viewer Cron', 'viewer@opsifin.local', UserRole::Viewer],
        ];

        foreach ($users as [$name, $email, $role]) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'role' => $role,
                    'is_active' => true,
                    'password' => 'password',
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
