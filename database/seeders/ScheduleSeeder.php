<?php

namespace Database\Seeders;

use App\Models\Room;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Database\Seeder;

class ScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('staff_id', 'T-20418')->first();
        $teachers = [
            'reyes' => User::where('staff_id', 'T-10001')->first(),
            'santos' => User::where('staff_id', 'T-10002')->first(),
            'cruz' => User::where('staff_id', 'T-10003')->first(),
        ];

        if (in_array(null, $teachers, true)) {
            return;
        }

        $labs = [
            ['name' => 'LAB 1', 'floor' => 1, 'building' => 'Main Building', 'capacity' => 45],
            ['name' => 'LAB 2', 'floor' => 2, 'building' => 'Main Building', 'capacity' => 40],
        ];

        foreach ($labs as $data) {
            $room = Room::firstOrCreate(
                ['name' => $data['name']],
                [...$data, 'people_count' => 0, 'is_maintenance' => false]
            );

            if (! $room->logs()->exists()) {
                $room->logs()->create([
                    'user_id' => $admin?->id,
                    'message' => 'Room created',
                ]);
            }
        }

        $rooms = Room::query()
            ->whereIn('name', ['LAB 1', 'LAB 2', 'Room 101', 'Room 203', 'Room 301', 'Room 405'])
            ->get()
            ->keyBy('name');

        $schedules = [
            [
                'instructor' => 'reyes',
                'room' => 'LAB 1',
                'subject_code' => 'APPSDEV',
                'descriptive_title' => 'Application Development',
                'days' => ['SAT'],
                'start_time' => '16:00',
                'end_time' => '19:00',
                'units' => 3,
            ],
            [
                'instructor' => 'reyes',
                'room' => 'Room 101',
                'subject_code' => 'WEBDEV',
                'descriptive_title' => 'Web Systems and Technologies',
                'days' => ['M', 'T', 'W', 'TH', 'F'],
                'start_time' => '07:30',
                'end_time' => '08:30',
                'units' => 3,
            ],
            [
                'instructor' => 'reyes',
                'room' => 'Room 301',
                'subject_code' => 'IAS',
                'descriptive_title' => 'Information Assurance and Security',
                'days' => ['T', 'TH'],
                'start_time' => '13:00',
                'end_time' => '14:30',
                'units' => 3,
            ],
            [
                'instructor' => 'santos',
                'room' => 'LAB 2',
                'subject_code' => 'IT TRACK EL 2',
                'descriptive_title' => 'IT Track Elective 2',
                'days' => ['M', 'W', 'F'],
                'start_time' => '13:00',
                'end_time' => '14:30',
                'units' => 3,
            ],
            [
                'instructor' => 'santos',
                'room' => 'Room 203',
                'subject_code' => 'IT TRACK EL 2',
                'descriptive_title' => 'IT Track Elective 2',
                'days' => ['T', 'TH'],
                'start_time' => '10:00',
                'end_time' => '11:30',
                'units' => 3,
            ],
            [
                'instructor' => 'santos',
                'room' => 'Room 405',
                'subject_code' => 'CAPSTONE',
                'descriptive_title' => 'Capstone Project',
                'days' => ['SAT'],
                'start_time' => '08:00',
                'end_time' => '12:00',
                'units' => 3,
            ],
            [
                'instructor' => 'cruz',
                'room' => 'LAB 1',
                'subject_code' => 'DBMS',
                'descriptive_title' => 'Database Management Systems',
                'days' => ['M', 'W'],
                'start_time' => '08:00',
                'end_time' => '09:30',
                'units' => 3,
            ],
            [
                'instructor' => 'cruz',
                'room' => 'LAB 2',
                'subject_code' => 'NETADMIN',
                'descriptive_title' => 'Network Administration',
                'days' => ['T', 'TH'],
                'start_time' => '15:00',
                'end_time' => '16:30',
                'units' => 3,
            ],
            [
                'instructor' => 'cruz',
                'room' => 'Room 203',
                'subject_code' => 'DISCRETE',
                'descriptive_title' => 'Discrete Mathematics',
                'days' => ['M', 'W', 'F'],
                'start_time' => '10:00',
                'end_time' => '11:00',
                'units' => 3,
            ],
            [
                'instructor' => 'cruz',
                'room' => 'LAB 2',
                'subject_code' => 'MOBILE',
                'descriptive_title' => 'Mobile Application Development',
                'days' => ['F'],
                'start_time' => '16:00',
                'end_time' => '19:00',
                'units' => 3,
            ],
        ];

        foreach ($schedules as $data) {
            $instructor = $teachers[$data['instructor']];
            $room = $rooms->get($data['room']);

            if (! $instructor || ! $room) {
                continue;
            }

            $attributes = [
                'user_id' => $instructor->id,
                'room_id' => $room->id,
                'subject_code' => $data['subject_code'],
                'descriptive_title' => $data['descriptive_title'],
                'days' => $data['days'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'units' => $data['units'],
            ];

            $existing = Schedule::query()
                ->where('user_id', $instructor->id)
                ->where('subject_code', $data['subject_code'])
                ->where('start_time', $data['start_time'])
                ->where('end_time', $data['end_time'])
                ->first();

            if ($existing) {
                $existing->update($attributes);
            } else {
                Schedule::create($attributes);
            }
        }
    }
}
