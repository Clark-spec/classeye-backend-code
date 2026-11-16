<?php

namespace Database\Seeders;

use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('staff_id', 'T-20418')->first();
        $teacher = User::where('staff_id', 'T-10001')->first();

        $rooms = [
            ['name' => 'Room 101', 'floor' => 1, 'building' => 'Main Building', 'capacity' => 40, 'people_count' => 24, 'is_maintenance' => false],
            ['name' => 'Room 102', 'floor' => 1, 'building' => 'Main Building', 'capacity' => 30, 'people_count' => 0, 'is_maintenance' => false],
            ['name' => 'Room 103', 'floor' => 1, 'building' => 'Main Building', 'capacity' => 32, 'people_count' => 18, 'is_maintenance' => false],
            ['name' => 'Room 104', 'floor' => 1, 'building' => 'Main Building', 'capacity' => 40, 'people_count' => 0, 'is_maintenance' => false],
            ['name' => 'Room 105', 'floor' => 1, 'building' => 'Main Building', 'capacity' => 25, 'people_count' => 12, 'is_maintenance' => false],

            ['name' => 'Room 201', 'floor' => 2, 'building' => 'Main Building', 'capacity' => 40, 'people_count' => 35, 'is_maintenance' => false],
            ['name' => 'Room 202', 'floor' => 2, 'building' => 'Main Building', 'capacity' => 30, 'people_count' => 0, 'is_maintenance' => false],
            ['name' => 'Room 203', 'floor' => 2, 'building' => 'Main Building', 'capacity' => 35, 'people_count' => 22, 'is_maintenance' => false],
            ['name' => 'Room 204', 'floor' => 2, 'building' => 'Main Building', 'capacity' => 40, 'people_count' => 18, 'is_maintenance' => false],
            ['name' => 'Room 205', 'floor' => 2, 'building' => 'Main Building', 'capacity' => 40, 'people_count' => 45, 'is_maintenance' => false],

            ['name' => 'Room 301', 'floor' => 3, 'building' => 'Science Wing', 'capacity' => 45, 'people_count' => 28, 'is_maintenance' => false],
            ['name' => 'Room 302', 'floor' => 3, 'building' => 'Science Wing', 'capacity' => 35, 'people_count' => 0, 'is_maintenance' => false],
            ['name' => 'Room 303', 'floor' => 3, 'building' => 'Science Wing', 'capacity' => 30, 'people_count' => 16, 'is_maintenance' => false],
            ['name' => 'Room 304', 'floor' => 3, 'building' => 'Science Wing', 'capacity' => 30, 'people_count' => 0, 'is_maintenance' => false],
            ['name' => 'Room 305', 'floor' => 3, 'building' => 'Science Wing', 'capacity' => 40, 'people_count' => 41, 'is_maintenance' => false],

            ['name' => 'Room 401', 'floor' => 4, 'building' => 'Arts Wing', 'capacity' => 40, 'people_count' => 20, 'is_maintenance' => false],
            ['name' => 'Room 402', 'floor' => 4, 'building' => 'Arts Wing', 'capacity' => 30, 'people_count' => 0, 'is_maintenance' => false],
            ['name' => 'Room 403', 'floor' => 4, 'building' => 'Arts Wing', 'capacity' => 30, 'people_count' => 15, 'is_maintenance' => false],
            ['name' => 'Room 404', 'floor' => 4, 'building' => 'Arts Wing', 'capacity' => 30, 'people_count' => 0, 'is_maintenance' => true],
            ['name' => 'Room 405', 'floor' => 4, 'building' => 'Arts Wing', 'capacity' => 25, 'people_count' => 10, 'is_maintenance' => false],
        ];

        foreach ($rooms as $index => $data) {
            $room = Room::updateOrCreate(['name' => $data['name']], $data);

            // Give the demo a usable time series so the insights panel is not
            // empty on a fresh database.
            $this->seedSnapshots($room);

            if ($room->logs()->exists()) {
                continue;
            }

            $messages = $this->starterMessages($room);

            foreach ($messages as $offset => $message) {
                $log = $room->logs()->make([
                    'user_id' => $offset % 2 === 0 ? $admin?->id : $teacher?->id,
                    'message' => $message,
                ]);
                $log->created_at = now()->subMinutes(($index + 1) * 12 + ($offset * 18));
                $log->save();
            }
        }
    }

    /**
     * Deterministic 4-hour history (9 points, 30 minutes apart). The last
     * point always matches the room's current people count. Room 203 gets a
     * deliberate late spike so the anomaly detector has something to show.
     */
    private function seedSnapshots(Room $room): void
    {
        if ($room->snapshots()->exists()) {
            return;
        }

        $series = $this->snapshotSeries($room);
        $count = count($series);

        foreach ($series as $offset => $peopleCount) {
            $minutesAgo = ($count - 1 - $offset) * 30;

            $room->snapshots()->create([
                'capacity' => $room->capacity,
                'people_count' => $peopleCount,
                'is_maintenance' => $room->is_maintenance,
                'recorded_at' => now()->subMinutes($minutesAgo),
            ]);
        }
    }

    /**
     * @return array<int, int>
     */
    private function snapshotSeries(Room $room): array
    {
        if ($room->is_maintenance || $room->people_count === 0) {
            return array_fill(0, 9, 0);
        }

        if ($room->name === 'Room 203') {
            return [4, 6, 8, 10, 11, 12, 13, 14, 22];
        }

        $target = $room->people_count;
        $series = [];

        for ($index = 0; $index < 9; $index++) {
            $progress = $index / 8;
            $series[] = (int) round($target * (0.55 + 0.45 * $progress));
        }

        return $series;
    }

    /**
     * Three realistic starter log entries per room, based on its status.
     *
     * @return array<int, string>
     */
    private function starterMessages(Room $room): array
    {
        if ($room->is_maintenance) {
            return [
                'Room created',
                'Marked under maintenance',
                'Maintenance inspection scheduled',
            ];
        }

        if ($room->people_count > $room->capacity) {
            return [
                'Morning attendance recorded',
                "People count changed from {$room->capacity} to {$room->people_count}",
                'Over capacity alert triggered',
            ];
        }

        if ($room->people_count > 0) {
            return [
                'Room created',
                "People count changed from 0 to {$room->people_count}",
                'Room details updated',
            ];
        }

        return [
            'Room created',
            'People count changed from 12 to 0',
            'Room marked empty after class',
        ];
    }
}
