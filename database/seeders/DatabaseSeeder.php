<?php

namespace Database\Seeders;

use App\Models\Room;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(UserSeeder::class);

        if (Room::count() === 0) {
            $this->call(RoomSeeder::class);
        }

        $this->call(ScheduleSeeder::class);
    }
}
