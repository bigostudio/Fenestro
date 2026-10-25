<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Provision an administrator account from the server shell.
 * The password is read interactively (hidden) and stored hashed.
 */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create
                            {--name= : Full name}
                            {--email= : Login email}
                            {--phone= : 10-digit phone number (must be unique)}';

    protected $description = 'Create an administrator account (password is prompted, never passed as an argument)';

    public function handle(): int
    {
        $name  = $this->option('name')  ?? $this->ask('Full name');
        $email = $this->option('email') ?? $this->ask('Email');
        $phone = $this->option('phone') ?? $this->ask('10-digit phone number');

        $password = $this->secret('Password (min 12 chars, mixed case, number, symbol)');
        $confirm  = $this->secret('Confirm password');

        $validator = Validator::make(
            compact('name', 'email', 'phone', 'password') + ['password_confirmation' => $confirm],
            [
                'name'     => 'required|string|max:255',
                'email'    => 'required|email|max:255|unique:users,email',
                'phone'    => 'required|digits:10|unique:users,phone',
                'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::create([
            'name'      => $name,
            'email'     => $email,
            'phone'     => $phone,
            'password'  => $password, // hashed by the User model's 'hashed' cast
            'role'      => 'admin',
            'is_active' => 1,
        ]);

        $this->info("Admin account created for {$email}.");

        return self::SUCCESS;
    }
}
