<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use App\Services\Audit\AuditChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditChainTest extends TestCase
{
    use RefreshDatabase;

    private function teacher(): User
    {
        return User::create([
            'name' => 'Audit Teacher',
            'staff_id' => 'T-90002',
            'password' => 'password',
            'role' => 'teacher',
        ]);
    }

    private function room(): Room
    {
        return Room::create([
            'name' => 'Room 902',
            'floor' => 1,
            'building' => 'Main Building',
            'capacity' => 40,
            'people_count' => 10,
            'is_maintenance' => false,
        ]);
    }

    public function test_activity_entries_form_a_verifiable_chain(): void
    {
        $user = $this->teacher();
        $room = $this->room();

        $first = $room->logs()->create([
            'user_id' => $user->id,
            'message' => 'Room created',
        ]);

        $second = $room->logs()->create([
            'user_id' => $user->id,
            'message' => 'People count changed from 0 to 10',
        ]);

        $this->assertNotNull($first->hash);
        $this->assertSame($first->hash, $second->prev_hash);
        $this->assertTrue(app(AuditChain::class)->verify($room->id)['valid']);

        $this->artisan('audit:verify', ['--room' => $room->id])->assertSuccessful();
    }

    public function test_tampering_with_an_activity_entry_is_detected(): void
    {
        $user = $this->teacher();
        $room = $this->room();

        $log = $room->logs()->create([
            'user_id' => $user->id,
            'message' => 'Room created',
        ]);

        $log->message = 'Tampered message';
        $log->saveQuietly();

        $result = app(AuditChain::class)->verify($room->id);

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['broken']);
        $this->assertSame($log->id, $result['broken'][0]['id']);
    }
}
