<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class AdminCreateCommand extends Command
{
    protected $signature = 'cron:admin-create {--email= : Administrator email address} {--name=Scheduler Administrator}';

    protected $description = 'Create or reset the initial administrator with a random one-time password';

    public function handle(): int
    {
        $email = trim((string) ($this->option('email') ?: $this->ask('Administrator email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email address is required.');

            return self::FAILURE;
        }

        $password = $this->generateCopySafePassword();
        $user = User::firstOrNew(['email' => $email]);
        $wasRecentlyCreated = ! $user->exists;

        $user->forceFill([
            'name' => (string) $this->option('name'),
            'password' => $password,
            'role' => UserRole::Admin,
            'is_active' => true,
            'email_verified_at' => now(),
        ])->save();

        if (! Hash::check($password, $user->fresh()->password)) {
            $this->error('The administrator password could not be verified after saving.');

            return self::FAILURE;
        }

        $this->info($wasRecentlyCreated ? 'Administrator created.' : 'Administrator password reset.');
        $this->table(['Email', 'One-time password'], [[$email, $password]]);
        $this->warn('Store this password now. It is not written to a file or log by the application.');

        return self::SUCCESS;
    }

    private function generateCopySafePassword(int $length = 24): string
    {
        // Exclude characters that are commonly confused when copied or typed.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

        return implode('', array_map(
            static fn (): string => $alphabet[random_int(0, strlen($alphabet) - 1)],
            range(1, $length),
        ));
    }
}
