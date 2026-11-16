<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssistantRequest;
use App\Models\Room;
use App\Services\Ai\AssistantService;
use Illuminate\Http\JsonResponse;

/**
 * Conversational layer over the deterministic insights. It is read-only: the
 * assistant can describe and forecast occupancy, but it can never change a
 * room, log an event, or trigger an alert.
 */
class AssistantController extends Controller
{
    public function __construct(private readonly AssistantService $assistant) {}

    public function __invoke(AssistantRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Room::class);

        $validated = $request->validated();

        return response()->json(
            $this->assistant->answer(
                (string) $validated['question'],
                is_array($validated['history'] ?? null) ? $validated['history'] : []
            )
        );
    }
}
