<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Reset an administrator's password from the server shell
 * (replaces the old public forgot/reset-password endpoints).
 */
class ResetAdminPassword extends Command
{
    protected $signature = 'admin:reset-password {email : Email of the admin account}';

    protected $description = 'Set a new password for an existing administrator (password is prompted)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->where('role', 'admin')->first();

        if (! $user) {
            $this->error('No admin account found with that email.');

            return self::FAILURE;
        }

        $password = $this->secret('New password (min 12 chars, mixed case, number, symbol)');
        $confirm  = $this->secret('Confirm new password');

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirm],
            ['password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()]]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user->update(['password' => $password]);

        $this->info("Password updated for {$user->email}. Existing tokens expire on their normal JWT TTL.");

        return self::SUCCESS;
    }
}
