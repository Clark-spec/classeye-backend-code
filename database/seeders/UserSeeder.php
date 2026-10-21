<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // The "hashed" cast on the User model hashes these plain passwords.
        User::updateOrCreate(
            ['staff_id' => 'T-20418'],
            ['name' => 'Admin', 'password' => 'password', 'role' => 'admin']
        );

        User::updateOrCreate(
            ['staff_id' => 'T-10001'],
            ['name' => 'Ms. Reyes', 'password' => 'password', 'role' => 'teacher']
        );

        User::updateOrCreate(
            ['staff_id' => 'T-10002'],
            ['name' => 'Marco Santos', 'password' => 'password', 'role' => 'teacher']
        );

        User::updateOrCreate(
            ['staff_id' => 'T-10003'],
            ['name' => 'Ana Cruz', 'password' => 'password', 'role' => 'teacher']
        );
    }
}
