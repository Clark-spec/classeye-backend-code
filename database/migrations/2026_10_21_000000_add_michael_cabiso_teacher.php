<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('users')->insertOrIgnore([
            'name' => 'Michael Cabiso',
            'staff_id' => 'T-10004',
            'password' => Hash::make('password'),
            'role' => 'teacher',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('staff_id', 'T-10004')
            ->where('name', 'Michael Cabiso')
            ->delete();
    }
};
