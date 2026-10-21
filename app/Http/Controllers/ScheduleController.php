<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScheduleRequest;
use App\Http\Resources\ScheduleResource;
use App\Models\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScheduleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Schedule::class);

        $schedules = Schedule::query()
            ->with(['instructor', 'room'])
            ->forUser($request->user())
            ->search((string) $request->query('search', ''))
            ->day((string) $request->query('day', ''))
            ->forInstructor((int) $request->query('instructor_id', 0))
            ->forRoom((int) $request->query('room_id', 0))
            ->orderBy('start_time')
            ->orderBy('subject_code')
            ->get();

        return ScheduleResource::collection($schedules);
    }

    public function store(ScheduleRequest $request): JsonResponse
    {
        $this->authorize('create', Schedule::class);

        $data = $request->validated();
        $data['user_id'] = $data['instructor_id'];
        unset($data['instructor_id']);

        $schedule = Schedule::create($data)->load(['instructor', 'room']);

        return (new ScheduleResource($schedule))->response()->setStatusCode(201);
    }

    public function show(Schedule $schedule): ScheduleResource
    {
        $this->authorize('view', $schedule);

        return new ScheduleResource($schedule->load(['instructor', 'room']));
    }

    public function update(ScheduleRequest $request, Schedule $schedule): ScheduleResource
    {
        $this->authorize('update', $schedule);

        $data = $request->validated();
        $data['user_id'] = $data['instructor_id'];
        unset($data['instructor_id']);

        $schedule->update($data);

        return new ScheduleResource($schedule->fresh(['instructor', 'room']));
    }

    public function destroy(Schedule $schedule): JsonResponse
    {
        $this->authorize('delete', $schedule);

        $schedule->delete();

        return response()->json(['message' => 'Schedule deleted.']);
    }
}
