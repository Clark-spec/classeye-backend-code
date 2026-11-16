<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InsightsTest extends TestCase
{
    use RefreshDatabase;

    private function teacher(): User
    {
        return User::create([
            'name' => 'Test Teacher',
            'staff_id' => 'T-90001',
            'password' => 'password',
            'role' => 'teacher',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function room(array $overrides = []): Room
    {
        return Room::create(array_merge([
            'name' => 'Room 901',
            'floor' => 1,
            'building' => 'Main Building',
            'capacity' => 40,
            'people_count' => 24,
            'is_maintenance' => false,
        ], $overrides));
    }

    public function test_insights_require_authentication(): void
    {
        $this->getJson('/api/insights')->assertUnauthorized();
    }

    public function test_insights_forecast_occupancy_from_snapshots(): void
    {
        Sanctum::actingAs($this->teacher());
        $room = $this->room();

        foreach ([6, 10, 14, 18, 21, 24] as $index => $peopleCount) {
            $room->snapshots()->create([
                'capacity' => $room->capacity,
                'people_count' => $peopleCount,
                'is_maintenance' => false,
                'recorded_at' => now()->subMinutes((5 - $index) * 30),
            ]);
        }

        $response = $this->getJson('/api/insights')->assertOk();

        $response->assertJsonStructure([
            'generated_at',
            'window_hours',
            'forecast_minutes',
            'rooms_analysed',
            'at_risk',
            'anomalies',
            'average_utilization',
            'headline',
            'rooms' => [
                [
                    'room_id',
                    'name',
                    'forecast_people_count',
                    'growth_per_hour',
                    'utilization',
                    'risk_level',
                    'minutes_to_capacity',
                    'anomaly' => ['detected', 'direction', 'change', 'z_score'],
                    'confidence',
                    'reason',
                    'samples',
                ],
            ],
        ]);

        $insight = collect($response->json('rooms'))->firstWhere('room_id', $room->id);

        $this->assertNotNull($insight);
        $this->assertSame($room->people_count, $insight['people_count']);
        $this->assertGreaterThan($room->people_count, $insight['forecast_people_count']);
        $this->assertSame('high', $insight['confidence']);
    }

    public function test_updating_occupancy_records_a_snapshot(): void
    {
        Sanctum::actingAs($this->teacher());
        $room = $this->room();

        $this->putJson("/api/rooms/{$room->id}", [
            'name' => $room->name,
            'floor' => $room->floor,
            'building' => $room->building,
            'capacity' => $room->capacity,
            'people_count' => 31,
            'is_maintenance' => false,
        ])->assertOk();

        $this->assertDatabaseHas('room_snapshots', [
            'room_id' => $room->id,
            'people_count' => 31,
        ]);
    }

    public function test_room_insight_endpoint_is_read_only(): void
    {
        Sanctum::actingAs($this->teacher());
        $room = $this->room();

        $this->getJson("/api/insights/rooms/{$room->id}")
            ->assertOk()
            ->assertJsonPath('room_id', $room->id);
    }
}
