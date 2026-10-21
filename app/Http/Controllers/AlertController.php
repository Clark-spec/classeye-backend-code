<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\JsonResponse;

class AlertController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Room::class);

        $alerts = Room::overCapacity()
            ->orderBy('name')
            ->get()
            ->map(fn (Room $room): array => [
                'id' => $room->id,
                'name' => $room->name,
                'floor' => $room->floor,
                'building' => $room->building,
                'capacity' => $room->capacity,
                'people_count' => $room->people_count,
                'message' => "{$room->name} is over capacity ({$room->people_count}/{$room->capacity})",
                'updated_at' => $room->updated_at?->toIso8601String(),
            ])
            ->values();

        return response()->json($alerts);
    }
}
