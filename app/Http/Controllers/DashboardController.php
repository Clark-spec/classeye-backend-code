<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoomResource;
use App\Models\Room;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $stats = [
            'total' => Room::count(),
            'occupied' => Room::status('occupied')->count(),
            'empty' => Room::status('empty')->count(),
            'maintenance' => Room::status('maintenance')->count(),
            'alerts' => Room::overCapacity()->count(),
        ];

        $recentRooms = Room::query()->orderBy('name')->limit(5)->get();

        return response()->json([
            'stats' => $stats,
            'recent_rooms' => RoomResource::collection($recentRooms),
        ]);
    }
}
