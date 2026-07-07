<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['phone' => '8431474741'],
            [
                'name'      => 'Ops Manager',
                'email'     => 'admin@fenestro.app',
                'password'  => \Illuminate\Support\Facades\Hash::make('Admin@1234'),
                'role'      => 'admin',
                'is_active' => 1,
            ]
        );
    }
}
