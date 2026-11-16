<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Services\Insights\OccupancyInsightsService;
use Illuminate\Http\JsonResponse;

/**
 * Read-only advisory endpoints. Insights never modify room data; every action
 * still goes through the normal RoomController policies.
 */
class InsightsController extends Controller
{
    public function __construct(private readonly OccupancyInsightsService $insights) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Room::class);

        return response()->json($this->insights->summary());
    }

    public function show(Room $room): JsonResponse
    {
        $this->authorize('view', $room);

        return response()->json($this->insights->forRoom($room));
    }
}
