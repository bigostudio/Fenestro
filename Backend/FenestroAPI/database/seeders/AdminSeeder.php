<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Optional bootstrap admin for local/dev environments.
 *
 * Credentials come from ADMIN_SEED_NAME / ADMIN_SEED_EMAIL / ADMIN_SEED_PHONE /
 * ADMIN_SEED_PASSWORD in .env — nothing is hardcoded. If they're not set the
 * seeder does nothing. An existing account is never overwritten, so re-running
 * the seeder can't reset a production password.
 *
 * For production, prefer `php artisan admin:create`.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email    = env('ADMIN_SEED_EMAIL');
        $password = env('ADMIN_SEED_PASSWORD');
        $phone    = env('ADMIN_SEED_PHONE');

        if (! $email || ! $password || ! $phone) {
            $this->command?->warn('AdminSeeder skipped: set ADMIN_SEED_EMAIL, ADMIN_SEED_PHONE and ADMIN_SEED_PASSWORD, or use `php artisan admin:create`.');

            return;
        }

        if (User::where('email', $email)->orWhere('phone', $phone)->exists()) {
            $this->command?->info('AdminSeeder: an account with that email or phone already exists; left unchanged.');

            return;
        }

        User::create([
            'name'      => env('ADMIN_SEED_NAME', 'Administrator'),
            'email'     => $email,
            'phone'     => $phone,
            'password'  => $password, // hashed by the User model's 'hashed' cast
            'role'      => 'admin',
            'is_active' => 1,
        ]);
    }
}
