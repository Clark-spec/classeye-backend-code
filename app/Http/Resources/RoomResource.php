<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'floor' => $this->floor,
            'building' => $this->building,
            'capacity' => $this->capacity,
            'people_count' => $this->people_count,
            'is_maintenance' => $this->is_maintenance,
            'status' => $this->status,
            'is_over_capacity' => $this->isOverCapacity(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'logs' => $this->whenLoaded('logs', fn () => ActivityLogResource::collection($this->logs)),
        ];
    }
}
