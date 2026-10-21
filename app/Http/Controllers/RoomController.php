<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoomRequest;
use App\Http\Resources\ActivityLogResource;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoomController extends Controller
{
    /**
     * List rooms with optional search, status and floor filters. Each scope is
     * a small, reusable query object rather than a chain of if-statements.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Room::class);

        $rooms = Room::query()
            ->search((string) $request->query('search', ''))
            ->status((string) $request->query('status', ''))
            ->floor((int) $request->query('floor', 0))
            ->orderBy('name')
            ->get();

        return RoomResource::collection($rooms);
    }

    public function store(RoomRequest $request): JsonResponse
    {
        $this->authorize('create', Room::class);

        $data = $request->validated();
        $data['building'] = $data['building'] ?? 'Main Building';
        $data['is_maintenance'] = $data['is_maintenance'] ?? false;

        $room = Room::create($data);
        $this->recordLog($room, $request->user(), 'Room created');

        return (new RoomResource($room))->response()->setStatusCode(201);
    }

    public function show(Room $room): RoomResource
    {
        $this->authorize('view', $room);

        $room->load(['logs' => function (HasMany $query): void {
            $query->with('user')->limit(10);
        }]);

        return new RoomResource($room);
    }

    public function update(RoomRequest $request, Room $room): RoomResource
    {
        $this->authorize('update', $room);

        $data = $request->validated();
        $data['building'] = $data['building'] ?? $room->building;

        $oldPeopleCount = $room->people_count;
        $wasUnderMaintenance = $room->is_maintenance;

        $room->update($data);

        if ($wasUnderMaintenance !== $room->is_maintenance) {
            $this->recordLog(
                $room,
                $request->user(),
                $room->is_maintenance ? 'Marked under maintenance' : 'Removed from maintenance'
            );
        }

        if ($oldPeopleCount !== $room->people_count) {
            $this->recordLog(
                $room,
                $request->user(),
                "People count changed from {$oldPeopleCount} to {$room->people_count}"
            );
        }

        if ($wasUnderMaintenance === $room->is_maintenance && $oldPeopleCount === $room->people_count) {
            $this->recordLog($room, $request->user(), 'Room details updated');
        }

        return new RoomResource($room->fresh());
    }

    public function destroy(Room $room): JsonResponse
    {
        $this->authorize('delete', $room);

        $room->delete();

        return response()->json(['message' => 'Room deleted.']);
    }

    /**
     * Full activity history for one room. Route model binding resolves {room}.
     */
    public function logs(Room $room): AnonymousResourceCollection
    {
        $this->authorize('view', $room);

        $logs = $room->logs()->with('user')->get();

        return ActivityLogResource::collection($logs);
    }

    /**
     * Small private service method: writes one immutable activity entry.
     */
    private function recordLog(Room $room, ?User $user, string $message): void
    {
        $room->logs()->create([
            'user_id' => $user?->id,
            'message' => $message,
        ]);
    }
}
